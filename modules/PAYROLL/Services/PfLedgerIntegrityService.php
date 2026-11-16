<?php

namespace Modules\PAYROLL\Services;

use Illuminate\Support\Facades\DB;
use Modules\PAYROLL\Models\PfAccount;
use Modules\PAYROLL\Models\PfLedger;

/**
 * Provident-fund ledger integrity.
 *
 * The ledger had accumulated two classes of row that describe something that never happened, and
 * `ProvidentFundService::reconcile()` could not see either of them. It recomputes each account's
 * balance from the ledger and compares it with the stored balance — two paths to the same number —
 * but both paths read the same corrupt ledger, so it reported zero drift while the fund was carrying
 * ৳33.8 lakh of contributions to payroll that does not exist.
 *
 * Two defects, measured on 2026-11-16:
 *
 *   1. ORPHANS. 3,433 of 5,115 ledger entries reference a `payroll_slip` row that is no longer in the
 *      database — slip ids 982 and 1963..2914 against a live range of 2944..3924. They were booked by
 *      a payroll run that was later deleted, and deleting the run never withdrew the accrual. 1,750
 *      of them are dated 2026-12-31, a month in the future, and report as `accrued`.
 *
 *   2. DOUBLE COUNTS. The October run booked its contribution twice: once against the dead slip and
 *      once against the live one. Every one of the 875 accounts therefore stores three copies of a
 *      single month — 9,300.00 held against 3,100.00 actually earned.
 *
 * What this class does NOT do is decide. `audit()` only reads; `repair()` writes nothing unless the
 * caller passes the exact plan it expects, so a repair can never run against a total it did not
 * check first.
 */
class PfLedgerIntegrityService
{
    /** Entry types that increase a member's balance. */
    public const CREDIT_TYPES = ['employee_contribution', 'employer_contribution'];

    /** Entry types that draw on it. */
    public const DEBIT_TYPES = ['withdrawal', 'settlement'];

    /**
     * Read-only. Everything a human needs to decide whether to repair, and nothing that changes.
     *
     * @return array<string, mixed>
     */
    public function audit(): array
    {
        $liveSlipIds = DB::table('payroll_slips')->pluck('id')->flip();

        $total = 0;
        $orphans = 0;
        $orphanAmount = 0.0;
        $future = 0;
        $futureAmount = 0.0;
        $duplicateGroups = 0;
        $duplicateAmount = 0.0;

        $byPeriod = [];
        $groups = [];

        DB::table('pf_ledgers')->orderBy('id')->chunk(2000, function ($rows) use (
            $liveSlipIds, &$total, &$orphans, &$orphanAmount, &$future, &$futureAmount, &$byPeriod, &$groups
        ): void {
            foreach ($rows as $row) {
                $total++;
                $period = (string) ($row->contribution_month ?? '—');
                $byPeriod[$period] = ($byPeriod[$period] ?? 0) + 1;

                if (self::isOrphaned($row, $liveSlipIds)) {
                    $orphans++;
                    $orphanAmount += (float) $row->amount;

                    if ($row->entry_date !== null && substr((string) $row->entry_date, 0, 10) > now()->toDateString()) {
                        $future++;
                        $futureAmount += (float) $row->amount;
                    }
                }

                $key = $row->pf_account_id.'|'.$period.'|'.$row->entry_type;
                $groups[$key][] = (float) $row->amount;
            }
        });

        foreach ($groups as $amounts) {
            if (count($amounts) > 1) {
                $duplicateGroups++;
                // Everything after the first copy is the overstatement.
                $duplicateAmount += array_sum($amounts) - $amounts[0];
            }
        }

        ksort($byPeriod);

        return [
            'entries' => $total,
            'orphan_entries' => $orphans,
            'orphan_amount' => round($orphanAmount, 2),
            'future_entries' => $future,
            'future_amount' => round($futureAmount, 2),
            'duplicate_groups' => $duplicateGroups,
            'duplicate_amount' => round($duplicateAmount, 2),
            'ledger_total' => round((float) DB::table('pf_ledgers')->sum('amount'), 2),
            'stored_employee' => round((float) DB::table('pf_accounts')->sum('employee_balance'), 2),
            'stored_employer' => round((float) DB::table('pf_accounts')->sum('employer_balance'), 2),
            'by_period' => array_map(fn (int $n): int => $n, $byPeriod),
        ];
    }

    /**
     * What each account would hold if only entries backed by a live slip were counted.
     *
     * @return array{accounts: list<array<string, mixed>>, employee: float, employer: float}
     */
    public function correctBalances(): array
    {
        $liveSlipIds = DB::table('payroll_slips')->pluck('id')->flip();
        $accounts = [];
        $employee = 0.0;
        $employer = 0.0;

        foreach (PfAccount::query()->orderBy('id')->get() as $account) {
            $balances = $this->balancesFor((int) $account->id, $liveSlipIds);

            $employeeDrift = round($balances['employee'] - (float) $account->employee_balance, 2);
            $employerDrift = round($balances['employer'] - (float) $account->employer_balance, 2);

            $employee += $balances['employee'];
            $employer += $balances['employer'];

            if (abs($employeeDrift) > 0.009 || abs($employerDrift) > 0.009) {
                $accounts[] = [
                    'account_id' => (int) $account->id,
                    'employee_id' => $account->employee_id,
                    'employee_now' => round((float) $account->employee_balance, 2),
                    'employee_correct' => round($balances['employee'], 2),
                    'employer_now' => round((float) $account->employer_balance, 2),
                    'employer_correct' => round($balances['employer'], 2),
                ];
            }
        }

        return ['accounts' => $accounts, 'employee' => round($employee, 2), 'employer' => round($employer, 2)];
    }

