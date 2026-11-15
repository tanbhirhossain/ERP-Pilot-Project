<?php

namespace Modules\PAYROLL\Services;

use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Modules\ACCOUNTS\DTO\JournalData;
use Modules\ACCOUNTS\DTO\JournalLineData;
use Modules\ACCOUNTS\Interfaces\AccountsPostingProviderInterface;
use Modules\ACCOUNTS\Models\Account;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\PAYROLL\Actions\ApprovePayrollLoanAction;
use Modules\PAYROLL\Interfaces\HRM\EmployeePayrollDataProviderInterface;
use Modules\PAYROLL\Interfaces\Repositories\PayrollLoanRepositoryInterface;
use Modules\PAYROLL\Models\PayrollLoan;
use Modules\PAYROLL\Models\PayrollLoanInstallment;
use Modules\PAYROLL\Models\PayrollLoanMonthlyAllocation;
use Modules\PAYROLL\Models\PayrollLoanMonthlyBatch;
use Modules\PAYROLL\Models\PayrollSalarySheet;
use Modules\PAYROLL\Models\PayrollSetting;

final class PayrollLoanService
{
    public function __construct(
        private readonly PayrollLoanRepositoryInterface $loans,
        private readonly ApprovePayrollLoanAction $approval,
        private readonly EmployeePayrollDataProviderInterface $employees,
        private readonly AccountsPostingProviderInterface $posting,
    ) {}

    public function paginate(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return $this->loans->paginate($filters, $perPage);
    }

    public function findOrFail(int $id): PayrollLoan
    {
        return $this->loans->findOrFail($id);
    }

    public function request(array $data, ?int $actorId): PayrollLoan
    {
        if ((float) $data['principal_amount'] <= 0 || (float) $data['principal_amount'] > 9999999999.99 || (int) $data['installment_count'] < 1 || (int) $data['installment_count'] > 120) {
            throw ValidationException::withMessages(['principal_amount' => 'Enter a positive loan amount within the supported limit and between 1 and 120 monthly installments.']);
        }
        $data['principal_amount'] = round((float) $data['principal_amount'], 2);
        $employee = $this->employees->employeeForPayroll($data['employee_id'], now()->toDateString(), now()->toDateString());
        if ($employee === null) {
            throw ValidationException::withMessages(['employee_id' => 'No active HRM salary record was found for this employee.']);
        }
        $data['employee_name'] = $employee->name;
        $data['interest_rate'] = 0.0;
        $data['requested_by'] = $actorId;
        $data['approval_steps'] = PayrollLoanApprovalSteps::normalize(PayrollSetting::query()->first()?->loan_approval_steps);
        $data['installment_amount'] = round((float) $data['principal_amount'] / (int) $data['installment_count'], 2);
        $data['status'] = 'pending';
        unset($data['approval_steps_text']);

        return $this->loans->create($data);
    }

    public function approve(PayrollLoan $loan, int $actorId, ?string $comments = null): PayrollLoan
    {
        return $this->approval->approve($loan, $actorId, $comments);
    }

    public function reject(PayrollLoan $loan, int $actorId, ?string $comments = null): PayrollLoan
    {
        return $this->approval->reject($loan, $actorId, $comments);
    }

