<?php

namespace Modules\PAYROLL\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\CORE\Support\DataTableQuery;
use Modules\CORE\Support\PaginatedResource;
use Modules\PAYROLL\Interfaces\HRM\EmployeePayrollDataProviderInterface;
use Modules\PAYROLL\Models\PayrollPeriodExclusion;
use Modules\PAYROLL\Models\PayrollSalarySheet;
use Modules\PAYROLL\Models\PayrollSlip;
use Modules\PAYROLL\Models\PayrollWorkflowAudit;
use Modules\PAYROLL\Services\PayrollSalarySheetService;

final class PayrollSalarySheetController extends Controller
{
    public function index(Request $request, PayrollSalarySheetService $sheets): Response
    {
        return Inertia::render('PAYROLL::SalarySheets/Index', [
            'sheets' => PaginatedResource::from($sheets->paginate(['status' => $request->input('filters.status', $request->input('status')), 'search' => $request->input('search')], $request->integer('per_page', 25))),
        ]);
    }

    public function create(Request $request, EmployeePayrollDataProviderInterface $employees): Response
    {
        $requestedYear = $request->integer('pay_year', (int) now()->format('Y'));
        $requestedMonth = $request->integer('pay_month', (int) now()->format('m'));
        $year = $requestedYear >= 2000 && $requestedYear <= 2100 ? $requestedYear : (int) now()->format('Y');
        $month = $requestedMonth >= 1 && $requestedMonth <= 12 ? $requestedMonth : (int) now()->format('m');
        $existing = PayrollSalarySheet::query()->where('pay_year', $year)->where('pay_month', $month)->first();

        return Inertia::render('PAYROLL::SalarySheets/Create', [
            'defaultYear' => $year,
            'defaultMonth' => $month,
            'defaultIncludePunishments' => $existing?->include_punishments ?? false,
            'employeeOptions' => $employees->employeeOptions(null, 200),
            // Told before the run, not after: an employee with no salary history is left out of the sheet
            // (they would otherwise throw and mark the whole month `failed`), so name them here instead.
            'payrollWarnings' => $employees->unpayableForPeriod(
                sprintf('%d-%02d-01', $year, $month),
                sprintf('%d-%02d-%02d', $year, $month, cal_days_in_month(CAL_GREGORIAN, $month, $year)),
            ),
        ]);
    }

    public function store(Request $request, PayrollSalarySheetService $sheets): RedirectResponse
    {
        $data = $request->validate([
            'pay_year' => ['required', 'integer', 'between:2000,2100'],
            'pay_month' => ['required', 'integer', 'between:1,12'],
            'include_punishments' => ['sometimes', 'boolean'],
            'excluded_employee_ids' => ['sometimes', 'array', 'max:2000'],
            'excluded_employee_ids.*' => ['required', 'distinct', 'exists:employees,employee_id'],
            'exclusion_reason' => ['nullable', 'string', 'min:3', 'max:1000'],
        ]);
        $excludedEmployeeIds = array_values(array_unique(array_map('strval', $data['excluded_employee_ids'] ?? [])));
        $exclusionReason = trim((string) ($data['exclusion_reason'] ?? ''));
        if ($excludedEmployeeIds !== [] && $exclusionReason === '') {
            throw ValidationException::withMessages(['exclusion_reason' => 'Enter a documented reason for each employee-period exclusion.']);
        }

        $sheet = $sheets->dispatch(
            (int) $data['pay_year'],
            (int) $data['pay_month'],
            $request->user()?->id,
            (bool) ($data['include_punishments'] ?? false),
            $excludedEmployeeIds,
            $exclusionReason !== '' ? $exclusionReason : null,
        );
        $message = in_array($sheet->status, ['ready', 'released'], true)
            ? 'A completed payroll sheet already exists for this period.'
            : 'Payroll calculation queued. A worker will process the sheet in employee batches.';
        if ($excludedEmployeeIds !== []) {
            $message .= ' Documented payroll-period exclusions were saved.';
        }

        return redirect()->route('payroll.sheets.show', $sheet)->with('success', $message);
    }

    public function release(Request $request, PayrollSalarySheet $salarySheet, PayrollSalarySheetService $sheets): RedirectResponse
    {
        $sheets->release($salarySheet, $request->user()?->id);

        return back()->with('success', 'Salary sheet released. Individual pay slips can now be paid or placed on hold.');
    }

    public function show(Request $request, PayrollSalarySheet $salarySheet, EmployeePayrollDataProviderInterface $employees): Response
    {
        $sheet = $salarySheet->load('cutlist');
        $periodStart = sprintf('%d-%02d-01', (int) $sheet->pay_year, (int) $sheet->pay_month);
        $periodEnd = sprintf('%d-%02d-%02d', (int) $sheet->pay_year, (int) $sheet->pay_month, cal_days_in_month(CAL_GREGORIAN, (int) $sheet->pay_month, (int) $sheet->pay_year));
        $dt = DataTableQuery::fromRequest($request, ['employee_name', 'employee_id', 'gross_salary', 'total_deductions', 'net_salary'], [], 'employee_name', 'asc');
        $slipQuery = PayrollSlip::query()->where('salary_sheet_id', $sheet->id);
        if ($dt->search !== null && trim($dt->search) !== '') {
            $needle = '%'.addcslashes(trim($dt->search), '%_').'%';
            $slipQuery->where(fn ($q) => $q->where('employee_name', 'like', $needle)->orWhere('employee_id', 'like', $needle));
        }
        $sort = in_array($dt->sort, ['employee_name', 'employee_id', 'gross_salary', 'total_deductions', 'net_salary'], true) ? $dt->sort : 'employee_name';
        $slips = $slipQuery->orderBy($sort, $dt->direction)->paginate($dt->perPage)->withQueryString();
        $periodExclusions = PayrollPeriodExclusion::query()->with(['employee:id,employee_id,full_name', 'creator:id,name'])
            ->where('pay_year', (int) $sheet->pay_year)->where('pay_month', (int) $sheet->pay_month)
            ->orderBy('employee_id')->get()->map(fn (PayrollPeriodExclusion $exclusion) => [
                'employee_id' => (string) $exclusion->employee_id,
                'employee_name' => $exclusion->employee?->full_name,
                'reason' => $exclusion->reason,
                'created_by_name' => $exclusion->creator?->name,
                'created_at' => $exclusion->created_at?->toIso8601String(),
            ])->values();

        return Inertia::render('PAYROLL::SalarySheets/Show', [
            'sheet' => $sheet,
            'periodExclusions' => $periodExclusions,
            'releaseAudit' => PayrollWorkflowAudit::query()->where('subject_type', 'salary_sheet')->where('subject_id', $sheet->id)->where('event', 'released')->first(['actor_id', 'occurred_at']),
            'canRelease' => (bool) $request->user()?->can('payroll.sheets.release'),
            'canRefreshCutlist' => $sheet->status === 'failed' && ! $sheet->slips()->exists(),
            'payrollWarnings' => $employees->unpayableForPeriod($periodStart, $periodEnd),
            'slips' => PaginatedResource::from($slips),
        ]);
    }
}
