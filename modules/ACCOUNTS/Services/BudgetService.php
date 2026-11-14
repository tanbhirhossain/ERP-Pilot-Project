<?php

namespace Modules\ACCOUNTS\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\ACCOUNTS\Models\Account;
use Modules\ACCOUNTS\Models\Budget;
use Modules\ACCOUNTS\Models\BudgetLine;

/**
 * F14 — the budget engine: line management and the budget-vs-actual variance report.
 *
 * One principle drives the whole class: **actuals are never stored.** Every "actual" figure is read
 * live from `acc_account_ledger` (which only ever holds posted entries), so a variance report cannot
 * drift out of step with the books — the two are reconciled by construction, not by a nightly sync.
 *
 * Like `VatReturnService` and `DepreciationService`, all arithmetic is done in integer paise so a
 * comparison is exact and re-runs identically. The one subtlety worth naming is **sign**: a budget is
 * planned in the account's own natural direction (planned spend for a debit account, planned income
 * for a credit one), and the actual is computed the same way `LedgerService::trialBalance()` does
 * (debit−credit for a debit account, credit−debit otherwise). Because both sides use the same
 * convention, `budget − actual` is a meaningful variance for an expense *and* for income without any
 * per-row sign juggling.
 */
class BudgetService
{
    private function cents(float $v): int
    {
        return (int) round($v * 100);
    }

    private function dollars(int $cents): float
    {
        return $cents / 100;
    }

    private function assertPeriod(string $period): void
    {
        if (! preg_match('/^\d{4}-\d{2}$/', $period)) {
            abort(422, 'Period must be YYYY-MM.');
        }
    }

    /**
     * Create or update a single (budget, account, period) line. Writing 0 deletes the line, so the
     * grid never accumulates empty rows, and an edit to the same account+period updates in place
     * (the unique key) rather than duplicating.
     */
    public function upsertLine(Budget $budget, int $accountId, string $period, float $amount, ?string $notes = null): ?BudgetLine
    {
        $this->assertPeriod($period);

        if ($budget->status === 'locked') {
            abort(422, 'This budget is locked and cannot be edited.');
        }

        $account = Account::find($accountId);
        if (! $account || $account->is_group || ! $account->is_posting) {
            abort(422, 'Budgets must target a posting account (not a group header).');
        }

        // The month must sit inside the budget's fiscal year, or the plan and the actuals would be
        // measured over different windows.
        $fy = $budget->fiscalYear;
        if ($fy) {
            $monthStart = Carbon::createFromFormat('Y-m-d', $period.'-01')->startOfDay();
            if ($monthStart->lt($fy->start_date->copy()->startOfDay()) || $monthStart->gt($fy->end_date->copy()->startOfDay())) {
                abort(422, "Period {$period} falls outside the budget's fiscal year ({$fy->start_date->toDateString()} … {$fy->end_date->toDateString()}).");
            }
        }

        $existing = BudgetLine::where('budget_id', $budget->id)
            ->where('account_id', $accountId)->where('period', $period)->first();

        if ($this->cents($amount) <= 0) {
            $existing?->delete();

            return null;
        }

        return BudgetLine::updateOrCreate(
            ['budget_id' => $budget->id, 'account_id' => $accountId, 'period' => $period],
            ['amount' => $this->dollars($this->cents($amount)), 'notes' => $notes],
        );
    }

    /**
     * Nature-signed posted movement per account for one calendar month — the exact same convention as
     * `LedgerService::trialBalance()`, just bounded to the month and to the accounts asked about.
     *
     * @param  iterable<int>  $accountIds
     * @return array<int,float> account_id => signed amount
     */
    public function actuals(iterable $accountIds, string $period): array
    {
        $this->assertPeriod($period);
        $ids = is_array($accountIds) ? $accountIds : iterator_to_array($accountIds);
        if ($ids === []) {
            return [];
        }

        $start = Carbon::createFromFormat('Y-m-d', $period.'-01')->startOfMonth()->toDateString();
        $end = Carbon::createFromFormat('Y-m-d', $period.'-01')->endOfMonth()->toDateString();

        return DB::table('acc_account_ledger as l')
            ->join('acc_accounts as a', 'a.id', '=', 'l.account_id')
            ->whereIn('l.account_id', $ids)
            ->whereBetween('l.posting_date', [$start, $end])
            ->groupBy('l.account_id', 'a.nature')
            ->selectRaw('l.account_id as account_id, a.nature as nature, SUM(l.debit) as d, SUM(l.credit) as c')
            ->get()
            ->mapWithKeys(function ($row): array {
                $net = $row->nature === 'debit'
                    ? $this->cents((float) $row->d) - $this->cents((float) $row->c)
                    : $this->cents((float) $row->c) - $this->cents((float) $row->d);

                return [(int) $row->account_id => $this->dollars($net)];
            })
            ->all();
    }

