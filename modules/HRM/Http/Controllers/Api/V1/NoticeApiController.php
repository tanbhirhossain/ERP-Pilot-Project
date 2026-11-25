<?php

namespace Modules\HRM\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Modules\CORE\Support\Api\ApiEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Modules\CORE\Interfaces\UserRepositoryInterface;
use Modules\HRM\Database\Seeders\TeamWorkNoticeSeeder;
use Modules\HRM\Interfaces\DepartmentRepositoryInterface;
use Modules\HRM\Interfaces\Employee\EmployeeRepositoryInterface;
use Modules\HRM\Interfaces\Notice\NoticeRepositoryInterface;
use Modules\HRM\Notifications\DeskNotification;
use Modules\HRM\Support\Api\ApiResponse;
use Modules\HRM\Support\Api\ResolvesTeamScope;

/**
 * Notices (Phase 3).
 *
 * POST  /api/v1/notices              post (perm + audience inside scope)
 * GET   /api/v1/notices/mine         visible to me (live only, newest first)
 * GET   /api/v1/notices/posted       posted by me
 * PATCH /api/v1/notices/{id}         edit (poster or all-scope)
 * POST  /api/v1/notices/{id}/retract retract (poster or all-scope)
 *
 * Audiences: all | department | employees. A supervisor may only target their
 * own reports (employees ⊆ direct reports); HR/DGM/GM/CEO/Admins may target
 * anything. Visibility is filtered in PHP (not JSON SQL) so it behaves
 * identically on every database driver.
 */
class NoticeApiController extends Controller
{
    use ResolvesTeamScope;

    public function __construct(
        private readonly NoticeRepositoryInterface $rows,
        private readonly EmployeeRepositoryInterface $employees,
        private readonly DepartmentRepositoryInterface $departments,
        private readonly UserRepositoryInterface $users,
    ) {}

