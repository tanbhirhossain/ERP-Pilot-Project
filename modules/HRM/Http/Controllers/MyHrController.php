<?php

namespace Modules\HRM\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\HRM\Models\Attendance\AttendanceDaily;
use Modules\HRM\Models\Employee\Employee;
use Modules\HRM\Models\Leave\LeaveType;
use Modules\HRM\Models\LeaveRequest;
use Modules\PAYROLL\Models\PayrollSlip;

/**
 * Employee Self-Service (Phase 1 — F9).
 *
 * Every action resolves the signed-in user's employee record from
 * `employees.user_id` and scopes EVERY query to that employee's code. There is
 * no route parameter that could be tampered with to read a colleague's payslip:
 * the employee id comes from the session, never the request.
 *
 * A user with no linked employee sees a friendly "ask HR to link your account"
 * page rather than a 403, so the failure is actionable.
 */
class MyHrController extends Controller
{
    private function employee(Request $request): ?Employee
    {
        $user = $request->user();

        if ($user === null) {
            return null;
        }

        return Employee::where('user_id', $user->id)->first();
    }

    public function dashboard(Request $request): Response
    {
        $employee = $this->employee($request);

        if ($employee === null) {
            return Inertia::render('HRM::My/NotLinked', []);
        }

        $employee->load(['department', 'designation']);

        // Recent payslips (last 6).
        $slips = PayrollSlip::where('employee_id', $employee->employee_id)
            ->with('salarySheet:id,pay_year,pay_month,status')
            ->orderByDesc('id')
            ->limit(6)
            ->get(['id', 'employee_id', 'salary_sheet_id', 'basic_salary', 'gross_salary', 'total_deductions', 'net_salary', 'payment_status', 'paid_at']);

        // Leave counts this year.
        $year = now()->year;
        $leaveTaken = (float) LeaveRequest::where('employee_id', $employee->employee_id)
            ->where('status', 'Approved')
            ->whereYear('from_date', $year)
            ->sum('days');
        $leavePending = LeaveRequest::where('employee_id', $employee->employee_id)
            ->where('status', 'Pending')
            ->count();

        // Attendance this month.
        $monthStart = now()->startOfMonth()->toDateString();
        $monthEnd = now()->endOfMonth()->toDateString();
        $attendance = AttendanceDaily::where('employee_id', $employee->employee_id)
            ->whereBetween('duty_date', [$monthStart, $monthEnd])
            ->get();
        $present = $attendance->whereIn('status', ['Present', 'Late'])->count();
        $late = $attendance->where('status', 'Late')->count();
        $absent = $attendance->where('status', 'Absent')->count();

        return Inertia::render('HRM::My/Dashboard', [
            'employee' => [
                'employee_id' => $employee->employee_id,
                'full_name' => $employee->full_name ?? trim($employee->first_name.' '.$employee->last_name),
                'department' => $employee->department?->name,
                'designation' => $employee->designation?->name,
                'joining_date' => $employee->joining_date?->toDateString(),
                'photo' => $employee->photo,
            ],
            'slips' => $slips,
            'leave' => ['taken' => $leaveTaken, 'pending' => $leavePending],
            'attendance' => ['present' => $present, 'late' => $late, 'absent' => $absent, 'days' => $attendance->count()],
        ]);
    }

    public function payslips(Request $request): Response
    {
        $employee = $this->employee($request);

        if ($employee === null) {
            return Inertia::render('HRM::My/NotLinked', []);
        }

        $slips = PayrollSlip::where('employee_id', $employee->employee_id)
            ->with('salarySheet:id,pay_year,pay_month,status')
            ->orderByDesc('id')
            ->paginate(12)
            ->withQueryString();

        return Inertia::render('HRM::My/Payslips', ['slips' => $slips]);
    }

    public function payslip(Request $request, int $id): Response
    {
        $employee = $this->employee($request);

        if ($employee === null) {
            return Inertia::render('HRM::My/NotLinked', []);
        }

        // Scoped to this employee — a tampered id returns 404, never a colleague's slip.
        $slip = PayrollSlip::where('employee_id', $employee->employee_id)
            ->with('salarySheet:id,pay_year,pay_month,status')
            ->findOrFail($id);

        return Inertia::render('HRM::My/Payslip', ['slip' => $slip]);
    }

    public function leave(Request $request): Response
    {
        $employee = $this->employee($request);

        if ($employee === null) {
            return Inertia::render('HRM::My/NotLinked', []);
        }

        $requests = LeaveRequest::where('employee_id', $employee->employee_id)
            ->with('leaveType:id,name')
            ->orderByDesc('id')
            ->paginate(12)
            ->withQueryString();

        $types = LeaveType::where('is_active', true)->get(['id', 'name']);

        return Inertia::render('HRM::My/Leave', ['requests' => $requests, 'types' => $types]);
    }

    public function attendance(Request $request): Response
    {
        $employee = $this->employee($request);

        if ($employee === null) {
            return Inertia::render('HRM::My/NotLinked', []);
        }

        $month = $request->query('month', now()->format('Y-m'));
        $start = $month.'-01';
        $end = date('Y-m-t', strtotime($start));

        $rows = AttendanceDaily::where('employee_id', $employee->employee_id)
            ->whereBetween('duty_date', [$start, $end])
            ->orderBy('duty_date')
            ->get(['id', 'duty_date', 'status', 'primary_punch_in', 'primary_punch_out', 'primary_worked_hours', 'total_overtime_hours']);

        return Inertia::render('HRM::My/Attendance', ['rows' => $rows, 'month' => $month]);
    }
}
