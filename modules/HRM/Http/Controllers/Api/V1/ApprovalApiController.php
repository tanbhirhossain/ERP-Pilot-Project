<?php

namespace Modules\HRM\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Modules\CORE\Support\Api\ApiEndpoint;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Modules\HRM\Models\Leave\LeaveRequest;
use Modules\HRM\Models\Roster\RosterOvertime;
use Modules\HRM\Services\ApprovalEngine;
use Modules\HRM\Support\Api\ApiResponse;

/**
 * Approval desks for the mobile app (Phase 2 engine).
 *
 * GET  /api/v1/approvals/pending?type=leave|overtime  my desk, both flows
 * GET  /api/v1/approvals/history?type=&per_page=       decisions I have taken
 * POST /api/v1/approvals/leaves/{id}/approve           {comments?}
 * POST /api/v1/approvals/leaves/{id}/reject            {comments} (required)
 * POST /api/v1/approvals/overtimes/{id}/approve        {adjusted_hours?, comments?}
 * POST /api/v1/approvals/overtimes/{id}/reject         {reason} (required)
 *
 * Authorization is stage membership, not a Spatie permission: the engine
 * resolves the current stage to supervisor-mapping or role members, and
 * anything else answers 403 with the desk the item is actually waiting on.
 */
class ApprovalApiController extends Controller
{
    public function __construct(private readonly ApprovalEngine $engine) {}

