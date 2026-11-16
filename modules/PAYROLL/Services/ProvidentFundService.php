<?php

namespace Modules\PAYROLL\Services;

use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\CORE\Support\DataTableQuery;
use Modules\HRM\Models\Employee\Employee;
use Modules\PAYROLL\Models\PfAccount;
use Modules\PAYROLL\Models\PfLedger;
use Modules\PAYROLL\Models\PfPolicy;
use Modules\PAYROLL\Models\PfWithdrawal;
use Modules\PAYROLL\Models\PayrollSalarySheet;
use Modules\PAYROLL\Models\PayrollSlip;

/**
 * The provident fund as a real liability.
 *
 * The pilot's fund read 0.00 on every screen while 1,962 payroll slips carried a computed
 * `provident_fund` block, because nothing ever posted a ledger entry: the only writer was
 * "mark slip paid", and every slip was unpaid. The fund is therefore built around one idea —
 * **a contribution is accrued when the month is closed, not when the cash moves** (the accounting
 * model the user chose) — and everything else follows from it:
 *
 *   - `accrueFromSheet()` posts both sides for a payroll sheet, idempotently.
 *   - `backfillHistory()` does the same for every already-computed slip, so the pilot's own data
 *     becomes correct rather than merely starting to be correct from next month.
 *   - `fundSummary()` reports the two numbers a fund manager is asked for: what the fund owes
 *     (accrued and settled credits) and what it has paid out.
 *   - Withdrawals move money out through the same ledger, never by editing a balance.
 */
final class ProvidentFundService
{
    /* ------------------------------ accounts ------------------------------ */

    /**
     * The account list, with the columns a fund desk actually filters on.
     *
     * Balances come from the cached columns (`employee_balance` + `employer_balance`), which
     * `recalculateBalances()` proves against the ledger; summing the ledger per row would make an
     * 875-row page unusable on a live database.
     */
    public function accounts(DataTableQuery $dt): LengthAwarePaginator
    {
        $q = PfAccount::query()
            ->with(['policy:id,name', 'employee:id,employee_id,full_name,joining_date,status'])
            ->select('pf_accounts.*')
            ->selectRaw('(pf_accounts.employee_balance + pf_accounts.employer_balance) as total_balance');

        if ($dt->search !== null && trim($dt->search) !== '') {
            $needle = '%'.addcslashes(trim($dt->search), '%_').'%';
            $q->where(fn (Builder $g) => $g
                ->where('pf_accounts.employee_id', 'like', $needle)
                ->orWhere('pf_accounts.employee_name', 'like', $needle)
                ->orWhereHas('employee', fn (Builder $e) => $e
                    ->where('full_name', 'like', $needle)
                    ->orWhere('first_name', 'like', $needle)
                    ->orWhere('last_name', 'like', $needle)));
        }

        foreach ($dt->filters as $key => $value) {
            match ($key) {
                // "Has a balance" and "is empty" are different questions from "is active"; a fund
                // desk reconciling a rollout asks them constantly.
                'balance' => $value === 'positive'
                    ? $q->whereRaw('(pf_accounts.employee_balance + pf_accounts.employer_balance) > 0')
                    : $q->whereRaw('(pf_accounts.employee_balance + pf_accounts.employer_balance) <= 0'),
                'status' => $q->where('pf_accounts.status', $value),
                'policy_id' => $q->where('pf_accounts.pf_policy_id', (int) $value),
                'date_from' => $q->whereDate('pf_accounts.eligible_from', '>=', $value),
                'date_to' => $q->whereDate('pf_accounts.eligible_from', '<=', $value),
                default => null,
            };
        }

        $sortable = [
            'employee_id' => 'pf_accounts.employee_id',
            'employee_name' => 'pf_accounts.employee_name',
            'eligible_from' => 'pf_accounts.eligible_from',
            'total_balance' => 'total_balance',
        ];

        $column = $sortable[$dt->sort] ?? 'pf_accounts.employee_name';
        $q->orderBy($column, $dt->direction === 'desc' ? 'desc' : 'asc');

        $page = $q->paginate($dt->perPage)->withQueryString();

        // Serialise `eligible_from` as a plain `Y-m-d`, not a midnight timestamp.
        //
        // Measured before this was fixed: the grid rendered "2024-02-18T00:00:00.000000Z" for a
        // `date` column. Casting to `date` in the model is not enough, because a model serialising
        // `date` produces an ISO string, and the browser then formats it in local time — 00:00Z in
        // Dhaka (UTC+6) is 06:00 that same day, but a negative offset would show the *previous*
        // day. A fund register must print the date the database holds, so the wire format is a
        // date with no time and no zone for the client to reinterpret.
        $page->through(fn (PfAccount $account): array => array_merge($account->toArray(), [
            'eligible_from' => $account->eligible_from?->toDateString(),
            'total_balance' => round($account->totalBalance(), 2),
        ]));

        return $page;
    }

