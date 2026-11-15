<?php

namespace Modules\PAYROLL\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\CORE\Support\PaginatedResource;
use Modules\PAYROLL\Interfaces\HRM\EmployeePayrollDataProviderInterface;
use Modules\PAYROLL\Models\PayrollLoan;
use Modules\PAYROLL\Models\PayrollLoanInstallment;
use Modules\PAYROLL\Models\PayrollLoanMonthlyBatch;
use Modules\PAYROLL\Services\PayrollLoanService;

/**
 * The staff-advance module — five screens, the way a fund desk actually works.
 *
 *   loans                       Overview    the book: what is out, what came back, who is late
 *   loans/register              Register    every advance with its schedule and its state
 *   loans/schedule              Schedule    installments by payroll month, and what payroll staged
 *   loans/create                New         request an advance
 *   loans/{loan}                Statement   one member's advance: schedule, approvals, recoveries
 *
 * The module used to be one screen holding the dashboard, the approval queue, the monthly allocation
 * form and the register. Splitting it follows the same rule the fund module learned the hard way: a
 * page you can both read and post from is a page whose numbers you cannot trust, because nothing on
 * it says which figures are older than the last write.
 */
final class PayrollLoanController extends Controller
{
    /** The overview: the shape of the book, not a list of loans. */
    public function index(Request $request, PayrollLoanService $loans): Response
    {
        $desk = $loans->dashboard(12);

        return Inertia::render('PAYROLL::Loans/Index', [
            'summary' => $desk['summary'],
            'cash' => $desk['cash'],
            'trend' => $desk['trend'],
            'recent' => $desk['recent'],
            'overdue' => $desk['overdue'],
            'watchlist' => $desk['watchlist'],
            'period' => $desk['period'],
            'ledger' => $loans->ledgerPosition(),
            'canManage' => (bool) $request->user()?->can('payroll.loans.manage'),
            'canApproveLoans' => (bool) $request->user()?->can('payroll.loans.approve'),
        ]);
    }

    /** The register: one row per advance, with the schedule behind it summarised on the row. */
    public function register(Request $request, PayrollLoanService $loans, EmployeePayrollDataProviderInterface $employees): Response
    {
        $page = $loans->paginate([
            'status' => $request->input('filters.status', $request->input('status')),
            'employee_id' => $request->input('filters.employee_id'),
            'search' => $request->input('search'),
        ], $request->integer('per_page', 25));
        $page->getCollection()->transform(fn ($loan) => array_merge($loan->toArray(), [
            'employee_name' => $loan->employee?->full_name,
            'requested_by' => $loan->requested_by,
            // A date-only column must leave the server as a date. `toArray()` renders the cast as an
            // ISO timestamp, and the browser then shifts it into its own timezone: 2026-10-01 became
            // "30 September" west of UTC. Ship the calendar date that was stored.
            'first_due_date' => $loan->first_due_date?->toDateString(),
        ]));
        $year = (int) now()->format('Y');
        $month = (int) now()->format('m');
        $allocationBatch = PayrollLoanMonthlyBatch::query()
            ->where('pay_year', $year)->where('pay_month', $month)
            ->withCount('allocations')->first();

        return Inertia::render('PAYROLL::Loans/Register', [
            'loans' => PaginatedResource::from($page),
            'employeeOptions' => $employees->employeeOptions(null, 200),
            'allocationPeriod' => ['year' => $year, 'month' => $month],
            'allocationBatch' => $allocationBatch,
            'currentUserId' => (int) $request->user()->id,
            'canApproveLoans' => (bool) $request->user()?->can('payroll.loans.approve'),
            'canManage' => (bool) $request->user()?->can('payroll.loans.manage'),
        ]);
    }

