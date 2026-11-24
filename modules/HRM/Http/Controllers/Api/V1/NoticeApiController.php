<?php

namespace Modules\HRM\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Modules\CORE\Models\User;
use Modules\HRM\Database\Seeders\TeamWorkNoticeSeeder;
use Modules\HRM\Models\Employee\Employee;
use Modules\HRM\Models\Notice\Notice;
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

    public function store(Request $request): JsonResponse
    {
        if (! $request->user()->can(TeamWorkNoticeSeeder::PERM_NOTICES)) {
            return ApiResponse::error('You may not post notices.', 403);
        }

        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:10000'],
            'audience_type' => ['required', Rule::in(Notice::AUDIENCES)],
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
            $known = \Modules\HRM\Models\Department::whereIn('id', $ids)->pluck('id')->map(fn ($v): int => (int) $v)->all();

            foreach ($ids as $id) {
                if (! in_array((int) $id, $known, true)) {
                    return ApiResponse::error("Unknown department: {$id}.", 422);
                }
            }

            $ids = array_map('intval', $ids);
        }

        if ($data['audience_type'] === 'employees' && $ids !== null) {
            $known = Employee::whereIn('employee_id', $ids)->pluck('employee_id')->all();

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

        $notice = Notice::create([
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

    public function mine(Request $request): JsonResponse
    {
        $data = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $employee = Employee::where('user_id', $request->user()->id)->first();
        $myCode = $employee?->employee_id;
        $myDept = $employee?->department_id !== null ? (int) $employee->department_id : null;

        $visible = Notice::live()->with('poster:id,name')->latest('id')->limit(500)->get()
            ->filter(function (Notice $notice) use ($myCode, $myDept): bool {
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

    public function posted(Request $request): JsonResponse
    {
        $data = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = Notice::where('posted_by', $request->user()->id)
            ->with('poster:id,name')
            ->latest('id')
            ->paginate($data['per_page'] ?? 20);

        return ApiResponse::paginated($page, 'Notices I posted.');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $notice = Notice::find($id);

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

        $notice->update(array_filter($data, fn ($v): bool => $v !== null));

        return ApiResponse::ok($notice->fresh(), 'Notice updated.');
    }

    public function retract(Request $request, int $id): JsonResponse
    {
        $notice = Notice::find($id);

        if ($notice === null) {
            return ApiResponse::error('Notice not found.', 404);
        }

        if (! $this->canManage($request->user(), $notice)) {
            return ApiResponse::error('Only the poster (or HR/DGM/GM/CEO) retracts this notice.', 403);
        }

        $notice->update(['is_active' => false]);

        return ApiResponse::ok($notice->fresh(), 'Notice retracted.');
    }

    private function canManage(User $user, Notice $notice): bool
    {
        return (int) $notice->posted_by === $user->id || $this->hasAllScope($user);
    }

    /**
     * Ping the audience's bells. Capped: a company-wide post is read from the
     * list, not fanned out into thousands of rows.
     */
    private function pingAudience(Notice $notice): void
    {
        try {
            $userIds = match ($notice->audience_type) {
                'all' => User::pluck('id')->all(),
                'department' => Employee::whereIn('department_id', $notice->audience_ids ?? [])
                    ->whereNotNull('user_id')->pluck('user_id')->all(),
                default => Employee::whereIn('employee_id', $notice->audience_ids ?? [])
                    ->whereNotNull('user_id')->pluck('user_id')->all(),
            };

            $userIds = array_values(array_unique(array_map('intval', $userIds)));

            if ($userIds === [] || count($userIds) > 200) {
                return;
            }

            $users = User::whereIn('id', $userIds)->get();

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