    /**
     * The loan desk: what the book is worth, what came back this month, what is overdue.
     *
     * Every figure is summed from `payroll_loan_installments` rather than from the loan row, because
     * an installment is the only thing that says a repayment was *actually taken* — the loan row
     * only knows what was promised. A fund that reports "recovered" from the promise is the reason
     * loan registers drift away from the ledger.
     *
     * @return array{summary: array<string, int|float|string|null>, cash: array<string, float|int>, trend: array<int, array<string, mixed>>, recent: array<int, array<string, mixed>>, overdue: array<int, array<string, mixed>>, watchlist: array<int, array<string, mixed>>}
     */
    public function dashboard(int $months = 12): array
    {
        $today = now()->toDateString();
        $installments = PayrollLoanInstallment::query();

        $principal = (float) (clone $installments)->sum('amount');
        $recovered = (float) (clone $installments)->where('status', 'paid')->sum('amount');
        $outstanding = (float) (clone $installments)->whereIn('status', ['scheduled', 'attached'])->sum('amount');
        $overdueAmount = (float) (clone $installments)->whereIn('status', ['scheduled', 'attached'])->whereDate('due_date', '<', $today)->sum('amount');

        $summary = [
            'loans' => PayrollLoan::query()->count(),
            'active_loans' => PayrollLoan::query()->where('status', 'active')->count(),
            'pending_loans' => PayrollLoan::query()->where('status', 'pending')->count(),
            'completed_loans' => PayrollLoan::query()->where('status', 'completed')->count(),
            'rejected_loans' => PayrollLoan::query()->where('status', 'rejected')->count(),
            'employees_with_loans' => PayrollLoan::query()->where('status', 'active')->distinct()->count('employee_id'),
            // Summed from installments, not from the loan rows: an advance nobody scheduled is still
            // money the company is owed and must not vanish from the total.
            'principal' => round($principal, 2),
            'recovered' => round($recovered, 2),
            'outstanding' => round($outstanding, 2),
            'overdue_amount' => round($overdueAmount, 2),
            'overdue_count' => (clone $installments)->whereIn('status', ['scheduled', 'attached'])->whereDate('due_date', '<', $today)->count(),
            'due_this_month' => (float) (clone $installments)
                ->whereIn('status', ['scheduled', 'attached'])
                ->whereBetween('due_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
                ->sum('amount'),
            'staged_this_month' => (float) PayrollLoanMonthlyAllocation::query()
                ->whereHas('batch', fn ($q) => $q->where('pay_year', now()->year)->where('pay_month', now()->month))
                ->sum('amount'),
        ];

        $lastBatch = PayrollLoanMonthlyBatch::query()->orderByDesc('pay_year')->orderByDesc('pay_month')->first();

        return [
            'summary' => $summary,
            'cash' => [
                'recovered' => round($recovered, 2),
                'outstanding' => round($outstanding, 2),
                // The share of the book that has come back. Not a percentage of the loan count —
                // a 50-installment advance repaid halfway is 50% recovered whatever the count says.
                'recovered_pct' => $principal > 0 ? round($recovered / $principal * 100, 1) : 0.0,
                'overdue_amount' => round($overdueAmount, 2),
            ],
            'trend' => $this->recoveryTrend($months),
            'recent' => $this->recentRequests(5),
            'overdue' => $this->overdueInstallments(6),
            'watchlist' => $this->watchlist(5),
            'period' => [
                'year' => (int) now()->year,
                'month' => (int) now()->month,
                'batch' => $lastBatch ? [
                    'pay_year' => (int) $lastBatch->pay_year,
                    'pay_month' => (int) $lastBatch->pay_month,
                    'status' => $lastBatch->status,
                    'scope' => $lastBatch->scope,
                    'installments' => $lastBatch->allocations()->count(),
                ] : null,
            ],
        ];
    }

    /**
     * The employee list used by the staging form on the schedule page.
     *
     * `employeeOptions()` hands back a Collection and the controller's prop is an array — returning it
     * straight through typed the method against the wrong thing and the page 500'd.
     */
    public function batchEmployeeOptions(): array
    {
        return $this->employees->employeeOptions(null, 200)->all();
    }

    /**
     * What was actually recovered each payroll month, oldest first, with the gap months present.
     *
     * Oldest-first because a chart is read left to right; zero-filled because a month with no payroll
     * run is a month the company recovered nothing, and dropping it would silently shorten the axis
     * and make a two-month gap look like consecutive months.
     *
     * @return array<int, array{period: string, label: string, label_long: string, recovered: float, scheduled: float, loans: int}>
     */
    public function recoveryTrend(int $months = 12): array
    {
        $months = max(1, min(24, $months));
        $rows = PayrollLoanInstallment::query()
            ->selectRaw("strftime('%Y-%m', paid_at) as period, sum(amount) as recovered, count(distinct payroll_loan_id) as loans")
            ->where('status', 'paid')
            ->whereNotNull('paid_at')
            ->groupBy('period')
            ->pluck('recovered', 'period');
        $counts = PayrollLoanInstallment::query()
            ->selectRaw("strftime('%Y-%m', paid_at) as period, count(distinct payroll_loan_id) as loans")
            ->where('status', 'paid')
            ->whereNotNull('paid_at')
            ->groupBy('period')
            ->pluck('loans', 'period');
        $scheduled = PayrollLoanInstallment::query()
            ->selectRaw("strftime('%Y-%m', due_date) as period, sum(amount) as amount")
            ->whereIn('status', ['scheduled', 'attached'])
            ->groupBy('period')
            ->pluck('amount', 'period');

        $anchor = PayrollLoanInstallment::query()->whereNotNull('paid_at')->max('paid_at');
        $end = $anchor ? Carbon::parse($anchor)->startOfMonth() : now()->startOfMonth();

        $trend = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $month = $end->copy()->subMonthsNoOverflow($i);
            $period = $month->format('Y-m');
            $trend[] = [
                'period' => $period,
                'label' => $month->format('M'),
                'label_long' => $month->format('F Y'),
                'recovered' => round((float) ($rows[$period] ?? 0), 2),
                'scheduled' => round((float) ($scheduled[$period] ?? 0), 2),
                'loans' => (int) ($counts[$period] ?? 0),
            ];
        }

        return $trend;
    }