    /**
     * Remove the rows that describe payroll which no longer exists, and resettle the balances from
     * what remains.
     *
     * $expect is the audit this was planned against. Every count must match, or nothing is written —
     * a repair that runs against a different database than the one somebody looked at is how a
     * cleanup becomes an incident.
     *
     * @param  array{orphans: int, duplicate_groups: int, employee: float, employer: float}  $expect
     * @return array<string, mixed>
     */
    public function repair(array $expect): array
    {
        $audit = $this->audit();

        $mismatch = [];
        if ((int) $expect['orphans'] !== $audit['orphan_entries']) {
            $mismatch[] = "orphan entries: expected {$expect['orphans']}, found {$audit['orphan_entries']}";
        }
        if ((int) $expect['duplicate_groups'] !== $audit['duplicate_groups']) {
            $mismatch[] = "duplicate groups: expected {$expect['duplicate_groups']}, found {$audit['duplicate_groups']}";
        }

        if ($mismatch !== []) {
            throw new \RuntimeException('Refusing to repair: the ledger is not what the plan was built against. '.implode('; ', $mismatch));
        }

        $liveSlipIds = DB::table('payroll_slips')->pluck('id')->flip();

        $deleted = 0;
        $deletedAmount = 0.0;

        DB::transaction(function () use ($liveSlipIds, &$deleted, &$deletedAmount): void {
            DB::table('pf_ledgers')->orderBy('id')->chunkById(1000, function ($rows) use ($liveSlipIds, &$deleted, &$deletedAmount): void {
                $ids = [];
                foreach ($rows as $row) {
                    if (self::isOrphaned($row, $liveSlipIds)) {
                        $ids[] = $row->id;
                        $deletedAmount += (float) $row->amount;
                    }
                }
                if ($ids !== []) {
                    $deleted += DB::table('pf_ledgers')->whereIn('id', $ids)->delete();
                }
            });

            // Balances are now derived from a ledger that only describes real payroll.
            foreach (PfAccount::query()->orderBy('id')->lazyById(200) as $account) {
                $balances = $this->balancesFor((int) $account->id, $liveSlipIds);
                $account->forceFill([
                    'employee_balance' => $balances['employee'],
                    'employer_balance' => $balances['employer'],
                ])->save();
            }
        });

        return [
            'deleted_entries' => $deleted,
            'deleted_amount' => round($deletedAmount, 2),
            'after' => $this->audit(),
        ];
    }

    /**
     * Is this row describing payroll that no longer exists?
     *
     * Only a row that names a salary slip can be orphaned by that slip's deletion. A withdrawal
     * points at `pf_withdrawals` instead, and its `reference_id` is a withdrawal id — treating it as
     * a slip id is how the first repair deleted a real 100.00 withdrawal and its ledger row. The
     * reference *type* decides, never the id alone.
     *
     * @param  object  $row
     * @param  \Illuminate\Support\Collection<int, int>  $liveSlipIds
     */
    private static function isOrphaned(object $row, $liveSlipIds): bool
    {
        if ($row->reference_id === null) {
            return false;
        }

        if (($row->reference_type ?? null) !== 'payroll_slip') {
            return false;
        }

        return ! $liveSlipIds->has((int) $row->reference_id);
    }

    /**
     * Balance for one account, counting only entries whose slip still exists.
     *
     * @param  \Illuminate\Support\Collection<int, int>  $liveSlipIds
     * @return array{employee: float, employer: float}
     */
    private function balancesFor(int $accountId, $liveSlipIds): array
    {
        $employee = 0.0;
        $employer = 0.0;

        $entries = PfLedger::query()->where('pf_account_id', $accountId)->get();

        foreach ($entries as $entry) {
            if (self::isOrphaned($entry, $liveSlipIds)) {
                continue;
            }

            $amount = (float) $entry->amount;

            if ($entry->entry_type === 'employee_contribution') {
                $employee += $amount;
            } elseif ($entry->entry_type === 'employer_contribution') {
                $employer += $amount;
            } elseif (in_array($entry->entry_type, self::DEBIT_TYPES, true)) {
                // Mirrors PfAccount::recalculateBalances(): a withdrawal draws on the member's own
                // money, a final settlement draws on both sides.
                $employee -= $amount;
                if ($entry->entry_type === 'settlement') {
                    $employer -= $amount;
                }
            }
        }

        return ['employee' => round($employee, 2), 'employer' => round($employer, 2)];
    }
}
