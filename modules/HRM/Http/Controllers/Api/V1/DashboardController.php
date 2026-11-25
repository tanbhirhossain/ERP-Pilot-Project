<?php

namespace Modules\HRM\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Modules\CORE\Support\Api\ApiEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\HRM\Interfaces\Attendance\AttendanceDailyRepositoryInterface;
use Modules\HRM\Interfaces\Attendance\AttendanceRegularizationRepositoryInterface;
use Modules\HRM\Interfaces\Certificate\CertificateRequestRepositoryInterface;
use Modules\HRM\Interfaces\Employee\EmployeeResignationRepositoryInterface;
use Modules\HRM\Interfaces\Informed\InformedRequestRepositoryInterface;
use Modules\HRM\Interfaces\Leave\LeaveRequestRepositoryInterface;
use Modules\HRM\Interfaces\Roster\RosterOvertimeRepositoryInterface;
use Modules\HRM\Support\Api\ApiResponse;
use Modules\HRM\Support\Api\ResolvesApiEmployee;
use Modules\PAYROLL\Interfaces\Repositories\PayrollLoanRepositoryInterface;
use Modules\PAYROLL\Interfaces\Repositories\PayrollSlipRepositoryInterface;

/**
 * Employee home screen, JSON mirror of MyHrController::dashboard plus the
 * pending-count badges every self-service queue needs.
 *
 * GET /api/v1/dashboard
 */
class DashboardController extends Controller
{
    use ResolvesApiEmployee;

    public function __construct(
        private readonly PayrollSlipRepositoryInterface $slips,
        private readonly LeaveRequestRepositoryInterface $leaveRows,
        private readonly AttendanceDailyRepositoryInterface $dailies,
        private readonly RosterOvertimeRepositoryInterface $overtimeRows,
        private readonly AttendanceRegularizationRepositoryInterface $regularizationRows,
        private readonly InformedRequestRepositoryInterface $informedRows,
        private readonly EmployeeResignationRepositoryInterface $resignationRows,
        private readonly PayrollLoanRepositoryInterface $loanRows,
        private readonly CertificateRequestRepositoryInterface $certificateRows,
    ) {}

    #[ApiEndpoint(
        summary: 'Employee home screen',
        description: 'Employee card, last 6 payslips, leave taken/pending, this-month attendance, and the pending-count badges for every self-service queue.',
        response: 'MobileEnvelope',
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $employee = $this->apiEmployee($request, ['department', 'designation']);

        if ($employee instanceof JsonResponse) {
            return $employee;
        }

        $code = $employee->employee_id;

        $slips = $this->slips->latestForEmployee($code, 6);

        $year = now()->year;
        $leaveTaken = $this->leaveRows->approvedDaysForYear($code, $year);
        $leavePending = $this->leaveRows->pendingCountForEmployee($code);

        $monthStart = now()->startOfMonth()->toDateString();
        $monthEnd = now()->endOfMonth()->toDateString();
        $attendance = $this->dailies->listForEmployeeRange($code, $monthStart, $monthEnd);

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
                'overtimes' => $this->overtimeRows->pendingCountForEmployee($code),
                'regularizations' => $this->regularizationRows->pendingCountForEmployee($code),
                'informed' => $this->informedRows->pendingCountForEmployee($code),
                'resignations' => $this->resignationRows->pendingCountForEmployee($code),
                'loans' => $this->loanRows->pendingCountForEmployee($code),
                'certificates' => $this->certificateRows->pendingCountForEmployee($code),
            ],
        ]);
    }
}