    /**
     * The advances that need a decision, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recentRequests(int $limit = 5): array
    {
        return PayrollLoan::query()
            ->with('employee:id,employee_id,full_name,designation')
            ->latest('id')
            ->limit(max(1, $limit))
            ->get()
            ->map(fn (PayrollLoan $loan): array => [
                'id' => (int) $loan->id,
                'employee_id' => $loan->employee_id,
                'employee_name' => $loan->employee_name ?: $loan->employee?->full_name,
                'principal_amount' => (float) $loan->principal_amount,
                'installment_count' => (int) $loan->installment_count,
                'status' => $loan->status,
                'purpose' => $loan->purpose,
                'created_at' => $loan->created_at?->toDateString(),
            ])->all();
    }

    /**
     * Repayments whose due date has passed and that payroll has not taken yet.
     *
     * This is the list that matters and the one the old screen never showed: an installment can sit
     * `scheduled` for a year while the loan still reads "active" and the register still calls it
     * healthy. The month it was due is what the operator needs, not the month it is today.
     *
     * @return array<int, array<string, mixed>>
     */
    public function overdueInstallments(int $limit = 10): array
    {
        return PayrollLoanInstallment::query()
            ->with(['loan:id,employee_id,employee_name,status,principal_amount,installment_count', 'loan.employee:id,employee_id,full_name'])
            ->whereIn('status', ['scheduled', 'attached'])
            ->whereDate('due_date', '<', now()->toDateString())
            ->orderBy('due_date')
            ->limit(max(1, $limit))
            ->get()
            ->map(fn (PayrollLoanInstallment $row): array => [
                'id' => (int) $row->id,
                'loan_id' => (int) $row->payroll_loan_id,
                'employee_id' => $row->loan?->employee_id,
                'employee_name' => $row->loan?->employee_name ?: $row->loan?->employee?->full_name,
                'installment_number' => (int) $row->installment_number,
                'due_date' => $row->due_date?->toDateString(),
                'amount' => (float) $row->amount,
                'days_late' => $row->due_date ? (int) $row->due_date->diffInDays(now()->startOfDay()) : 0,
            ])->all();
    }

