<?php

namespace Modules\HRM\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\CORE\Support\DataTableQuery;
use Modules\CORE\Support\PaginatedResource;
use Modules\HRM\Interfaces\Attendance\AttendanceDailyRepositoryInterface;
use Modules\HRM\Models\Attendance\AttendanceDaily;
use Modules\HRM\Services\Attendance\AttendanceDayReconciliationService;

class AttendanceDailyController extends Controller
{
    public function __construct(private readonly AttendanceDailyRepositoryInterface $repo) {}

    public function index(Request $r): Response
    {
        $allowedSorts = ['id', 'duty_date', 'grand_total_hours', 'created_at'];
        $allowedFilters = ['employee_id', 'status', 'duty_date', 'from', 'to', 'department_id', 'designation_id'];
        $dt = DataTableQuery::fromRequest($r, $allowedSorts, $allowedFilters);
        $paginated = $this->repo->paginate($dt);
        $filterDefs = [
            ['key' => 'department_id', 'label' => 'Department', 'kind' => 'endpoint', 'endpoint' => '/hrm/api/options/departments', 'placeholder' => 'Filter by department'],
            ['key' => 'designation_id', 'label' => 'Designation', 'kind' => 'endpoint', 'endpoint' => '/hrm/api/options/designations', 'placeholder' => 'Filter by designation'],
            ['key' => 'employee_id', 'label' => 'Employee', 'kind' => 'endpoint', 'endpoint' => '/hrm/api/options/employees', 'placeholder' => 'Search employee…'],
            ['key' => 'status', 'label' => 'Status', 'kind' => 'select', 'options' => [
                ['value' => 'Present', 'label' => 'Present'], ['value' => 'Late', 'label' => 'Late'], ['value' => 'Early Out', 'label' => 'Early Out'], ['value' => 'Late & Early Out', 'label' => 'Late & Early Out'],
                ['value' => 'Absent', 'label' => 'Absent'], ['value' => 'Missing In', 'label' => 'Missing In'], ['value' => 'Missing Out', 'label' => 'Missing Out / Punch Missing'],
                ['value' => 'On Leave', 'label' => 'On Leave'], ['value' => 'Holiday', 'label' => 'Holiday'], ['value' => 'Off Day', 'label' => 'Off Day'], ['value' => 'Extra Duty', 'label' => 'Extra Duty'],
            ], 'placeholder' => 'All statuses'],
            ['key' => 'from', 'label' => 'From Date', 'kind' => 'date'],
            ['key' => 'to', 'label' => 'To Date', 'kind' => 'date'],
        ];

        return Inertia::render('HRM::Attendance/Dailies/Index', [
            'dailies' => PaginatedResource::from($paginated->through(fn (AttendanceDaily $d) => [
                'id' => $d->id, 'employee_id' => $d->employee_id, 'employee' => $d->employee?->full_name ?? trim(($d->employee?->first_name ?? '').' '.($d->employee?->last_name ?? '')) ?: null,
                'department' => $d->employee?->department?->name ?? null, 'designation' => $d->employee?->designation?->name ?? null,
                'duty_date' => $d->duty_date?->toDateString(), 'primary_shift_id' => $d->primary_shift_id, 'shift' => $d->shift?->shift_name ?? null,
                'expected_in_time' => substr((string) ($d->expected_in_time ?? ''), 0, 5) ?: $d->expected_in_time, 'expected_out_time' => substr((string) ($d->expected_out_time ?? ''), 0, 5) ?: $d->expected_out_time,
                'primary_punch_in' => $d->primary_punch_in?->toIso8601String(), 'primary_punch_out' => $d->primary_punch_out?->toIso8601String(),
                'primary_worked_hours' => $d->primary_worked_hours, 'total_overtime_hours' => $d->total_overtime_hours, 'grand_total_hours' => $d->grand_total_hours, 'status' => $d->status, 'remarks' => $d->remarks,
            ])),
            'filterDefs' => $filterDefs,
            'reconcileFrom' => now()->subDays(7)->toDateString(),
            'reconcileTo' => now()->subDay()->toDateString(),
        ]);
    }

    public function reconcile(Request $request, AttendanceDayReconciliationService $service): RedirectResponse
    {
        $data = $request->validate(['from' => ['required', 'date', 'before_or_equal:to'], 'to' => ['required', 'date', 'before_or_equal:today', 'after_or_equal:from']]);
        if (Carbon::parse($data['from'])->diffInDays(Carbon::parse($data['to'])) > 62) {
            return back()->withErrors(['to' => 'Select a date range no longer than 63 days.']);
        }
        $result = $service->reconcile($data['from'], $data['to']);

        return back()->with('success', "Classified {$result['created']} missing attendance day(s): {$result['absent']} absent, {$result['holiday']} holiday, {$result['leave']} leave; {$result['off_day']} roster off-days classified and {$result['night_pending']} night shifts held pending.");
    }

    public function destroy(AttendanceDaily $daily): RedirectResponse
    {
        $this->repo->delete($daily);

        return back()->with('success', 'Daily record deleted.');
    }
}
