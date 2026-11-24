<?php

namespace Modules\HRM\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\HRM\Models\Roster\RosterOvertime;
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

        $query = RosterOvertime::where('employee_id', $employee->employee_id)
            ->with('approver:id,name')
            ->orderByDesc('overtime_date')
            ->orderByDesc('id');

        if (! empty($data['overtime_type'])) {
            $query->where('overtime_type', $data['overtime_type']);
        }

        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        if (! empty($data['from'])) {
            $query->whereDate('overtime_date', '>=', $data['from']);
        }

        if (! empty($data['to'])) {
            $query->whereDate('overtime_date', '<=', $data['to']);
        }

        return ApiResponse::paginated($query->paginate($data['per_page'] ?? 20), 'My overtime records.');
    }

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

        $duplicate = RosterOvertime::where('employee_id', $employee->employee_id)
            ->whereDate('overtime_date', $data['overtime_date'])
            ->where('overtime_type', $data['overtime_type'])
            ->whereIn('status', ['Pending', 'Approved'])
            ->exists();

        if ($duplicate) {
            return ApiResponse::error('A pending/approved record already exists for this date and type.', 422);
        }

        $ot = RosterOvertime::create($data + [
            'employee_id' => $employee->employee_id,
            'status' => 'Pending',
        ]);

        $ot->load('approver:id,name');

        // Phase-2 engine: ping the first stage's desk (no-op without a chain).
        app(\Modules\HRM\Services\ApprovalEngine::class)->notifyNewOvertime($ot);

        return ApiResponse::ok($ot, ucfirst(strtolower($data['overtime_type'])).' claim submitted.', [], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $employee = $this->apiEmployee($request);

        if ($employee instanceof JsonResponse) {
            return $employee;
        }

        $ot = RosterOvertime::where('employee_id', $employee->employee_id)
            ->with('approver:id,name')
            ->find($id);

        if ($ot === null) {
            return ApiResponse::error('Overtime record not found.', 404);
        }

        return ApiResponse::ok($ot, 'Overtime record.');
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $employee = $this->apiEmployee($request);

        if ($employee instanceof JsonResponse) {
            return $employee;
        }

        $ot = RosterOvertime::where('employee_id', $employee->employee_id)->find($id);

        if ($ot === null) {
            return ApiResponse::error('Overtime record not found.', 404);
        }

        if ($ot->status !== 'Pending') {
            return ApiResponse::error("Only a Pending record can be cancelled (this one is {$ot->status}).", 422);
        }

        $ot->update(['status' => 'Cancelled']);

        return ApiResponse::ok($ot->fresh(), 'Overtime record cancelled.');
    }
}