    /**
     * The advances closest to being repaid, and the ones carrying the most risk.
     *
     * A loan near its end is worth watching because the next payroll month closes it; a loan with a
     * large remainder is worth watching because that is the company's exposure.
     *
     * @return array<int, array<string, mixed>>
     */
    public function watchlist(int $limit = 5): array
    {
        $rows = PayrollLoan::query()
            ->with('employee:id,employee_id,full_name')
            ->where('status', 'active')
            ->withCount([
                'installments',
                'installments as paid_installments_count' => fn ($q) => $q->where('status', 'paid'),
                'installments as remaining_installments_count' => fn ($q) => $q->whereIn('status', ['scheduled', 'attached']),
            ])
            ->get();

        $balances = PayrollLoanInstallment::query()
            ->selectRaw("payroll_loan_id, sum(case when status = 'paid' then amount else 0 end) as recovered, sum(case when status in ('scheduled','attached') then amount else 0 end) as remaining")
            ->whereIn('payroll_loan_id', $rows->pluck('id'))
            ->groupBy('payroll_loan_id')
            ->get()
            ->keyBy('payroll_loan_id');

        return $rows
            ->map(fn (PayrollLoan $loan): array => [
                'id' => (int) $loan->id,
                'employee_id' => $loan->employee_id,
                'employee_name' => $loan->employee_name ?: $loan->employee?->full_name,
                'principal_amount' => (float) $loan->principal_amount,
                'recovered' => round((float) ($balances[$loan->id]->recovered ?? 0), 2),
                'remaining' => round((float) ($balances[$loan->id]->remaining ?? 0), 2),
                'installments' => (int) $loan->installments_count,
                'paid_installments' => (int) $loan->paid_installments_count,
                'remaining_installments' => (int) $loan->remaining_installments_count,
                'progress_pct' => $loan->installments_count > 0
                    ? round((int) $loan->paid_installments_count / (int) $loan->installments_count * 100, 0)
                    : 0,
            ])
            // Most exposure first, then nearly-finished: those are the two ends an operator acts on.
            ->sortByDesc(fn (array $row): array => [$row['remaining'], -$row['remaining_installments']])
            ->take(max(1, $limit))
            ->values()
            ->all();
    }

    /**
     * What the advances book actually costs the company, in ledger terms.
     *
     * This is the figure the loan module was missing entirely. Payroll already credits the recovery
     * to `2125 Payroll Deductions Payable`, which is money the *company owes the employee* — so a
     * recovery moved value from "wages payable" to "deductions payable" and the advance itself never
     * appeared as an asset anywhere. A loan book that shows ৳50,000 outstanding in the module and
     * nothing at all in the trial balance is a loan book an auditor cannot tie out.
     *
     * The fix is not to invent a number: it is to say plainly how much of the book has no ledger
     * counterpart, so the operator knows the size of the gap and can journal it once.
     *
     * @return array{recoverable: float, ledger_receivable: float, unreconciled: float, gl_linked: bool, note: string}
     */
    public function ledgerPosition(): array
    {
        $recoverable = round((float) PayrollLoanInstallment::query()
            ->whereIn('status', ['scheduled', 'attached'])
            ->sum('amount'), 2);

        // 1315 is the staff-advance receivable this service posts to. It does not exist until the
        // first advance is journalised, and the accounts module only allows posting to leaf accounts.
        $receivable = Account::query()->where('code', '1315')->first();
        $ledger = 0.0;
        if ($receivable !== null) {
            $ledger = round((float) DB::table('acc_journal_lines')->where('account_id', $receivable->id)->sum(DB::raw('debit - credit')), 2);
        }

        return [
            'recoverable' => $recoverable,
            'ledger_receivable' => $ledger,
            'unreconciled' => round($recoverable - $ledger, 2),
            'gl_linked' => $receivable !== null,
            'note' => $receivable === null
                ? 'No staff-advance receivable account exists yet, so nothing in the trial balance represents these advances. Payroll credits loan recovery to Payroll Deductions Payable (2125) instead of an asset.'
                : 'Advances are expected to appear in 1315 Staff Advance Receivable. Payroll still credits 2125 Payroll Deductions Payable on recovery, so the two have to be reconciled by hand.',
        ];
    }

    /**
     * Journal the approved advances of one payroll month as a receivable.
     *
     *   Dr 1315 Staff Advance Receivable
     *       Cr 2121 Salary Payable
     *
     * Nothing about an advance is an expense: it is cash (or a payable) exchanged for a claim on the
     * employee, and it becomes salary only when it is recovered. Crediting `2121` rather than a bank
     * account is the honest entry for this system, because there is no record of an advance ever
     * having been paid out — the module approves a claim and payroll recovers it, so the counterpart
     * belongs wherever the salary it was advanced against is sitting.
     *
     * Idempotent per month: monthly allocations and installments do not carry a journal id, so the
     * journal reference names the period and `postJournal` is keyed on it. Running twice is a no-op.
     *
     * @return array{posted: bool, journal_id: int|null, reference: string|null, amount: float, advances: int, reason: string|null}
     */
    public function postAdvances(int $year, int $month, ?int $actorId = null): array
    {
        if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
            throw ValidationException::withMessages(['period' => 'Choose a valid payroll period.']);
        }