    #[ApiEndpoint(
        summary: 'My approval desk',
        description: 'Leave and overtime items sitting on the caller\'s stage right now — stage-exact, both flows. Approving itself is per-item below.',
        query: [
            ['name' => 'type', 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => ['leave', 'overtime']], 'description' => 'Omit for both flows.'],
        ],
        response: 'MobileEnvelope',
    )]
    public function pending(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['nullable', 'in:leave,overtime'],
        ]);

        $queue = $this->engine->queueFor($request->user());

        if (($data['type'] ?? null) === 'leave') {
            return ApiResponse::ok(
                ['leaves' => $queue['leaves']],
                'My leave approvals.',
                ['total' => count($queue['leaves'])]
            );
        }

        if (($data['type'] ?? null) === 'overtime') {
            return ApiResponse::ok(
                ['overtimes' => $queue['overtimes']],
                'My overtime approvals.',
                ['total' => count($queue['overtimes'])]
            );
        }

        return ApiResponse::ok($queue, 'My approvals.', [
            'leaves' => count($queue['leaves']),
            'overtimes' => count($queue['overtimes']),
            'total' => count($queue['leaves']) + count($queue['overtimes']),
        ]);
    }

    #[ApiEndpoint(
        summary: 'Decisions I have taken',
        description: 'My approval trail across both flows, newest first.',
        query: [
            ['name' => 'type', 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => ['leave', 'overtime']]],
            ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1]],
            ['name' => 'per_page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]],
        ],
        response: 'MobilePage',
    )]
    public function history(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['nullable', 'in:leave,overtime'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $perPage = $data['per_page'] ?? 20;
        $page = $data['page'] ?? 1;
        $all = $this->engine->historyFor($request->user(), $data['type'] ?? null, 500);

        $paginator = new LengthAwarePaginator(
            array_slice($all, ($page - 1) * $perPage, $perPage),
            count($all),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return ApiResponse::paginated($paginator, 'My approval history.');
    }

    #[ApiEndpoint(
        summary: 'Approve a leave stage',
        description: 'Moves the application to the next stage or approves it finally. From the wrong stage answers 403 NOT_YOUR_STAGE naming the desk it waits on.',
        request: 'MobileApprove',
        response: 'MobileEnvelope',
        status: '200',
        path: [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]],
    )]
    public function approveLeave(Request $request, int $id): JsonResponse
    {
        $leave = LeaveRequest::find($id);

        if ($leave === null) {
            return ApiResponse::error('Leave application not found.', 404);
        }

        $data = $request->validate(['comments' => ['nullable', 'string', 'max:2000']]);

        try {
            $leave = $this->engine->approveLeave($request->user(), $leave, $data['comments'] ?? null);
        } catch (AuthorizationException $e) {
            return ApiResponse::error($e->getMessage(), 403, ['code' => 'NOT_YOUR_STAGE']);
        } catch (ValidationException $e) {
            return ApiResponse::error($e->getMessage(), 422, $e->errors());
        }

        $stage = $this->engine->leaveStage($leave->fresh());

        return ApiResponse::ok([
            'id' => $leave->id,
            'status' => $leave->status,
            'current_stage' => $stage['label'],
            'is_final' => $leave->status !== 'Pending',
        ], $leave->status === 'Pending' ? "Approved. Moved to {$stage['label']}." : 'Final approval complete.');
    }

    #[ApiEndpoint(
        summary: 'Reject a leave application',
        description: 'Ends the walk wherever it stands; the comments travel to the applicant as the reason. From the wrong stage answers 403 NOT_YOUR_STAGE.',
        request: 'MobileLeaveReject',
        response: 'MobileEnvelope',
        status: '200',
        path: [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]],
    )]
    public function rejectLeave(Request $request, int $id): JsonResponse
    {
        $leave = LeaveRequest::find($id);

        if ($leave === null) {
            return ApiResponse::error('Leave application not found.', 404);
        }

        $data = $request->validate(['comments' => ['required', 'string', 'min:3', 'max:2000']]);

        try {
            $leave = $this->engine->rejectLeave($request->user(), $leave, $data['comments']);
        } catch (AuthorizationException $e) {
            return ApiResponse::error($e->getMessage(), 403, ['code' => 'NOT_YOUR_STAGE']);
        } catch (ValidationException $e) {
            return ApiResponse::error($e->getMessage(), 422, $e->errors());
        }

        return ApiResponse::ok(['id' => $leave->id, 'status' => $leave->status], 'Leave application rejected.');
    }

    #[ApiEndpoint(
        summary: 'Approve an overtime stage',
        description: 'Optionally adjusts the hours before passing it on. From the wrong stage answers 403 NOT_YOUR_STAGE.',
        request: 'MobileOvertimeApprove',
        response: 'MobileEnvelope',
        status: '200',
        path: [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]],
    )]
    public function approveOvertime(Request $request, int $id): JsonResponse
    {
        $overtime = RosterOvertime::find($id);

        if ($overtime === null) {
            return ApiResponse::error('Overtime record not found.', 404);
        }

        $data = $request->validate([
            'adjusted_hours' => ['nullable', 'numeric', 'min:0', 'max:24'],
            'comments' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $overtime = $this->engine->approveOvertime(
                $request->user(),
                $overtime,
                isset($data['adjusted_hours']) ? (float) $data['adjusted_hours'] : null,
                $data['comments'] ?? null,
            );
        } catch (AuthorizationException $e) {
            return ApiResponse::error($e->getMessage(), 403, ['code' => 'NOT_YOUR_STAGE']);
        } catch (ValidationException $e) {
            return ApiResponse::error($e->getMessage(), 422, $e->errors());
        }

        $stage = $this->engine->overtimeStage($overtime);

        return ApiResponse::ok([
            'id' => $overtime->id,
            'status' => $overtime->status,
            'hours' => (float) $overtime->hours,
            'current_stage' => $stage['label'],
            'is_final' => $overtime->status !== 'Pending',
        ], $overtime->status === 'Pending' ? "Approved. Moved to {$stage['label']}." : 'Final approval complete.');
    }

    #[ApiEndpoint(
        summary: 'Reject an overtime record',
        description: 'Ends the record; the reason is required and travels to the claimant. From the wrong stage answers 403 NOT_YOUR_STAGE.',
        request: 'MobileOvertimeReject',
        response: 'MobileEnvelope',
        status: '200',
        path: [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]],
    )]
    public function rejectOvertime(Request $request, int $id): JsonResponse
    {
        $overtime = RosterOvertime::find($id);

        if ($overtime === null) {
            return ApiResponse::error('Overtime record not found.', 404);
        }

        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000']]);

        try {
            $overtime = $this->engine->rejectOvertime($request->user(), $overtime, $data['reason']);
        } catch (AuthorizationException $e) {
            return ApiResponse::error($e->getMessage(), 403, ['code' => 'NOT_YOUR_STAGE']);
        } catch (ValidationException $e) {
            return ApiResponse::error($e->getMessage(), 422, $e->errors());
        }

        return ApiResponse::ok(['id' => $overtime->id, 'status' => $overtime->status], 'Overtime record rejected.');
    }
}