    /**
     * The schedule: installments grouped by the payroll month they fall due.
     *
     * This is the screen that answers "what will payroll deduct next month", which the register
     * cannot answer — the register is ordered by loan, and a month is a column across every loan.
     */
    public function schedule(Request $request, PayrollLoanService $loans): Response
    {
        $year = (int) ($request->input('year') ?: now()->year);
        $month = (int) ($request->input('month') ?: now()->month);
        $year = max(2000, min(2100, $year));
        $month = max(1, min(12, $month));
        $start = now()->setDate($year, $month, 1)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $rows = PayrollLoanInstallment::query()
            ->with(['loan:id,employee_id,employee_name,status,principal_amount,installment_count', 'loan.employee:id,employee_id,full_name'])
            ->whereBetween('due_date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('due_date')
            ->orderBy('payroll_loan_id')
            ->get()
            ->map(fn (PayrollLoanInstallment $row): array => [
                'id' => (int) $row->id,
                'loan_id' => (int) $row->payroll_loan_id,
                'employee_id' => $row->loan?->employee_id,
                'employee_name' => $row->loan?->employee_name ?: $row->loan?->employee?->full_name,
                'installment_number' => (int) $row->installment_number,
                'installment_count' => (int) ($row->loan?->installment_count ?? 0),
                'due_date' => $row->due_date?->toDateString(),
                'amount' => (float) $row->amount,
                'status' => $row->status,
            ])->all();

        $batch = PayrollLoanMonthlyBatch::query()
            ->where('pay_year', $year)->where('pay_month', $month)
            ->withCount('allocations')->first();

        return Inertia::render('PAYROLL::Loans/Schedule', [
            'period' => ['year' => $year, 'month' => $month, 'label' => $start->format('F Y')],
            'installments' => $rows,
            'totals' => [
                'count' => count($rows),
                'amount' => round(array_sum(array_column($rows, 'amount')), 2),
                'due' => round(array_sum(array_map(
                    fn (array $r): float => $r['status'] === 'paid' ? 0.0 : (float) $r['amount'],
                    $rows,
                )), 2),
                'paid' => round(array_sum(array_map(
                    fn (array $r): float => $r['status'] === 'paid' ? (float) $r['amount'] : 0.0,
                    $rows,
                )), 2),
                'staged' => $batch ? round((float) $batch->allocations()->sum('amount'), 2) : 0.0,
            ],
            'batch' => $batch ? [
                'id' => (int) $batch->id,
                'status' => $batch->status,
                'scope' => $batch->scope,
                'allocations' => (int) $batch->allocations_count,
                'locked_at' => $batch->locked_at?->toDateString(),
            ] : null,
            'employeeOptions' => $loans->batchEmployeeOptions(),
            'canManage' => (bool) $request->user()?->can('payroll.loans.manage'),
        ]);
    }

    /** One advance, end to end: its schedule, its approval trail, and what has been recovered. */
    public function show(Request $request, PayrollLoan $loan, PayrollLoanService $loans): Response
    {
        $loan->load(['employee:id,employee_id,full_name,designation,department', 'installments', 'approvals.actor:id,name']);

        $installments = $loan->installments->map(fn (PayrollLoanInstallment $row): array => [
            'id' => (int) $row->id,
            'installment_number' => (int) $row->installment_number,
            'due_date' => $row->due_date?->toDateString(),
            'amount' => (float) $row->amount,
            'status' => $row->status,
            'paid_at' => $row->paid_at?->toDateString(),
        ])->all();

        $recovered = round(array_sum(array_map(
            fn (array $r): float => $r['status'] === 'paid' ? $r['amount'] : 0.0,
            $installments,
        )), 2);
        $remaining = round(array_sum(array_map(
            fn (array $r): float => in_array($r['status'], ['scheduled', 'attached'], true) ? $r['amount'] : 0.0,
            $installments,
        )), 2);

        return Inertia::render('PAYROLL::Loans/Show', [
            'loan' => [
                'id' => (int) $loan->id,
                'employee_id' => $loan->employee_id,
                'employee_name' => $loan->employee_name ?: $loan->employee?->full_name,
                'designation' => $loan->employee?->designation,
                'department' => $loan->employee?->department,
                'principal_amount' => (float) $loan->principal_amount,
                'interest_rate' => (float) $loan->interest_rate,
                'installment_count' => (int) $loan->installment_count,
                'installment_amount' => (float) $loan->installment_amount,
                'first_due_date' => $loan->first_due_date?->toDateString(),
                'status' => $loan->status,
                'purpose' => $loan->purpose,
                'requested_by' => $loan->requested_by,
                'approved_by' => $loan->approved_by,
                'approved_at' => $loan->approved_at?->toDateString(),
                'created_at' => $loan->created_at?->toDateString(),
                'current_approval_step' => (int) $loan->current_approval_step,
                'approval_steps' => $loan->approval_steps,
                'recovered' => $recovered,
                'remaining' => $remaining,
                'progress_pct' => $loan->principal_amount > 0 ? round($recovered / (float) $loan->principal_amount * 100, 1) : 0.0,
                'installments' => $installments,
                'approvals' => $loan->approvals->map(fn ($row): array => [
                    'step_number' => (int) $row->step_number,
                    'step_name' => $row->step_name,
                    'decision' => $row->decision,
                    'actor' => $row->actor?->name,
                    'comments' => $row->comments,
                    'decided_at' => $row->decided_at?->toDateString(),
                ])->all(),
            ],
            'currentUserId' => (int) $request->user()->id,
            'canApproveLoans' => (bool) $request->user()?->can('payroll.loans.approve'),
            'canManage' => (bool) $request->user()?->can('payroll.loans.manage'),
        ]);
    }

    /**
     * Recognise a month's approved advances in the ledger.
     *
     * The module has been able to approve an advance and recover it since P12, but nothing in the
     * books ever said the company was owed the money. This posts that claim: debit the receivable,
     * credit the salary payable the advance was drawn against.
     */
    public function postLedger(Request $request, PayrollLoanService $loans): RedirectResponse
    {
        $data = $request->validate([
            'pay_year' => ['required', 'integer', 'between:2000,2100'],
            'pay_month' => ['required', 'integer', 'between:1,12'],
        ]);

        $result = $loans->postAdvances((int) $data['pay_year'], (int) $data['pay_month'], $request->user()?->id);

        if (! $result['posted']) {
            return back()->with('error', $result['reason'] ?? 'Nothing was posted.');
        }

        return back()->with('success', sprintf(
            '%d advance(s) worth %s recognised for %04d-%02d as %s (Dr 1315 Staff Advance Receivable).',
            $result['advances'], number_format($result['amount'], 2), $data['pay_year'], $data['pay_month'], $result['reference'],
        ));
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

        return redirect()->route('payroll.loans.show', $loan->id)->with('success', 'Interest-free loan request #'.$loan->id.' submitted for approval.');
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