        $start = Carbon::create($year, $month, 1)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        // Only advances approved inside the month become its receivable — an advance approved in
        // October is not October's asset if the money moved in September.
        $advances = PayrollLoan::query()
            ->where('status', 'active')
            ->whereBetween('approved_at', [$start->toDateTimeString(), $end->toDateTimeString()])
            ->get();

        $amount = round((float) $advances->sum('principal_amount'), 2);
        $reference = sprintf('LOAN-%04d%02d', $year, $month);

        if ($amount <= 0.01) {
            return ['posted' => false, 'journal_id' => null, 'reference' => $reference, 'amount' => 0.0, 'advances' => 0, 'reason' => 'No advance was approved in this month, so there is nothing to recognise.'];
        }

        $already = DB::table('acc_journals')->where('reference_no', $reference)->where('source_type', 'payroll_loans')->first();
        if ($already !== null) {
            return ['posted' => false, 'journal_id' => (int) $already->id, 'reference' => $reference, 'amount' => $amount, 'advances' => $advances->count(), 'reason' => 'This month has already been recognised in the ledger.'];
        }

        $receivableId = $this->staffAdvanceAccount();
        $payableId = $this->salaryPayableAccount();

        // The date has to be one the books are open in. An advance approved this month but posted
        // against a closed month would be rejected by the accounts module, so the posting date is
        // today when today falls inside the period, and the period end otherwise.
        $journalDate = now()->between($start, $end) ? now()->toDateString() : $end->toDateString();

        $journal = $this->posting->postJournal(new JournalData(
            journalDate: $journalDate,
            type: 'JV',
            referenceNo: $reference,
            narration: sprintf('Staff advances approved %s (%d advance(s))', $start->format('F Y'), $advances->count()),
            lines: [
                new JournalLineData(accountId: $receivableId, debit: $amount, description: sprintf('Staff advance receivable — %d advance(s) approved %s', $advances->count(), $start->format('F Y'))),
                new JournalLineData(accountId: $payableId, credit: $amount, description: 'Advance drawn against salary payable'),
            ],
            sourceType: 'payroll_loans',
            sourceId: null,
        ));

        Log::info('Staff advances recognised', ['reference' => $reference, 'journal_id' => $journal->id, 'amount' => $amount, 'advances' => $advances->count()]);