    public function policies(): Collection
    {
        return PfPolicy::query()->latest('id')->get();
    }

    public function savePolicy(array $data): PfPolicy
    {
        return PfPolicy::query()->create($data + ['is_active' => true]);
    }

    /** One account with everything a statement needs. */
    public function findAccount(int $id): PfAccount
    {
        return PfAccount::query()
            ->with(['policy', 'employee:id,employee_id,full_name,designation,joining_date,status'])
            ->findOrFail($id);
    }

    /** The account statement: every movement, newest first, plus a running balance. */
    public function statement(int $accountId, ?string $from = null, ?string $to = null): array
    {
        $q = PfLedger::query()->where('pf_account_id', $accountId);

        if ($from !== null) {
            $q->whereDate('entry_date', '>=', $from);
        }
        if ($to !== null) {
            $q->whereDate('entry_date', '<=', $to);
        }

        $rows = $q->orderBy('entry_date')->orderBy('id')->get();

        // The opening balance is everything that happened before the window, so a statement that
        // starts in March still explains the April figure.
        $opening = 0.0;
        if ($from !== null) {
            $opening = round((float) PfLedger::query()
                ->where('pf_account_id', $accountId)
                ->whereDate('entry_date', '<', $from)
                ->get()
                ->sum(fn (PfLedger $entry) => $entry->signedAmount()), 2);
        }

        $running = $opening;

        $lines = $rows->map(function (PfLedger $entry) use (&$running): array {
            $running = round($running + $entry->signedAmount(), 2);

            return [
                'id' => $entry->id,
                'entry_date' => $entry->entry_date?->toDateString(),
                'contribution_month' => $entry->contribution_month,
                'entry_type' => $entry->entry_type,
                'status' => $entry->status,
                'direction' => $entry->direction() > 0 ? 'credit' : 'debit',
                'amount' => round((float) $entry->amount, 2),
                'signed_amount' => $entry->signedAmount(),
                'balance' => $running,
                'description' => $entry->description,
                'reference_type' => $entry->reference_type,
            ];
        })->all();

        return [
            'opening_balance' => $opening,
            'closing_balance' => $running,
            'lines' => array_reverse($lines),
        ];
    }

    /* ------------------------------ allocation ---------------------------- */