    #[ApiEndpoint(
        summary: 'Post a notice',
        description: 'Needs hrm.notices.manage. Audiences: all, department (ids are department ids), employees (ids are employee codes). A supervisor may only address their own reports; HR/DGM/GM/CEO may target anything.',
        request: 'MobileNoticePost',
        response: 'MobileEnvelope',
    )]
    public function store(Request $request): JsonResponse
    {
        if (! $request->user()->can(TeamWorkNoticeSeeder::PERM_NOTICES)) {
            return ApiResponse::error('You may not post notices.', 403);
        }

        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:10000'],
            'audience_type' => ['required', Rule::in($this->rows->audiences())],
            'audience_ids' => ['nullable', 'array', 'max:1000'],
            'audience_ids.*' => ['required'],
            'expire_at' => ['nullable', 'date', 'after:now'],
        ]);

        if ($data['audience_type'] !== 'all' && empty($data['audience_ids'])) {
            return ApiResponse::error('audience_ids is required unless the audience is all.', 422);
        }

        $ids = $data['audience_type'] === 'all' ? null : array_values($data['audience_ids']);

        // Shape-check the ids against their own table.
        if ($data['audience_type'] === 'department' && $ids !== null) {
            $known = $this->departments->knownIds($ids);

            foreach ($ids as $id) {
                if (! in_array((int) $id, $known, true)) {
                    return ApiResponse::error("Unknown department: {$id}.", 422);
                }
            }

            $ids = array_map('intval', $ids);
        }

        if ($data['audience_type'] === 'employees' && $ids !== null) {
            $known = $this->employees->knownCodes($ids);

            foreach ($ids as $code) {
                if (! in_array($code, $known, true)) {
                    return ApiResponse::error("Unknown employee: {$code}.", 422);
                }
            }
        }

        // Scope: supervisors only ever address their own reports.
        if (! $this->hasAllScope($request->user())) {
            if ($data['audience_type'] !== 'employees') {
                return ApiResponse::error('You may only address your own reports.', 403);
            }

            foreach ($ids ?? [] as $code) {
                if (! $this->inScope($request->user(), (string) $code)) {
                    return ApiResponse::error("{$code} is outside your team.", 403);
                }
            }
        }

        $notice = $this->rows->create([
            'title' => $data['title'],
            'body' => $data['body'],
            'posted_by' => $request->user()->id,
            'audience_type' => $data['audience_type'],
            'audience_ids' => $ids,
            'is_active' => true,
            'expire_at' => $data['expire_at'] ?? null,
        ]);

        $this->pingAudience($notice);

        $notice->load('poster:id,name');

        return ApiResponse::ok($notice, 'Notice posted.', [], 201);
    }

    #[ApiEndpoint(
        summary: 'Notices visible to me',
        description: 'Live only (active and unexpired), newest first — exactly what the app board shows.',
        query: [
            ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1]],
            ['name' => 'per_page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]],
        ],
        response: 'MobilePage',
    )]
    public function mine(Request $request): JsonResponse
    {
        $data = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $employee = $this->employees->findByUserId($request->user()->id);
        $myCode = $employee?->employee_id;
        $myDept = $employee?->department_id !== null ? (int) $employee->department_id : null;

        $visible = $this->rows->liveForBoard()
            ->filter(function ($notice) use ($myCode, $myDept): bool {
                if ($notice->audience_type === 'all') {
                    return true;
                }

                $ids = is_array($notice->audience_ids) ? $notice->audience_ids : [];

                if ($notice->audience_type === 'department') {
                    return $myDept !== null && in_array($myDept, array_map('intval', $ids), true);
                }

                return $myCode !== null && in_array($myCode, $ids, true);
            })->values()->all();

        $perPage = $data['per_page'] ?? 20;
        $page = $data['page'] ?? 1;

        $paginator = new LengthAwarePaginator(
            array_slice($visible, ($page - 1) * $perPage, $perPage),
            count($visible),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return ApiResponse::paginated($paginator, 'My notices.');
    }

    #[ApiEndpoint(
        summary: 'Notices I posted',
        description: 'Including retracted and expired ones, so the poster can audit their own board.',
        query: [
            ['name' => 'per_page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]],
        ],
        response: 'MobilePage',
    )]
    public function posted(Request $request): JsonResponse
    {
        $data = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = $this->rows->paginatePostedBy($request->user()->id, $data['per_page'] ?? 20);

        return ApiResponse::paginated($page, 'Notices I posted.');
    }

    #[ApiEndpoint(
        summary: 'Edit a notice',
        description: 'Poster or all-scope roles only. Title, body, expiry and the active flag are editable; the audience is fixed at post time.',
        request: 'MobileNoticeUpdate',
        response: 'MobileEnvelope',
        status: '200',
        path: [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]],
    )]
    public function update(Request $request, int $id): JsonResponse
    {
        $notice = $this->rows->find($id);

        if ($notice === null) {
            return ApiResponse::error('Notice not found.', 404);
        }

        if (! $this->canManage($request->user(), $notice)) {
            return ApiResponse::error('Only the poster (or HR/DGM/GM/CEO) edits this notice.', 403);
        }

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:200'],
            'body' => ['nullable', 'string', 'max:10000'],
            'expire_at' => ['nullable', 'date'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $this->rows->update($notice, array_filter($data, fn ($v): bool => $v !== null));

        return ApiResponse::ok($notice->fresh(), 'Notice updated.');
    }

    #[ApiEndpoint(
        summary: 'Retract a notice',
        description: 'Poster or all-scope roles only. The row stays for audit; it simply stops being visible.',
        response: 'MobileEnvelope',
        status: '200',
        path: [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]],
    )]
    public function retract(Request $request, int $id): JsonResponse
    {
        $notice = $this->rows->find($id);

        if ($notice === null) {
            return ApiResponse::error('Notice not found.', 404);
        }

        if (! $this->canManage($request->user(), $notice)) {
            return ApiResponse::error('Only the poster (or HR/DGM/GM/CEO) retracts this notice.', 403);
        }

        $this->rows->update($notice, ['is_active' => false]);

        return ApiResponse::ok($notice->fresh(), 'Notice retracted.');
    }

    private function canManage($user, $notice): bool
    {
        return (int) $notice->posted_by === $user->id || $this->hasAllScope($user);
    }

    /**
     * Ping the audience's bells. Capped: a company-wide post is read from the
     * list, not fanned out into thousands of rows.
     */
    private function pingAudience($notice): void
    {
        try {
            $userIds = match ($notice->audience_type) {
                'all' => $this->users->allIds(),
                'department' => $this->employees->userIdsForDepartments($notice->audience_ids ?? []),
                default => $this->employees->userIdsForCodes($notice->audience_ids ?? []),
            };

            $userIds = array_values(array_unique(array_map('intval', $userIds)));

            if ($userIds === [] || count($userIds) > 200) {
                return;
            }

            $users = $this->users->findMany($userIds);

            if ($users->isNotEmpty()) {
                Notification::send(
                    $users,
                    new DeskNotification($notice->title, mb_substr($notice->body, 0, 140), 'notice', $notice->id)
                );
            }
        } catch (\Throwable $e) {
            Log::warning('Notice notification skipped: '.$e->getMessage());
        }
    }
}