        return ['posted' => true, 'journal_id' => (int) $journal->id, 'reference' => $reference, 'amount' => $amount, 'advances' => $advances->count(), 'reason' => null];
    }

    /**
     * Salary Payable — the account an advance is drawn against.
     *
     * Created here as well as in the payroll journal service, because "run a payroll journal first"
     * is a dead end on a database whose payroll has never posted: the operator would be told to fix
     * an unrelated thing before the advance could be recognised. Both paths create the same account
     * with the same code and attributes, so whichever runs first wins and the other finds it.
     */
    private function salaryPayableAccount(): int
    {
        $existing = Account::query()->where('code', '2121')->first();
        if ($existing !== null) {
            return (int) $existing->id;
        }

        $parent = Account::query()->where('code', '2000')->first();

        $account = Account::create([
            'code' => '2121',
            'name' => 'Salary Payable',
            'account_type' => 'liability',
            'nature' => 'credit',
            'parent_id' => $parent?->id,
            'is_group' => false,
            'is_posting' => true,
            'is_active' => true,
            'is_system' => true,
            'description' => 'Auto-created by the staff-advance module so an advance has a counterpart.',
            'created_by' => auth()->id() ?? 1,
        ]);

        Log::info('SalaryPayable created by staff-advance module', ['id' => $account->id]);

        return (int) $account->id;
    }

    /** The staff-advance receivable, created on first use because a chart of accounts is not code. */
    private function staffAdvanceAccount(): int
    {
        $existing = Account::query()->where('code', '1315')->first();
        if ($existing !== null) {
            return (int) $existing->id;
        }

        $parent = Account::query()->where('code', '1300')->first();

        $account = Account::create([
            'code' => '1315',
            'name' => 'Staff Advance Receivable',
            'account_type' => 'asset',
            'nature' => 'debit',
            'parent_id' => $parent?->id,
            'is_group' => false,
            'is_posting' => true,
            'is_active' => true,
            'is_system' => true,
            'description' => 'Interest-free advances to employees, recovered through payroll. Created by the staff-advance module.',
            'created_by' => auth()->id() ?? 1,
        ]);

        Log::info('StaffAdvanceReceivable created', ['id' => $account->id]);

        return (int) $account->id;
    }

    /** Create missing repayment schedules for one, selected, or all active loans without bypassing loan approval. */
    public function allocateMissingInstallments(array $loanIds = [], bool $all = false): array
    {
        $query = PayrollLoan::query()->where('status', 'active')
            ->whereDoesntHave('installments');
        if (! $all) {
            $query->whereIn('id', $loanIds);
        }
        $allocated = 0;
        $skipped = 0;
        $query->orderBy('id')->chunkById(100, function ($loans) use (&$allocated, &$skipped): void {
            foreach ($loans as $loan) {
                DB::transaction(function () use ($loan, &$allocated, &$skipped): void {
                    $locked = PayrollLoan::query()->lockForUpdate()->findOrFail($loan->id);
                    if ($locked->status !== 'active' || $locked->installments()->exists()) {
                        $skipped++;

                        return;
                    }
                    $principal = round((float) $locked->principal_amount, 2);
                    $count = max(1, (int) $locked->installment_count);
                    $standard = round($principal / $count, 2);
                    $remaining = $principal;
                    $firstDue = Carbon::parse($locked->first_due_date)->startOfDay();
                    for ($i = 1; $i <= $count; $i++) {
                        $amount = $i === $count ? $remaining : min($remaining, $standard);
                        PayrollLoanInstallment::query()->create([
                            'payroll_loan_id' => $locked->id, 'installment_number' => $i,
                            'due_date' => $firstDue->copy()->addMonthsNoOverflow($i - 1)->toDateString(),
                            'amount' => $amount, 'status' => 'scheduled',
                        ]);
                        $remaining = round($remaining - $amount, 2);
                    }
                    $locked->update(['installment_amount' => $standard, 'interest_rate' => 0]);
                    $allocated++;
                });
            }
        });

        return ['allocated' => $allocated, 'skipped' => $skipped];
    }

    /** Reserve at most one due, zero-interest repayment per loan for a payroll month. */
    public function allocateMonthlyInstallments(int $year, int $month, string $scope, array $employeeIds, ?int $actorId): array
    {
        if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12 || ! in_array($scope, ['single', 'selected', 'all'], true)) {
            throw ValidationException::withMessages(['allocation' => 'Choose a valid payroll period and allocation scope.']);
        }
        $employeeIds = array_values(array_unique(array_filter(array_map('strval', $employeeIds))));
        if ($scope !== 'all' && $employeeIds === []) {
            throw ValidationException::withMessages(['employee_ids' => 'Select at least one employee for this allocation.']);
        }
        if ($scope === 'single' && count($employeeIds) !== 1) {
            throw ValidationException::withMessages(['employee_ids' => 'Single-employee allocation requires exactly one employee.']);
        }

        return DB::transaction(function () use ($year, $month, $scope, $employeeIds, $actorId): array {
            if (PayrollSalarySheet::query()->where('pay_year', $year)->where('pay_month', $month)->exists()) {
                throw ValidationException::withMessages(['period' => 'Monthly loan allocation must be completed before creating the salary sheet for this period. Existing salary sheets use an immutable calculation snapshot.']);
            }

            $now = now();
            DB::table('payroll_loan_monthly_batches')->insertOrIgnore([
                'pay_year' => $year,
                'pay_month' => $month,
                'scope' => $scope === 'all' ? 'all' : 'selected',
                'employee_ids' => $scope === 'all' ? null : json_encode($employeeIds, JSON_THROW_ON_ERROR),
                'status' => 'open',
                'created_by' => $actorId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $batch = PayrollLoanMonthlyBatch::query()->where('pay_year', $year)->where('pay_month', $month)->lockForUpdate()->firstOrFail();
            if ($batch->status !== 'open') {
                throw ValidationException::withMessages(['period' => 'This month’s loan allocation is already locked by payroll.']);
            }

            $isAll = $scope === 'all' || $batch->scope === 'all';
            if ($scope === 'all' && $batch->scope !== 'all') {
                $batch->update(['scope' => 'all', 'employee_ids' => null]);
            } elseif (! $isAll) {
                $merged = array_values(array_unique(array_merge($batch->employee_ids ?? [], $employeeIds)));
                $batch->update(['scope' => 'selected', 'employee_ids' => $merged]);
            }

            $loanQuery = PayrollLoan::query()->where('status', 'active');
            if (! $isAll) {
                $loanQuery->whereIn('employee_id', $employeeIds);
            }
            $loanIds = $loanQuery->orderBy('id')->lockForUpdate()->pluck('id')->all();
            if ($loanIds === []) {
                return ['allocated' => 0, 'amount' => 0.0, 'existing' => $batch->allocations()->count(), 'scope' => $batch->scope, 'status' => $batch->status, 'year' => $year, 'month' => $month];
            }

            // Serialize allocations per loan across payroll periods, not just per installment.
            $blockedLoanIds = PayrollLoanMonthlyAllocation::query()
                ->whereIn('payroll_loan_id', $loanIds)->whereIn('status', ['allocated', 'attached'])
                ->lockForUpdate()->pluck('payroll_loan_id')->map(fn ($id) => (int) $id)->all();
            $legacyAttachedLoanIds = PayrollLoanInstallment::query()
                ->whereIn('payroll_loan_id', $loanIds)->where('status', 'attached')
                ->lockForUpdate()->pluck('payroll_loan_id')->map(fn ($id) => (int) $id)->all();
            $blockedLoanIds = array_values(array_unique(array_merge($blockedLoanIds, $legacyAttachedLoanIds)));
            $periodEnd = Carbon::create($year, $month, 1)->endOfMonth()->toDateString();
            $query = PayrollLoanInstallment::query()
                ->with('loan:id,employee_id,status,interest_rate')
                ->whereIn('payroll_loan_id', $loanIds)
                ->when($blockedLoanIds !== [], fn ($installments) => $installments->whereNotIn('payroll_loan_id', $blockedLoanIds))
                ->where('status', 'scheduled')
                ->whereDate('due_date', '<=', $periodEnd)
                ->whereDoesntHave('monthlyAllocation')
                ->orderBy('payroll_loan_id')->orderBy('due_date')->orderBy('installment_number')->orderBy('id')
                ->lockForUpdate();

            $firstDueByLoan = $query->get()->groupBy('payroll_loan_id')->map(fn ($rows) => $rows->first());
            $allocated = 0;
            $allocatedAmount = 0.0;
            foreach ($firstDueByLoan as $installment) {
                if (! $installment->loan || (float) $installment->loan->interest_rate !== 0.0) {
                    throw ValidationException::withMessages(['loan_installments' => 'A scheduled repayment is inconsistent with the 0% interest loan policy. Contact payroll support before retrying.']);
                }
                PayrollLoanMonthlyAllocation::query()->create([
                    'batch_id' => $batch->id,
                    'payroll_loan_id' => $installment->payroll_loan_id,
                    'payroll_loan_installment_id' => $installment->id,
                    'employee_id' => $installment->loan->employee_id,
                    'amount' => $installment->amount,
                    'status' => 'allocated',
                    'created_by' => $actorId,
                    'allocated_at' => now(),
                ]);
                $allocated++;
                $allocatedAmount = round($allocatedAmount + (float) $installment->amount, 2);
            }

            $batch->refresh();

            return [
                'allocated' => $allocated,
                'amount' => $allocatedAmount,
                'existing' => $batch->allocations()->count(),
                'scope' => $batch->scope,
                'status' => $batch->status,
                'year' => $year,
                'month' => $month,
            ];
        }, 3);
    }
}