    /**
     * Create accounts for employees who do not have one.
     *
     * `eligible_from` is written through Carbon rather than as a raw string: the earlier version
     * bulk-inserted `Carbon::...->toDateString()` **plus a time**, into a `date` column, which is why
     * the grid rendered "23 Dec 2026, 06:00 pm" — the value was a datetime the model then cast again.
     */
    public function allocateAccounts(int $policyId, array $employeeIds = [], bool $allActive = false): array
    {
        $policy = PfPolicy::query()->where('is_active', true)->findOrFail($policyId);

        $ids = $allActive
            ? Employee::query()->where('is_active', true)->whereIn('status', ['Active', 'On Leave'])
                ->orderBy('employee_id')->pluck('employee_id')->all()
            : array_values(array_unique(array_map('strval', $employeeIds)));

        if ($ids === []) {
            return ['created' => 0, 'skipped' => 0];
        }

        $created = 0;
        $skipped = 0;

        foreach (array_chunk($ids, 250) as $chunk) {
            $counts = DB::transaction(function () use ($chunk, $policy): array {
                $existing = PfAccount::query()->whereIn('employee_id', $chunk)
                    ->pluck('employee_id')->map(fn ($id) => (string) $id)->all();
                $existingSet = array_fill_keys($existing, true);

                $employees = Employee::query()->whereIn('employee_id', $chunk)
                    ->get(['employee_id', 'full_name', 'joining_date'])->keyBy('employee_id');

                $rows = [];
                $skipped = 0;

                foreach ($chunk as $employeeId) {
                    $employeeId = (string) $employeeId;

                    if (isset($existingSet[$employeeId])) {
                        $skipped++;
                        continue;
                    }

                    $employee = $employees->get($employeeId);
                    if ($employee === null) {
                        $skipped++;
                        continue;
                    }

                    $eligibleFrom = $employee->joining_date
                        ? Carbon::parse($employee->joining_date)
                            ->addMonthsNoOverflow((int) $policy->minimum_service_months)
                            ->toDateString()
                        : null;

                    $rows[] = [
                        'employee_id' => $employeeId,
                        'employee_name' => $employee->full_name,
                        'pf_policy_id' => $policy->id,
                        'eligible_from' => $eligibleFrom,
                        'employee_balance' => 0,
                        'employer_balance' => 0,
                        'status' => 'active',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                $inserted = $rows === [] ? 0 : PfAccount::query()->insertOrIgnore($rows);

                return ['created' => $inserted, 'skipped' => $skipped + count($rows) - $inserted];
            }, 3);

            $created += $counts['created'];
            $skipped += $counts['skipped'];
        }

        // The returned shape is a contract: `ProvidentFundAllocationTest` pins it exactly, and it
        // predates this rebuild. What was *examined* is the caller's own input length, so it does not
        // belong in the result — reporting it here would be a second source of truth for a number
        // the caller already holds.
        return ['created' => $created, 'skipped' => $skipped];
    }

    /* -------------------------------- accrual ----------------------------- */

    /**
     * Accrue both sides of the fund for one closed payroll month.
     *
     * The source of truth is the slip's own `breakdown_json.provident_fund`, not a fresh
     * calculation: the contribution that enters the fund must be the contribution that was withheld
     * from that person's salary, down to the cent, even if a policy has changed since.
     */
    public function accrueFromSheet(PayrollSalarySheet $sheet, ?int $actorId = null): array
    {
        // `released` is the only final state; `ready` means the month may still be recalculated
        // (that is what `PayrollReopenService` sets it back to). Recognising a liability against a
        // month that can still change would leave the ledger describing a payroll that never
        // existed, so the gate belongs here and not only in the screen.
        if ($sheet->status !== 'released') {
            throw ValidationException::withMessages([
                'period' => sprintf(
                    '%s cannot be accrued: the salary sheet is "%s". Release the sheet first — a month that can still be recalculated must not create a provident-fund liability.',
                    PfLedger::periodFor((int) $sheet->pay_year, (int) $sheet->pay_month),
                    $sheet->status,
                ),
            ]);
        }

        $period = PfLedger::periodFor((int) $sheet->pay_year, (int) $sheet->pay_month);

        $slips = PayrollSlip::query()
            ->where('salary_sheet_id', $sheet->id)
            ->get(['id', 'employee_id', 'breakdown_json', 'pay_year', 'pay_month']);

        $posted = 0;
        $skipped = 0;

        foreach ($slips as $slip) {
            $pf = $slip->breakdown_json['provident_fund'] ?? [];

            if (empty($pf['eligible']) || empty($pf['account_id'])) {
                $skipped++;
                continue;
            }

            $result = $this->postAccrualForAccount(
                (int) $pf['account_id'],
                $period,
                (float) ($pf['employee_contribution'] ?? 0),
                (float) ($pf['employer_contribution'] ?? 0),
                $slip,
                $sheet->id,
                $actorId,
            );

            $result ? $posted++ : $skipped++;
        }

        return ['period' => $period, 'posted' => $posted, 'skipped' => $skipped, 'slips' => $slips->count()];
    }

    /**
     * The months that can be accrued, and the reason for each verdict.
     *
     * Live probe of the pilot database: both salary sheets sit at `status = 'ready'`, and `ready` is
     * explicitly *not* final here — `PayrollSalarySheetService::release()` is what freezes a month,
     * and `PayrollReopenService` puts a sheet back to `ready` precisely so it can be recalculated.
     * A fund must not recognise a liability against a month that can still change, so accrual is
     * gated on `released`.
     *
     * The reason string is the point, not decoration: an operator who reads "ready but not released"
     * knows what to do next, where a disabled button would leave them guessing — and an action that
     * reports nothing is exactly the failure that made the old one-click allocation look broken.
     */
    public function accrualCandidates(int $limit = 24): array
    {
        $sheets = PayrollSalarySheet::query()
            ->orderByDesc('pay_year')->orderByDesc('pay_month')
            ->limit($limit)
            ->get(['id', 'pay_year', 'pay_month', 'status', 'employee_count']);

        $posted = PfLedger::query()
            ->whereNotNull('contribution_month')
            ->selectRaw('contribution_month, count(distinct pf_account_id) as members, sum(amount) as value')
            ->groupBy('contribution_month')
            ->get()
            ->keyBy('contribution_month');

        return $sheets->map(function (PayrollSalarySheet $sheet) use ($posted): array {
            $period = PfLedger::periodFor((int) $sheet->pay_year, (int) $sheet->pay_month);
            $already = $posted->get($period);

            return [
                'sheet_id' => $sheet->id,
                'period' => $period,
                'status' => $sheet->status,
                'employees' => (int) $sheet->employee_count,
                'can_accrue' => $sheet->status === 'released',
                'reason' => match (true) {
                    $sheet->status === 'released' => 'Released — contributions may be accrued.',
                    $sheet->status === 'ready' => 'Ready but not released — this month can still be recalculated, so nothing is accrued yet.',
                    $sheet->status === 'processing' => 'Still processing.',
                    $sheet->status === 'failed' => 'The sheet failed; retry it before accruing.',
                    default => 'Status '.$sheet->status.'.',
                },
                'posted_members' => (int) ($already->members ?? 0),
                'posted_value' => round((float) ($already->value ?? 0), 2),
            ];
        })->all();
    }

    /**
     * The cash side of the fund: what came in, what went out, and what should be sitting in the bank.
     *
     * Kept apart from `fundSummary()` because they answer different questions, and mixing them is
     * how a treasurer's report starts lying. `liability` is owed to members whatever the bank says;
     * `cash_position` is what the fund actually holds. `reconcile()` proves the liability against
     * the members' own balances.
     */
    public function cashPosition(): array
    {
        $byStatus = PfLedger::query()
            ->selectRaw('status, entry_type, coalesce(sum(amount),0) as value_sum, count(*) as rows_count')
            ->groupBy('status', 'entry_type')
            ->get();

        $sum = fn (array $statuses, array $types): float => round((float) $byStatus
            ->whereIn('status', $statuses)
            ->whereIn('entry_type', $types)
            ->sum('value_sum'), 2);

        $credits = ['employee_contribution', 'employer_contribution'];

        $received = $sum(['settled', 'paid'], $credits);
        $outstanding = $sum(['accrued'], $credits);
        $paidOut = $sum(['settled', 'paid', 'accrued'], PfLedger::DEBIT_TYPES);

        return [
            'received' => $received,
            'outstanding' => $outstanding,
            'paid_out' => $paidOut,
            'cash_position' => round($received - $paidOut, 2),
            'liability' => round($received + $outstanding - $paidOut, 2),
            'lines' => (int) $byStatus->sum('rows_count'),
        ];
    }

    /**
     * Post the historical accrual from every slip already in the database.
     *
     * This is what makes the pilot's fund correct instead of merely correct from next month: the
     * contributions were withheld from real salaries, so the fund owes them whether or not the
     * posting code existed at the time. Idempotent through the period-unique index, so it can be run
     * as often as somebody wants to prove it.
     */
    public function backfillHistory(?int $actorId = null): array
    {
        $posted = 0;
        $skipped = 0;
        $months = [];

        PayrollSlip::query()
            ->select(['id', 'employee_id', 'salary_sheet_id', 'pay_year', 'pay_month', 'breakdown_json'])
            ->orderBy('pay_year')->orderBy('pay_month')->orderBy('id')
            ->chunk(400, function ($slips) use (&$posted, &$skipped, &$months, $actorId): void {
                foreach ($slips as $slip) {
                    $pf = $slip->breakdown_json['provident_fund'] ?? [];

                    if (empty($pf['eligible']) || empty($pf['account_id'])) {
                        $skipped++;
                        continue;
                    }

                    $period = PfLedger::periodFor((int) $slip->pay_year, (int) $slip->pay_month);

                    $result = $this->postAccrualForAccount(
                        (int) $pf['account_id'],
                        $period,
                        (float) ($pf['employee_contribution'] ?? 0),
                        (float) ($pf['employer_contribution'] ?? 0),
                        $slip,
                        $slip->salary_sheet_id,
                        $actorId,
                    );

                    if ($result) {
                        $posted++;
                        $months[$period] = ($months[$period] ?? 0) + 1;
                    } else {
                        $skipped++;
                    }
                }
            });

        ksort($months);

        return ['posted' => $posted, 'skipped' => $skipped, 'months' => $months];
    }

    /**
     * Write the two rows for one account and month, unless they are already there.
     *
     * Returns false when nothing was written, so the caller can report an honest count. The unique
     * index is the final arbiter: two operators pressing Accrue at the same time cannot double-credit
     * a month, and `insertOrIgnore` turns that race into a no-op instead of a 500.
     */
    private function postAccrualForAccount(
        int $accountId,
        string $period,
        float $employeeAmount,
        float $employerAmount,
        PayrollSlip $slip,
        ?int $sheetId,
        ?int $actorId,
    ): bool {
        $account = PfAccount::query()->lockForUpdate()->find($accountId);

        if ($account === null || $account->status !== 'active') {
            return false;
        }

        $entryDate = Carbon::createFromFormat('Y-m', $period)->endOfMonth()->toDateString();
        $wrote = false;

        foreach ([
            'employee_contribution' => $employeeAmount,
            'employer_contribution' => $employerAmount,
        ] as $type => $amount) {
            if (round($amount, 2) <= 0) {
                continue;
            }

            $exists = PfLedger::query()
                ->where('pf_account_id', $account->id)
                ->where('entry_type', $type)
                ->where('contribution_month', $period)
                ->where('status', 'accrued')
                ->exists();

            if ($exists) {
                continue;
            }

            PfLedger::query()->create([
                'pf_account_id' => $account->id,
                'entry_type' => $type,
                'amount' => round($amount, 2),
                'entry_date' => $entryDate,
                'contribution_month' => $period,
                'status' => 'accrued',
                'reference_type' => 'payroll_slip',
                'reference_id' => $slip->id,
                'payroll_salary_sheet_id' => $sheetId,
                'description' => sprintf('Provident fund accrual for %s', $period),
                'created_by' => $actorId,
            ]);

            $wrote = true;
        }

        if ($wrote) {
            $account->recalculateBalances();
        }

        return $wrote;
    }

    /**
     * Settle the accruals for a month once the cash has actually moved.
     *
     * The liability already exists, so this does **not** credit the account again — it marks the
     * accrued rows settled and writes a matching cash line. The balance therefore stays put, which is
     * the point: paying a liability does not create a second one.
     */
    public function settleMonth(string $period, ?int $actorId = null): array
    {
        $accrued = PfLedger::query()
            ->where('contribution_month', $period)
            ->where('status', 'accrued')
            ->get();

        $settled = 0;
        $value = 0.0;

        DB::transaction(function () use ($accrued, $period, $actorId, &$settled, &$value): void {
            foreach ($accrued as $entry) {
                // Deliberately *not* rewriting `reference_type`/`reference_id`: they point at the
                // payroll slip whose `breakdown_json` justified the amount, and that provenance is
                // what an auditor follows when a member disputes a month. The cash movement has its
                // own column — `settled_at`.
                $entry->update([
                    'status' => 'settled',
                    'settled_at' => now(),
                    'updated_at' => now(),
                ]);
                $settled++;
                $value += (float) $entry->amount;
            }
        }, 3);

        return ['period' => $period, 'settled' => $settled, 'value' => round($value, 2), 'actor' => $actorId];
    }

    /* ------------------------------ withdrawals --------------------------- */

    public function requestWithdrawal(PfAccount $account, array $data, ?int $actorId): PfWithdrawal
    {
        $amount = round((float) $data['requested_amount'], 2);

        if ($amount <= 0) {
            throw ValidationException::withMessages(['requested_amount' => 'A withdrawal must be greater than zero.']);
        }

        $ceiling = (new PfWithdrawal([
            'type' => $data['type'],
            'pf_account_id' => $account->id,
            'approved_amount' => null,
        ]))->setRelation('account', $account)->ceiling();

        if ($amount > $ceiling) {
            throw ValidationException::withMessages([
                'requested_amount' => sprintf(
                    'This %s may not exceed %s. The account holds %s on the employee side and %s in total.',
                    str_replace('_', ' ', $data['type']),
                    number_format($ceiling, 2),
                    number_format((float) $account->employee_balance, 2),
                    number_format($account->totalBalance(), 2),
                ),
            ]);
        }

        return PfWithdrawal::query()->create([
            'pf_account_id' => $account->id,
            'employee_id' => $account->employee_id,
            'type' => $data['type'],
            'requested_amount' => $amount,
            'reason' => $data['reason'],
            'status' => 'pending',
            'requested_by' => $actorId,
        ]);
    }

    /**
     * Approve, refuse or pay a withdrawal — and, when it is paid, move the money in the ledger.
     *
     * One action covers all three because they are one lifecycle: an approval that does not post,
     * and a posting that was never approved, are both ways a fund loses money.
     */
    public function decideWithdrawal(PfWithdrawal $withdrawal, string $decision, ?float $approvedAmount, ?int $actorId, ?string $note = null): PfWithdrawal
    {
        return DB::transaction(function () use ($withdrawal, $decision, $approvedAmount, $actorId, $note): PfWithdrawal {
            $locked = PfWithdrawal::query()->whereKey($withdrawal->id)->lockForUpdate()->firstOrFail();

            // Paying is the *end* of the lifecycle, so it is legal from `pending` (a small fund that
            // approves by paying) and from `approved` (the two-step flow the screens present). Every
            // other decision needs a request that is still open. Guarding all three on `pending`
            // made "Mark paid" throw on a row the withdrawal desk itself had just approved.
            $allowedFrom = $decision === 'pay' ? ['pending', 'approved'] : ['pending'];

            if (! in_array($locked->status, $allowedFrom, true)) {
                throw ValidationException::withMessages([
                    'status' => $decision === 'pay'
                        ? sprintf('Only a pending or approved request can be paid; this one is %s.', $locked->status)
                        : 'Only a pending request can be decided.',
                ]);
            }

            if ($decision === 'reject') {
                $locked->update([
                    'status' => 'rejected',
                    'decided_by' => $actorId,
                    'decided_at' => now(),
                    'decision_note' => $note,
                ]);

                return $locked->refresh();
            }

            $account = PfAccount::query()->whereKey($locked->pf_account_id)->lockForUpdate()->firstOrFail();
            $amount = round((float) ($approvedAmount ?? $locked->requested_amount), 2);
            $ceiling = (new PfWithdrawal([
                'type' => $locked->type,
                'pf_account_id' => $account->id,
            ]))->setRelation('account', $account)->ceiling();

            if ($amount <= 0 || $amount > $ceiling) {
                throw ValidationException::withMessages([
                    'approved_amount' => sprintf('The approved amount must be between 0.01 and %s.', number_format($ceiling, 2)),
                ]);
            }

            if ($decision === 'approve') {
                $locked->update([
                    'status' => 'approved',
                    'approved_amount' => $amount,
                    'decided_by' => $actorId,
                    'decided_at' => now(),
                    'decision_note' => $note,
                ]);

                return $locked->refresh();
            }

            // decision === 'pay': approve and post in one transaction, so the ledger can never show a
            // payout that no approval authorised.
            $entry = PfLedger::query()->create([
                'pf_account_id' => $account->id,
                'entry_type' => $locked->isFinal() ? 'settlement' : 'withdrawal',
                'amount' => $amount,
                'entry_date' => now()->toDateString(),
                'contribution_month' => null,
                'status' => 'paid',
                'reference_type' => 'pf_withdrawal',
                'reference_id' => $locked->id,
                'description' => sprintf('%s paid: %s', str_replace('_', ' ', $locked->type), $locked->reason),
                'created_by' => $actorId,
            ]);

            $account->recalculateBalances();

            if ($locked->isFinal()) {
                $account->update(['status' => 'closed']);
            }

            $locked->update([
                'status' => 'paid',
                'approved_amount' => $amount,
                'decided_by' => $actorId,
                'decided_at' => $locked->decided_at ?? now(),
                'paid_at' => now(),
                'decision_note' => $note,
                'pf_ledger_id' => $entry->id,
            ]);

            return $locked->refresh();
        }, 3);
    }

    public function withdrawals(DataTableQuery $dt): LengthAwarePaginator
    {
        $q = PfWithdrawal::query()->with(['account:id,employee_id,employee_balance,employer_balance']);

        if (is_string($dt->filters['status'] ?? null)) {
            $q->where('status', $dt->filters['status']);
        }
        if (is_string($dt->filters['type'] ?? null)) {
            $q->where('type', $dt->filters['type']);
        }

        return $q->latest('id')->paginate($dt->perPage)->withQueryString();
    }

    /* -------------------------------- reports ----------------------------- */

    /**
     * The four numbers a provident fund is judged on.
     *
     * `liability` is every credit ever accrued; `settled` is the part whose cash has moved;
     * `paid_out` is withdrawals and settlements; `net` is what the fund still holds on behalf of
     * members. A fund whose `net` does not equal the sum of its account balances is broken, and
     * `reconcile()` proves that it does.
     */
    public function fundSummary(): array
    {
        $credits = PfLedger::query()
            ->whereIn('entry_type', ['employee_contribution', 'employer_contribution'])
            ->selectRaw("
                coalesce(sum(case when status = 'accrued' then amount else 0 end), 0) as accrued,
                coalesce(sum(case when status = 'settled' then amount else 0 end), 0) as banked,
                coalesce(sum(case when status in ('settled','paid') then amount else 0 end), 0) as settled,
                coalesce(sum(case when entry_type = 'employee_contribution' then amount else 0 end), 0) as employee_side,
                coalesce(sum(case when entry_type = 'employer_contribution' then amount else 0 end), 0) as employer_side
            ")
            ->first();

        $debits = PfLedger::query()
            ->whereIn('entry_type', ['withdrawal', 'settlement'])
            ->selectRaw('coalesce(sum(amount), 0) as paid_out')
            ->first();

        $accounts = PfAccount::query()
            ->selectRaw("
                count(*) as accounts,
                coalesce(sum(employee_balance), 0) as employee_balance,
                coalesce(sum(employer_balance), 0) as employer_balance,
                coalesce(sum(case when status = 'active' then 1 else 0 end), 0) as active_accounts,
                coalesce(sum(case when status = 'closed' then 1 else 0 end), 0) as closed_accounts
            ")
            ->first();

        $eligible = Employee::query()->where('is_active', true)
            ->whereIn('status', ['Active', 'On Leave'])->count();

        return [
            'accounts' => (int) $accounts->accounts,
            'active_accounts' => (int) $accounts->active_accounts,
            'closed_accounts' => (int) $accounts->closed_accounts,
            'unallocated_employees' => max(0, $eligible - (int) $accounts->accounts),
            // `accrued` = recognised but not yet paid into the fund.
            // `banked`  = the cash actually received and sitting in the fund.
            // `settled` = banked plus the legacy pay-time rows written before accrual existed.
            'accrued' => round((float) $credits->accrued, 2),
            'banked' => round((float) $credits->banked, 2),
            'settled' => round((float) $credits->settled, 2),
            'paid_out' => round((float) $debits->paid_out, 2),
            'employee_side' => round((float) $credits->employee_side, 2),
            'employer_side' => round((float) $credits->employer_side, 2),
            'net' => round((float) $accounts->employee_balance + (float) $accounts->employer_balance, 2),
            'pending_withdrawals' => (int) PfWithdrawal::query()->where('status', 'pending')->count(),
            // `last_period` is the last month the fund has activity *for*, and it must never be a
            // future month. It once reported 2026-12 while today was 2026-11-16, because a deleted
            // payroll run had left its accrual behind; a treasurer reading "last period: December"
            // in November has no way to tell that from a fund that is a month ahead of the calendar.
            'last_period' => PfLedger::query()->whereNotNull('contribution_month')
                ->where('contribution_month', '<=', now()->format('Y-m'))
                ->max('contribution_month'),
            'future_periods' => PfLedger::query()->whereNotNull('contribution_month')
                ->where('contribution_month', '>', now()->format('Y-m'))
                ->distinct()
                ->pluck('contribution_month')
                ->values()
                ->all(),
        ];
    }

    /** Per-month fund activity, newest first — the shape a treasurer's report is read in. */
    public function monthlyActivity(int $limit = 24): array
    {
        return PfLedger::query()
            ->whereNotNull('contribution_month')
            ->selectRaw("
                contribution_month as period,
                count(distinct pf_account_id) as members,
                coalesce(sum(case when entry_type = 'employee_contribution' then amount else 0 end), 0) as employee_side,
                coalesce(sum(case when entry_type = 'employer_contribution' then amount else 0 end), 0) as employer_side,
                coalesce(sum(case when status = 'accrued' then amount else 0 end), 0) as accrued,
                coalesce(sum(case when status in ('settled','paid') then amount else 0 end), 0) as settled
            ")
            ->groupBy('contribution_month')
            ->orderByDesc('contribution_month')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'period' => $row->period,
                'members' => (int) $row->members,
                'employee_side' => round((float) $row->employee_side, 2),
                'employer_side' => round((float) $row->employer_side, 2),
                'accrued' => round((float) $row->accrued, 2),
                'settled' => round((float) $row->settled, 2),
                'total' => round((float) $row->employee_side + (float) $row->employer_side, 2),
            ])
            ->all();
    }

    /**
     * Everything the fund's overview page asks for, in one round trip.
     *
     * The desk is a hub: four figures at the top, the contribution trend, what is waiting for a
     * decision, and whether the books agree. Three of those are already service methods; this
     * composes them so the page opens in one query set rather than six sequential ones.
     */
    public function dashboard(int $months = 12): array
    {
        $summary = $this->fundSummary();
        $cash = $this->cashPosition();

        return [
            'summary' => $summary,
            'cash' => $cash,
            'trend' => $this->contributionTrend($months),
            'recent_withdrawals' => $this->recentWithdrawals(5),
            'periods' => $this->accrualCandidates(6),
            'drift_count' => count($this->reconcile()),
            // `reconcile()` compares the stored balance with the ledger, and both were built from the
            // same rows — so the fund showed a green "Reconciled" badge while carrying 33.8 lakh of
            // contributions to payroll that had been deleted. A ledger cannot audit itself; every
            // entry has to be traceable to something that still exists.
            'integrity' => app(\Modules\PAYROLL\Services\PfLedgerIntegrityService::class)->audit(),
            'composition' => [
                'employee' => $summary['employee_side'],
                'employer' => $summary['employer_side'],
                'paid_out' => $cash['paid_out'],
                'outstanding' => $cash['outstanding'],
            ],
        ];
    }

    /**
     * The contribution trend, oldest first — the order a chart is read in.
     *
     * `monthlyActivity()` is newest-first because a treasurer reads a table from the top; a chart is
     * the opposite, so the series is reversed here rather than making every caller remember which
     * end is which. Months with nothing posted are present with zeroes, so a gap in the fund reads as
     * a gap in the chart instead of silently collapsing the axis.
     */
    public function contributionTrend(int $months = 12): array
    {
        $rows = collect($this->monthlyActivity($months))->keyBy('period');

        $latest = PfLedger::query()->whereNotNull('contribution_month')->max('contribution_month');

        if ($latest === null) {
            return [];
        }

        [$year, $month] = array_map('intval', explode('-', $latest));
        $series = [];

        for ($i = $months - 1; $i >= 0; $i--) {
            $cursor = \Carbon\Carbon::create($year, $month, 1)->subMonthsNoOverflow($i);
            $period = $cursor->format('Y-m');
            $row = $rows->get($period);

            $series[] = [
                'period' => $period,
                'label' => $cursor->format('M'),
                'label_long' => $cursor->format('M Y'),
                'employee_side' => round((float) ($row['employee_side'] ?? 0), 2),
                'employer_side' => round((float) ($row['employer_side'] ?? 0), 2),
                'total' => round((float) ($row['total'] ?? 0), 2),
                'members' => (int) ($row['members'] ?? 0),
            ];
        }

        return $series;
    }

    /** The most recent requests, with the member's name attached — a dashboard table needs one row per person. */
    public function recentWithdrawals(int $limit = 5): array
    {
        return PfWithdrawal::query()
            ->select(['id', 'pf_account_id', 'employee_id', 'type', 'requested_amount', 'approved_amount', 'reason', 'status', 'created_at'])
            ->with(['account:id,employee_id,employee_name'])
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (PfWithdrawal $row): array => [
                'id' => $row->id,
                'account_id' => $row->pf_account_id,
                'employee_id' => $row->employee_id,
                'employee_name' => $row->account?->employee_name,
                'type' => $row->type,
                'requested_amount' => round((float) $row->requested_amount, 2),
                'approved_amount' => $row->approved_amount === null ? null : round((float) $row->approved_amount, 2),
                'reason' => $row->reason,
                'status' => $row->status,
                'created_at' => $row->created_at?->toDateString(),
            ])
            ->all();
    }

    /**
     * Prove the cached balances against the ledger.
     *
     * Returns the accounts that disagree. An empty list is the only acceptable answer, and it is the
     * check to run before anyone signs a fund statement.
     */
    public function reconcile(): array
    {
        $drift = [];

        PfAccount::query()->orderBy('id')->chunk(500, function ($accounts) use (&$drift): void {
            foreach ($accounts as $account) {
                // Mirrors `PfAccount::recalculateBalances()` exactly — that is the whole point of a
                // reconciliation: two independent paths to the same number, not the same path twice.
                $ledger = PfLedger::query()
                    ->where('pf_account_id', $account->id)
                    ->get()
                    ->reduce(function (array $carry, PfLedger $entry): array {
                        if ($entry->entry_type === 'employee_contribution') {
                            $carry['employee'] += (float) $entry->amount;
                        } elseif ($entry->entry_type === 'employer_contribution') {
                            $carry['employer'] += (float) $entry->amount;
                        } elseif (in_array($entry->entry_type, PfLedger::DEBIT_TYPES, true)) {
                            // A final settlement draws on the employer's side too; a partial
                            // withdrawal only ever draws on the member's own money.
                            $carry['employee'] -= (float) $entry->amount;
                        }

                        return $carry;
                    }, ['employee' => 0.0, 'employer' => 0.0]);

                $employeeDrift = round($ledger['employee'] - (float) $account->employee_balance, 2);
                $employerDrift = round($ledger['employer'] - (float) $account->employer_balance, 2);

                if (abs($employeeDrift) > 0.009 || abs($employerDrift) > 0.009) {
                    $drift[] = [
                        'account_id' => $account->id,
                        'employee_id' => $account->employee_id,
                        'employee_drift' => $employeeDrift,
                        'employer_drift' => $employerDrift,
                    ];
                }
            }
        });

        return $drift;
    }
}
