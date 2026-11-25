<?php

namespace Modules\HRM\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Modules\CORE\Support\Api\ApiEndpoint;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\HRM\Interfaces\Roster\RosterOvertimeRepositoryInterface;
use Modules\HRM\Support\Api\ApiResponse;
use Modules\HRM\Support\Api\ResolvesApiEmployee;

/**
 * Employee overtime / extra-duty self-service.
 *
 * The app AUTO-SHOWS every record synced from attendance plus the employee's
 * own claims; the employee then claims (POST) duty the sync missed. Approval
 * itself stays with the roster desk until the Phase-2 multi-stage chain lands.
 *
 * GET  /api/v1/overtimes?type=&status=&from=&to=  my OT/ED records
 * POST /api/v1/overtimes                          claim OT/ED
 * GET  /api/v1/overtimes/{id}                     one record + approver
 * POST /api/v1/overtimes/{id}/cancel              cancel a Pending claim
 */
class OvertimeApiController extends Controller
{
    use ResolvesApiEmployee;

    public const TYPES = ['Overtime', 'Extra Duty', 'Holiday Duty'];

    public function __construct(private readonly RosterOvertimeRepositoryInterface $overtimes) {}

    #[ApiEndpoint(
        summary: 'My overtime / extra duty / holiday duty',
        description: 'Auto-includes attendance-synced records; POST claims duty the sync missed.',
        query: [
            ['name' => 'overtime_type', 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => ['Overtime', 'Extra Duty', 'Holiday Duty']]],
            ['name' => 'status', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'Pending, Approved, Rejected, Cancelled.'],
            ['name' => 'from', 'in' => 'query', 'schema' => ['type' => 'string', 'format' => 'date']],
            ['name' => 'to', 'in' => 'query', 'schema' => ['type' => 'string', 'format' => 'date']],
            ['name' => 'per_page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]],
        ],
        response: 'MobilePage',
    )]
    public function index(Request $request): JsonResponse
    {
        $employee = $this->apiEmployee($request);

        if ($employee instanceof JsonResponse) {
            return $employee;
        }

        $data = $request->validate([
            'overtime_type' => ['nullable', Rule::in(self::TYPES)],
            'status' => ['nullable', 'string', 'max:20'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = $this->overtimes->paginateForEmployee(
            $employee->employee_id,
            $data['overtime_type'] ?? null,
            $data['status'] ?? null,
            $data['from'] ?? null,
            $data['to'] ?? null,
            $data['per_page'] ?? 20,
        );

        return ApiResponse::paginated($page, 'My overtime records.');
    }

    #[ApiEndpoint(
        summary: 'Claim overtime / extra / holiday duty',
        description: 'Date must be today or earlier; give either hours or start/end time (hours are computed). A pending/approved record for the same date and type answers 422.',
        request: 'MobileOvertimeClaim',
        response: 'MobileEnvelope',
    )]
    public function store(Request $request): JsonResponse
    {
        $employee = $this->apiEmployee($request);

        if ($employee instanceof JsonResponse) {
            return $employee;
        }

        $data = $request->validate([
            'overtime_date' => ['required', 'date', 'before_or_equal:today'],
            'overtime_type' => ['required', Rule::in(self::TYPES)],
            'shift_id' => ['nullable', 'integer', 'exists:roster_shifts,id'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'hours' => ['nullable', 'numeric', 'min:0.25', 'max:24'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        if (! empty($data['start_time']) && ! empty($data['end_time'])) {
            $start = Carbon::createFromFormat('H:i', $data['start_time']);
            $end = Carbon::createFromFormat('H:i', $data['end_time']);

            if ($end->lessThanOrEqualTo($start)) {
                return ApiResponse::error('End time must be after start time.', 422);
            }

            $data['hours'] = $data['hours'] ?? round($start->floatDiffInHours($end), 2);
        }

        if (empty($data['hours']) && empty($data['start_time'])) {
            return ApiResponse::error('Give either the hours or the start/end time.', 422);
        }

        if ($this->overtimes->duplicateExists($employee->employee_id, $data['overtime_date'], $data['overtime_type'])) {
            return ApiResponse::error('A pending/approved record already exists for this date and type.', 422);
        }

        $ot = $this->overtimes->create($data + [
            'employee_id' => $employee->employee_id,
            'status' => 'Pending',
        ]);

        $ot = $this->overtimes->findForEmployee($ot->id, $employee->employee_id, true) ?? $ot;

        // Phase-2 engine: ping the first stage's desk (no-op without a chain).
        app(\Modules\HRM\Services\ApprovalEngine::class)->notifyNewOvertime($ot);

        return ApiResponse::ok($ot, ucfirst(strtolower($data['overtime_type'])).' claim submitted.', [], 201);
    }

    #[ApiEndpoint(
        summary: 'Read one overtime record',
        description: 'Includes the approver once decided. Another employee\'s id answers 404.',
        response: 'MobileEnvelope',
        path: [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]],
    )]
    public function show(Request $request, int $id): JsonResponse
    {
        $employee = $this->apiEmployee($request);

        if ($employee instanceof JsonResponse) {
            return $employee;
        }

        $ot = $this->overtimes->findForEmployee($id, $employee->employee_id, true);

        if ($ot === null) {
            return ApiResponse::error('Overtime record not found.', 404);
        }

        return ApiResponse::ok($ot, 'Overtime record.');
    }

    #[ApiEndpoint(
        summary: 'Cancel a pending record',
        description: 'Pending only — anything already decided answers 422.',
        response: 'MobileEnvelope',
        status: '200',
        path: [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]],
    )]
    public function cancel(Request $request, int $id): JsonResponse
    {
        $employee = $this->apiEmployee($request);

        if ($employee instanceof JsonResponse) {
            return $employee;
        }

        $ot = $this->overtimes->findForEmployee($id, $employee->employee_id);

        if ($ot === null) {
            return ApiResponse::error('Overtime record not found.', 404);
        }

        if ($ot->status !== 'Pending') {
            return ApiResponse::error("Only a Pending record can be cancelled (this one is {$ot->status}).", 422);
        }

        $this->overtimes->update($ot, ['status' => 'Cancelled']);

        return ApiResponse::ok($this->overtimes->find($ot->id) ?? $ot, 'Overtime record cancelled.');
    }
}
