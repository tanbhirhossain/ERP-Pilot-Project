<?php

namespace Modules\PAYROLL\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\PAYROLL\Interfaces\HRM\EmployeePayrollDataProviderInterface;
use Modules\PAYROLL\Models\PayrollLoan;
use Modules\PAYROLL\Models\PayrollLoanMonthlyBatch;
use Modules\PAYROLL\Services\PayrollLoanService;
use Modules\CORE\Support\PaginatedResource;

final class PayrollLoanController extends Controller
{
    public function index(Request $request, PayrollLoanService $loans, EmployeePayrollDataProviderInterface $employees): Response
    {
        $page = $loans->paginate(['status'=>$request->input('filters.status',$request->input('status')),'employee_id'=>$request->input('filters.employee_id'),'search'=>$request->input('search')], $request->integer('per_page',25));
        $page->getCollection()->transform(fn ($loan) => array_merge($loan->toArray(), [
            'employee_name' => $loan->employee?->full_name,
            'requested_by' => $loan->requested_by,
        ]));
        $year = (int) now()->format('Y');
        $month = (int) now()->format('m');
        $allocationBatch = PayrollLoanMonthlyBatch::query()->where('pay_year', $year)->where('pay_month', $month)->withCount('allocations')->first();
        return Inertia::render('PAYROLL::Loans/Index', [
            'loans' => PaginatedResource::from($page),
            'employeeOptions' => $employees->employeeOptions(null, 200),
            'allocationPeriod' => ['year' => $year, 'month' => $month],
            'allocationBatch' => $allocationBatch,
            'currentUserId' => (int) $request->user()->id,
            'canApproveLoans' => (bool) $request->user()?->can('payroll.loans.approve'),
        ]);
    }

    public function create(EmployeePayrollDataProviderInterface $employees): Response
    {
        return Inertia::render('PAYROLL::Loans/Create', ['employeeOptions' => $employees->employeeOptions(null, 200)]);
    }

    public function store(Request $request, PayrollLoanService $loans): RedirectResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'exists:employees,employee_id'],
            'principal_amount' => ['required', 'numeric', 'gt:0', 'max:9999999999.99'],
            'installment_count' => ['required', 'integer', 'between:1,120'],
            'first_due_date' => ['required', 'date'],
            'purpose' => ['required', 'string', 'min:5', 'max:1000'],
        ]);
        $loan = $loans->request($data, $request->user()?->id);
        return redirect()->route('payroll.loans.index')->with('success', 'Interest-free loan request #'.$loan->id.' submitted for approval.');
    }

    public function approve(Request $request, PayrollLoan $loan, PayrollLoanService $loans): RedirectResponse
    {
        $data = $request->validate(['comments' => ['nullable', 'string', 'max:1000']]);
        $result = $loans->approve($loan, (int) $request->user()->id, $data['comments'] ?? null);
        return back()->with('success', $result->status === 'active' ? 'Loan approved and its 0% monthly schedule allocated.' : 'Approval stage completed; loan is awaiting the next approval tier.');
    }

    public function allocateInstallments(Request $request, PayrollLoanService $loans): RedirectResponse
    {
        $data = $request->validate([
            'mode' => ['required', 'in:selected,all'],
            'loan_ids' => ['required_if:mode,selected', 'array', 'min:1'],
            'loan_ids.*' => ['integer', 'distinct', 'exists:payroll_loans,id'],
        ]);
        $result = $loans->allocateMissingInstallments($data['loan_ids'] ?? [], $data['mode'] === 'all');
        return back()->with('success', $result['allocated'].' active loan repayment schedule(s) allocated; '.$result['skipped'].' skipped because already scheduled or inactive.');
    }

    public function allocateMonthly(Request $request, PayrollLoanService $loans): RedirectResponse
    {
        $data = $request->validate([
            'pay_year' => ['required', 'integer', 'between:2000,2100'],
            'pay_month' => ['required', 'integer', 'between:1,12'],
            'scope' => ['required', 'in:single,selected,all'],
            'employee_id' => ['required_if:scope,single', 'nullable', 'exists:employees,employee_id'],
            'employee_ids' => ['required_if:scope,selected', 'array', 'min:1'],
            'employee_ids.*' => ['string', 'distinct', 'exists:employees,employee_id'],
        ]);
        $employeeIds = $data['scope'] === 'single'
            ? [(string) $data['employee_id']]
            : ($data['scope'] === 'selected' ? array_map('strval', $data['employee_ids'] ?? []) : []);
        $result = $loans->allocateMonthlyInstallments(
            (int) $data['pay_year'],
            (int) $data['pay_month'],
            (string) $data['scope'],
            $employeeIds,
            $request->user()?->id,
        );
        return back()->with('success', sprintf(
            '%d due installment(s) totaling %s allocated for %04d-%02d; %d total installment(s) are staged in the monthly payroll batch. Create the salary sheet to attach them to payslips.',
            $result['allocated'], number_format((float) $result['amount'], 2), $result['year'], $result['month'], $result['existing'],
        ));
    }

    public function reject(Request $request, PayrollLoan $loan, PayrollLoanService $loans): RedirectResponse
    {
        $data = $request->validate(['comments' => ['required', 'string', 'min:3', 'max:1000']]);
        $loans->reject($loan, (int) $request->user()->id, $data['comments']);
        return back()->with('success', 'Loan request rejected.');
    }
}
