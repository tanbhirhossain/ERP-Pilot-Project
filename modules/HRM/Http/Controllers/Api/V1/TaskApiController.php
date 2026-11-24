<?php

namespace Modules\HRM\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Modules\CORE\Support\Api\ApiEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Modules\CORE\Models\User;
use Modules\HRM\Database\Seeders\TeamWorkNoticeSeeder;
use Modules\HRM\Models\Employee\Employee;
use Modules\HRM\Models\Task\WorkTask;
use Modules\HRM\Notifications\DeskNotification;
use Modules\HRM\Support\Api\ApiResponse;
use Modules\HRM\Support\Api\ResolvesApiEmployee;
use Modules\HRM\Support\Api\ResolvesTeamScope;

/**
 * Work assignment (Phase 3).
 *
 * POST  /api/v1/tasks              assign (perm + target in scope)
 * GET   /api/v1/tasks/mine         assigned to me
 * GET   /api/v1/tasks/assigned     assigned by me
 * GET   /api/v1/tasks/team         scope-filtered register
 * GET   /api/v1/tasks/{id}         one (assignee / assigner / in-scope manager)
 * PATCH /api/v1/tasks/{id}/progress   assignee moves Open↔In Progress→Done
 * POST  /api/v1/tasks/{id}/verify     assigner accepts Done → Verified
 * POST  /api/v1/tasks/{id}/close      assigner closes Done/Verified
 * POST  /api/v1/tasks/{id}/reopen     assigner reopens Done/Verified/Closed/Cancelled
 * POST  /api/v1/tasks/{id}/cancel     assigner cancels
 *
 * Assign/manage needs hrm.tasks.manage (Supervisor/HR/DGM/GM/CEO); the target
 * must additionally sit inside the assigner's team scope, so a supervisor can
 * only ever task their own reports. The assignee is pinged on assign, the
 * assigner when the work lands on Done.
 */
class TaskApiController extends Controller
{
    use ResolvesApiEmployee;
    use ResolvesTeamScope;

