<?php

namespace Modules\HRM\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\HRM\Models\Attendance\AttendanceDaily;
use Modules\HRM\Models\AttendanceRegularization;
use Modules\HRM\Models\Certificate\CertificateRequest;
use Modules\HRM\Models\Employee\EmployeeResignation;
use Modules\HRM\Models\InformedRequest;
use Modules\HRM\Models\LeaveRequest;
use Modules\HRM\Models\Roster\RosterOvertime;
use Modules\HRM\Support\Api\ApiResponse;
use Modules\HRM\Support\Api\ResolvesApiEmployee;
use Modules\PAYROLL\Models\PayrollLoan;
use Modules\PAYROLL\Models\PayrollSlip;

/**
 * Employee home screen, JSON mirror of MyHrController::dashboard plus the
 * pending-count badges every self-service queue needs.
 *
 * GET /api/v1/dashboard
 */
class DashboardController extends Controller
{
    use ResolvesApiEmployee;

    public function __invoke(Request $request): JsonResponse
    {
        $employee = $this->apiEmployee($request);

        if ($employee instanceof JsonResponse) {
            return $employee;
        }

        $employee->load(['department', 'designation']);
        $code = $employee->employee_id;

        $slips = PayrollSlip::where('employee_id', $code)
            ->with('salarySheet:id,pay_year,pay_month,status')
            ->orderByDesc('id')
            ->limit(6)
            ->get(['id', 'employee_id', 'salary_sheet_id', 'pay_year', 'pay_month', 'basic_salary', 'gross_salary', 'total_deductions', 'net_salary', 'payment_status', 'paid_at']);

        $year = now()->year;
        $leaveTaken = (float) LeaveRequest::where('employee_id', $code)
            ->where('status', 'Approved')
            ->whereYear('from_date', $year)
            ->sum('days');
        $leavePending = LeaveRequest::where('employee_id', $code)->where('status', 'Pending')->count();

        $monthStart = now()->startOfMonth()->toDateString();
        $monthEnd = now()->endOfMonth()->toDateString();
        $attendance = AttendanceDaily::where('employee_id', $code)
            ->whereBetween('duty_date', [$monthStart, $monthEnd])
            ->get();

        return ApiResponse::ok([
            'employee' => [
                'employee_id' => $code,
                'full_name' => $employee->full_name ?? trim(($employee->first_name ?? '').' '.($employee->last_name ?? '')),
                'department' => $employee->department?->name,
                'designation' => $employee->designation?->name,
                'joining_date' => $employee->joining_date?->toDateString(),
                'photo' => $employee->photo,
            ],
            'slips' => $slips,
            'leave' => ['taken' => $leaveTaken, 'pending' => $leavePending],
            'attendance' => [
                'present' => $attendance->whereIn('status', ['Present', 'Late'])->count(),
                'late' => $attendance->where('status', 'Late')->count(),
                'absent' => $attendance->where('status', 'Absent')->count(),
                'days' => $attendance->count(),
            ],
            'pending' => [
                'leaves' => $leavePending,
                'overtimes' => RosterOvertime::where('employee_id', $code)->where('status', 'Pending')->count(),
                'regularizations' => AttendanceRegularization::where('employee_id', $code)->where('status', 'Pending')->count(),
                'informed' => InformedRequest::where('employee_id', $code)->where('status', 'Pending')->count(),
                'resignations' => EmployeeResignation::where('employee_id', $code)->where('status', 'Pending')->count(),
                'loans' => PayrollLoan::where('employee_id', $code)->where('status', 'pending')->count(),
                'certificates' => CertificateRequest::where('employee_id', $code)->where('status', 'Pending')->count(),
            ],
        ]);
    }
}