    /**
     * The variance report for a budget, aggregated per account (across the given month, or every
     * month the budget has lines for when no period is passed).
     *
     * @return array{budget: array, rows: list<array>, periods: list<string>, unfavorable: list<array>}
     */
    public function report(Budget $budget, ?string $period = null): array
    {
        if ($period !== null) {
            $this->assertPeriod($period);
        }

        $lines = $budget->lines()
            ->with('account:id,code,name,nature')
            ->when($period, fn ($q) => $q->where('period', $period))
            ->get();

        $periods = $lines->pluck('period')->unique()->sort()->values()->all();

        // Actual per account, summed over the periods in play — one ledger query per month.
        $accountIds = $lines->pluck('account_id')->unique()->all();
        $actualCents = array_fill_keys($accountIds, 0);
        foreach ($periods as $p) {
            foreach ($this->actuals($accountIds, $p) as $aid => $amt) {
                $actualCents[$aid] += $this->cents($amt);
            }
        }

        // Budget per account, and the account metadata, from the lines.
        $budgetCents = [];
        $meta = [];
        foreach ($lines as $line) {
            $aid = $line->account_id;
            $budgetCents[$aid] = ($budgetCents[$aid] ?? 0) + $this->cents((float) $line->amount);
            $meta[$aid] = [
                'account_id' => $aid,
                'code' => $line->account?->code,
                'name' => $line->account?->name,
                'nature' => $line->account?->nature ?? 'debit',
            ];
        }

        $rows = [];
        $totalBudget = 0;
        $totalActual = 0;
        $unfavorable = [];

        foreach ($budgetCents as $aid => $bc) {
            $ac = $actualCents[$aid] ?? 0;
            $variance = $bc - $ac;
            // Debit accounts are favourable at-or-under plan (spend); credit accounts at-or-over.
            $favourable = $meta[$aid]['nature'] === 'credit' ? $ac >= $bc : $ac <= $bc;
            $pct = $bc > 0 ? round($ac / $bc * 100, 1) : ($ac > 0 ? 100.0 : 0.0);

            $row = [
                ...$meta[$aid],
                'budget' => $this->dollars($bc),
                'actual' => $this->dollars($ac),
                'variance' => $this->dollars($variance),
                'pct' => $pct,
                'favourable' => $favourable,
            ];
            $rows[] = $row;
            $totalBudget += $bc;
            $totalActual += $ac;
            if (! $favourable) {
                $unfavorable[] = $row;
            }
        }

        usort($rows, fn ($a, $b) => strcmp((string) $a['code'], (string) $b['code']));
        // Worst first: the most negative variance (an expense far over plan, or income far under it).
        usort($unfavorable, fn ($a, $b) => $a['variance'] <=> $b['variance']);

        return [
            'budget' => [
                'id' => $budget->id, 'name' => $budget->name, 'code' => $budget->code,
                'department' => $budget->department, 'status' => $budget->status,
                'period' => $period, 'fiscal_year' => $budget->fiscalYear?->name,
            ],
            'periods' => $periods,
            'rows' => $rows,
            'unfavorable' => $unfavorable,
            'totals' => [
                'budget' => $this->dollars($totalBudget),
                'actual' => $this->dollars($totalActual),
                'variance' => $this->dollars($totalBudget - $totalActual),
                'pct' => $totalBudget > 0 ? round($totalActual / $totalBudget * 100, 1) : 0.0,
                'overrun_count' => count($unfavorable),
            ],
        ];
    }

    /** Approve a budget: needs at least one line and an open fiscal year. */
    public function approve(Budget $budget, ?int $userId = null): Budget
    {
        if ($budget->status !== 'draft') {
            abort(422, 'Only a draft budget can be approved.');
        }
        if ($budget->lines()->count() === 0) {
            abort(422, 'Add at least one budget line before approving.');
        }
        $fy = $budget->fiscalYear;
        if ($fy && $fy->status !== 'open') {
            abort(422, 'The fiscal year is not open; a budget cannot be approved against it.');
        }

        $budget->forceFill(['status' => 'approved', 'approved_by' => $userId, 'approved_at' => now()])->save();

        return $budget->refresh();
    }

    /** Freeze an approved budget against further edits (its lines are locked by upsertLine). */
    public function lock(Budget $budget): Budget
    {
        if ($budget->status !== 'approved') {
            abort(422, 'Only an approved budget can be locked.');
        }
        $budget->forceFill(['status' => 'locked'])->save();

        return $budget->refresh();
    }

    /** Headline stats for the budget index, over approved budgets only. */
    public function summary(?string $period = null): array
    {
        $budgets = Budget::approved()->get();
        $budgeted = 0;
        foreach ($budgets as $b) {
            $q = $b->lines()->when($period, fn ($x) => $x->where('period', $period));
            $budgeted += $this->cents((float) $q->sum('amount'));
        }

        return [
            'count' => $budgets->count(),
            'budgeted' => $this->dollars($budgeted),
        ];
    }
}