    #[ApiEndpoint(
        summary: 'Assign work',
        description: 'Needs hrm.tasks.manage, and the target must sit inside the assigner\'s scope — a supervisor can only task their own reports. The assignee\'s bell is pinged.',
        request: 'MobileTaskAssign',
        response: 'MobileEnvelope',
    )]
    public function store(Request $request): JsonResponse
    {
        if (! $request->user()->can(TeamWorkNoticeSeeder::PERM_TASKS)) {
            return ApiResponse::error('You may not assign work.', 403);
        }

        $data = $request->validate([
            'employee_id' => ['required', 'string', 'exists:employees,employee_id'],
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'priority' => ['nullable', Rule::in(WorkTask::PRIORITIES)],
            'due_date' => ['nullable', 'date'],
        ]);

        if (! $this->inScope($request->user(), $data['employee_id'])) {
            return ApiResponse::error('This employee is outside your team.', 403);
        }

        $task = WorkTask::create([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'employee_id' => $data['employee_id'],
            'assigned_by' => $request->user()->id,
            'priority' => $data['priority'] ?? 'Medium',
            'status' => 'Open',
            'due_date' => $data['due_date'] ?? null,
        ]);

        $this->pingAssignee($task, "New task: {$task->title}", 'Assigned by '.$request->user()->name.'.');

        $task->load(['employee:employee_id,full_name,first_name,last_name', 'assigner:id,name']);

        return ApiResponse::ok($task, "Work assigned to {$data['employee_id']}.", [], 201);
    }

    #[ApiEndpoint(
        summary: 'Tasks assigned to me',
        description: 'The assignee\'s work queue, newest first.',
        query: [
            ['name' => 'status', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'Open, In Progress, Done, Verified, Closed, Cancelled.'],
            ['name' => 'per_page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]],
        ],
        response: 'MobilePage',
    )]
    public function mine(Request $request): JsonResponse
    {
        $employee = $this->apiEmployee($request);

        if ($employee instanceof JsonResponse) {
            return $employee;
        }

        $data = $request->validate([
            'status' => ['nullable', 'string', 'max:20'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = WorkTask::where('employee_id', $employee->employee_id)
            ->with(['assigner:id,name'])
            ->orderByDesc('id');

        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        return ApiResponse::paginated($query->paginate($data['per_page'] ?? 20), 'My tasks.');
    }

    #[ApiEndpoint(
        summary: 'Work I assigned',
        description: 'Everything the caller handed out, newest first.',
        query: [
            ['name' => 'status', 'in' => 'query', 'schema' => ['type' => 'string']],
            ['name' => 'per_page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]],
        ],
        response: 'MobilePage',
    )]
    public function assigned(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'string', 'max:20'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = WorkTask::where('assigned_by', $request->user()->id)
            ->with(['employee:employee_id,full_name,first_name,last_name'])
            ->orderByDesc('id');

        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        return ApiResponse::paginated($query->paginate($data['per_page'] ?? 20), 'Work I assigned.');
    }

    #[ApiEndpoint(
        summary: 'Team task register',
        description: 'Scope-filtered: a supervisor sees their reports\' tasks, HR/DGM/GM/CEO see everything.',
        query: [
            ['name' => 'employee_id', 'in' => 'query', 'schema' => ['type' => 'string']],
            ['name' => 'status', 'in' => 'query', 'schema' => ['type' => 'string']],
            ['name' => 'per_page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]],
        ],
        response: 'MobilePage',
    )]
    public function team(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['nullable', 'string', 'exists:employees,employee_id'],
            'status' => ['nullable', 'string', 'max:20'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $codes = $this->teamCodes($request->user());

        if (! empty($data['employee_id'])) {
            if ($codes !== null && ! in_array($data['employee_id'], $codes, true)) {
                return ApiResponse::error('This employee is outside your team.', 403);
            }

            $codes = [$data['employee_id']];
        }

        $query = WorkTask::with(['employee:employee_id,full_name,first_name,last_name', 'assigner:id,name'])
            ->orderByDesc('id');

        if ($codes !== null) {
            $query->whereIn('employee_id', $codes);
        }

        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        return ApiResponse::paginated($query->paginate($data['per_page'] ?? 20), 'Team tasks.');
    }

    #[ApiEndpoint(
        summary: 'Read one task',
        description: 'Visible to the assignee, the assigner, and in-scope managers — anyone else answers 403.',
        response: 'MobileEnvelope',
        path: [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]],
    )]
    public function show(Request $request, int $id): JsonResponse
    {
        $task = WorkTask::with(['employee:employee_id,full_name,first_name,last_name', 'assigner:id,name', 'verifier:id,name'])->find($id);

        if ($task === null) {
            return ApiResponse::error('Task not found.', 404);
        }

        if (! $this->canSee($request->user(), $task)) {
            return ApiResponse::error('This task is outside your team.', 403);
        }

        return ApiResponse::ok($task, 'Task.');
    }

    #[ApiEndpoint(
        summary: 'Move my task (assignee lanes)',
        description: 'Assignee only: Open ↔ In Progress → Done. Done lands on the assigner\'s bell; anything past Done is the assigner\'s call below.',
        request: 'MobileTaskProgress',
        response: 'MobileEnvelope',
        status: '200',
        path: [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]],
    )]
    public function progress(Request $request, int $id): JsonResponse
    {
        $task = WorkTask::find($id);

        if ($task === null) {
            return ApiResponse::error('Task not found.', 404);
        }

        $employee = Employee::where('user_id', $request->user()->id)->first();

        if ($employee === null || $employee->employee_id !== $task->employee_id) {
            return ApiResponse::error('Only the assignee moves this task.', 403);
        }

        $data = $request->validate([
            'status' => ['required', Rule::in(['Open', 'In Progress', 'Done'])],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $allowed = WorkTask::ASSIGNEE_MOVES[$task->status] ?? [];

        if (! in_array($data['status'], $allowed, true)) {
            return ApiResponse::error("Cannot move this task from {$task->status} to {$data['status']}.", 422);
        }

        $task->update([
            'status' => $data['status'],
            'remarks' => $data['remarks'] ?? $task->remarks,
            'completed_at' => $data['status'] === 'Done' ? now() : null,
        ]);

        if ($data['status'] === 'Done') {
            $this->pingUser(
                $task->assigned_by,
                "Task done: {$task->title}",
                "{$task->employee_id} marked the work done.",
                $task->id,
            );
        }

        return ApiResponse::ok($task->fresh(), "Task moved to {$data['status']}.");
    }

    #[ApiEndpoint(
        summary: 'Verify a done task',
        description: 'Assigner (or HR/DGM/GM/CEO) accepts Done → Verified. Anything not Done answers 422.',
        response: 'MobileEnvelope',
        status: '200',
        path: [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]],
    )]
    public function verify(Request $request, int $id): JsonResponse
    {
        return $this->transition($request, $id, 'verify');
    }

    #[ApiEndpoint(
        summary: 'Close a task',
        description: 'Assigner (or HR/DGM/GM/CEO) closes Done/Verified → Closed.',
        response: 'MobileEnvelope',
        status: '200',
        path: [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]],
    )]
    public function close(Request $request, int $id): JsonResponse
    {
        return $this->transition($request, $id, 'close');
    }

    #[ApiEndpoint(
        summary: 'Reopen a task',
        description: 'Assigner (or HR/DGM/GM/CEO) reopens Done/Verified/Closed/Cancelled → Open, clearing the tail stamps.',
        response: 'MobileEnvelope',
        status: '200',
        path: [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]],
    )]
    public function reopen(Request $request, int $id): JsonResponse
    {
        return $this->transition($request, $id, 'reopen');
    }

    #[ApiEndpoint(
        summary: 'Cancel a task',
        description: 'Assigner (or HR/DGM/GM/CEO) cancels from any open state.',
        response: 'MobileEnvelope',
        status: '200',
        path: [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]],
    )]
    public function cancel(Request $request, int $id): JsonResponse
    {
        return $this->transition($request, $id, 'cancel');
    }

    private function transition(Request $request, int $id, string $action): JsonResponse
    {
        $task = WorkTask::find($id);

        if ($task === null) {
            return ApiResponse::error('Task not found.', 404);
        }

        $user = $request->user();

        if (! $user->can(TeamWorkNoticeSeeder::PERM_TASKS)) {
            return ApiResponse::error('You may not manage work.', 403);
        }

        if ((int) $task->assigned_by !== $user->id && ! $this->hasAllScope($user)) {
            return ApiResponse::error('Only the assigner (or HR/DGM/GM/CEO) decides this task.', 403);
        }

        $map = [
            'verify' => ['from' => ['Done'], 'to' => 'Verified'],
            'close' => ['from' => ['Done', 'Verified'], 'to' => 'Closed'],
            'reopen' => ['from' => ['Done', 'Verified', 'Closed', 'Cancelled'], 'to' => 'Open'],
            'cancel' => ['from' => ['Open', 'In Progress', 'Done', 'Verified'], 'to' => 'Cancelled'],
        ];

        if (! in_array($task->status, $map[$action]['from'], true)) {
            return ApiResponse::error("Cannot {$action} a task that is {$task->status}.", 422);
        }

        $update = ['status' => $map[$action]['to']];

        if ($action === 'verify') {
            $update['verified_by'] = $user->id;
            $update['verified_at'] = now();
        }

        if ($action === 'close') {
            $update['closed_at'] = now();
        }

        if ($action === 'reopen') {
            $update['completed_at'] = null;
            $update['verified_by'] = null;
            $update['verified_at'] = null;
            $update['closed_at'] = null;
        }

        $task->update($update);

        if (in_array($action, ['verify', 'close', 'reopen', 'cancel'], true)) {
            $this->pingAssignee($task->fresh(), "Task {$map[$action]['to']}: {$task->title}", 'Decided by '.$user->name.'.');
        }

        return ApiResponse::ok($task->fresh(), "Task {$map[$action]['to']}.");
    }

    private function canSee(User $user, WorkTask $task): bool
    {
        if ((int) $task->assigned_by === $user->id) {
            return true;
        }

        $employee = Employee::where('user_id', $user->id)->first();

        if ($employee !== null && $employee->employee_id === $task->employee_id) {
            return true;
        }

        return $this->inScope($user, $task->employee_id);
    }

    private function pingAssignee(WorkTask $task, string $title, string $body): void
    {
        try {
            $userId = Employee::where('employee_id', $task->employee_id)->value('user_id');

            if ($userId !== null) {
                $this->pingUser((int) $userId, $title, $body, $task->id);
            }
        } catch (\Throwable $e) {
            Log::warning('Task notification skipped: '.$e->getMessage());
        }
    }

    private function pingUser(int $userId, string $title, string $body, int $taskId): void
    {
        try {
            $user = User::find($userId);

            if ($user !== null) {
                Notification::send($user, new DeskNotification($title, $body, 'task', $taskId));
            }
        } catch (\Throwable $e) {
            Log::warning('Task notification skipped: '.$e->getMessage());
        }
    }
}
