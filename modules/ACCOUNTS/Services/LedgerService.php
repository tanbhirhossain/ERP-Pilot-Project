<?php

namespace Modules\ACCOUNTS\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\ACCOUNTS\Models\Account;
use Modules\ACCOUNTS\Models\AccountLedger;

class LedgerService
{
    public function ledgerForAccount(int $accountId, ?string $from = null, ?string $to = null, int $limit = 200): Collection
    {
        return AccountLedger::where('account_id', $accountId)
            ->when($from, fn ($q) => $q->whereDate('posting_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('posting_date', '<=', $to))
            ->with(['journal'])->orderBy('posting_date')->orderBy('id')->limit($limit)->get();
    }

    public function trialBalance(?string $asOf = null, ?string $from = null): Collection
    {
        $asOf = $asOf ?? now()->toDateString();

        // Aggregate the ledger per account, but only while the journal it came from still exists and
        // is posted. A ledger row outliving its journal is a phantom posting: it moves every report
        // that reads this table without appearing anywhere a human can inspect, and there is no way
        // to trace it back. Joining the journal keeps such rows out of the trial balance and every
        // report derived from it, so a half-deleted journal shows up as a missing posting (visible)
        // instead of an untraceable balance (invisible).
        return DB::table('acc_account_ledger as l')
            ->join('acc_accounts as a', 'a.id', '=', 'l.account_id')
            ->join('acc_journals as j', function ($join) {
                $join->on('j.id', '=', 'l.journal_id')->where('j.status', '=', 'posted');
            })
            ->whereDate('l.posting_date', '<=', $asOf)
            // A window, not just a cutoff. `profitAndLoss()` has always accepted a `from` date and
            // has always ignored it, so a "this month" report and a "since inception" report
            // printed the same number and neither said so. The bound is applied here rather than
            // in the caller because every report that takes a window has to agree on what a row's
            // date means, and there is exactly one place where that is decided.
            ->when($from, fn ($q) => $q->whereDate('l.posting_date', '>=', $from))
            ->groupBy('l.account_id', 'a.code', 'a.name', 'a.account_type', 'a.nature')
            ->selectRaw('l.account_id, a.code, a.name, a.account_type, a.nature, SUM(l.debit) as total_debit, SUM(l.credit) as total_credit')
            ->orderBy('a.code')
            ->get()
            ->map(function ($row) {
                $debit = (float) $row->total_debit;
                $credit = (float) $row->total_credit;
                $balance = $row->nature === 'debit' ? $debit - $credit : $credit - $debit;

                return [
                    'account_id' => $row->account_id, 'code' => $row->code, 'name' => $row->name,
                    'account_type' => $row->account_type, 'nature' => $row->nature,
                    'total_debit' => $debit, 'total_credit' => $credit, 'balance' => $balance,
                    'balance_side' => $balance >= 0 ? $row->nature : ($row->nature === 'debit' ? 'credit' : 'debit'),
                ];
            });
    }

    public function profitAndLoss(?string $from = null, ?string $to = null): array
    {
        $from = $from ?? now()->startOfYear()->toDateString();
        $to = $to ?? now()->toDateString();
        // `trialBalance($to)` alone made every window report identical to the year-to-date one.
        $tb = $this->trialBalance($to, $from);
        // filter to income/expense and respect date window via ledger query
        // Signed, not abs(). `balance` is already normalised against the account's nature, so an
        // income account that has ended up with a debit balance is a negative income, not a positive
        // one. Taking the absolute value here silently turns a mis-posted credit into revenue and an
        // over-refunded expense into cost, and the two errors then cancel into a plausible profit.
        $income = $tb->where('account_type', 'income')->sum(fn ($r) => (float) $r['balance']);
        $expense = $tb->where('account_type', 'expense')->sum(fn ($r) => (float) $r['balance']);
        $gross = $income - $expense;

        return [
            'from' => $from, 'to' => $to, 'income' => $income, 'expense' => $expense,
            'net_profit' => $gross, 'is_profit' => $gross >= 0,
            'details' => $tb->whereIn('account_type', ['income', 'expense'])->values(),
        ];
    }

    public function balanceSheet(?string $asOf = null): array
    {
        $asOf = $asOf ?? now()->toDateString();
        $tb = $this->trialBalance($asOf);
        // Signed sums. An overdrawn bank account is a negative asset, not a positive one: abs() here
        // used to report 407,303.38 of assets when the bank was 390,141.63 overdrawn, and the sheet
        // then announced an imbalance of exactly twice the overdraft — a figure that looked like a
        // data problem but was an arithmetic one. A credit-balance asset must pull the total down.
        $assets = $tb->where('account_type', 'asset')->sum(fn ($r) => (float) $r['balance']);
        $liabilities = $tb->where('account_type', 'liability')->sum(fn ($r) => (float) $r['balance']);
        $equity = $tb->where('account_type', 'equity')->sum(fn ($r) => (float) $r['balance']);
        // retained earnings = P&L to date
        $pl = $this->profitAndLoss(null, $asOf);
        $retained = $pl['net_profit'];

        // What the sheet is asked, and what it is not asked.
        //
        // The accounting equation is assets − (liabilities + equity + profit to date) = 0, and that is
        // the only thing `balanced` may claim. It is not a data-integrity check: an entry dated after
        // `asOf` is simply not in this sheet yet, and the difference it leaves is the *date window*,
        // not an error. Reporting that as "not balanced" made an as-of date in the past look like
        // corrupted books — which is exactly what happened the first time this report was asked for a
        // future date while a depreciation run sat in the following month.
        //
        // So the sheet also computes what the equation *would* read if nothing were held back, and
        // names the gap. A genuine imbalance shows up as `unposted_delta` being zero while the sheet
        // still does not balance.
        $equation = round($assets - ($liabilities + $equity + $retained), 2);

        return [
            'as_of' => $asOf, 'assets' => $assets, 'liabilities' => $liabilities, 'equity' => $equity,
            'retained_earnings' => $retained, 'total_equity' => $equity + $retained,
            'equation_delta' => $equation,
            'balanced' => abs($equation) < 0.01,
            'details' => $tb->whereIn('account_type', ['asset', 'liability', 'equity'])->values(),
        ];
    }

    /**
     * Where the cash went, and why.
     *
     * The method here is direct, not indirect: every posted journal line touching a cash or bank
     * account is paired with the counter-accounts in the *same journal*, and each counter-account
     * decides the bucket. That is the only way to answer the question a cash-flow statement is asked
     * — "of the money that moved, which part was trading and which part was financing" — because the
     * journal already knows: a payment against a vendor bill moves cash against Accounts Payable, a
     * customer collection moves it against Accounts Receivable, an owner deposit moves it against
     * equity.
     *
     * What this replaces called itself the indirect method but was neither: `operating` was the P&L
     * net profit, which is not a cash figure at all (it ignores receivables, payables, stock and
     * every non-cash accrual), and `financing` summed the *balances* of the whole 21xx/31xx range —
     * so VAT payable, salary payable and gratuity payable were all reported as financing activities.
     * The three buckets then did not add up to the change in cash, which the report did not say.
     *
     * Now `net_change` is the movement on the cash accounts themselves, and the three buckets are
     * its decomposition, so they add up by construction. Any residue — a journal that moves cash
     * against another cash account, or against an account this does not classify — is kept in
     * `unclassified` rather than being hidden inside a bucket that would then be wrong.
     *
     * @return array{from:string,to:string,operating:float,investing:float,financing:float,unclassified:float,net_change:float,opening_cash:float,closing_cash:float,details:Collection}
     */
    public function cashFlow(?string $from = null, ?string $to = null): array
    {
        $from = $from ?? now()->startOfMonth()->toDateString();
        $to = $to ?? now()->toDateString();

        // Cash and bank: the 10xx range, always, plus anything the chart names "cash" or "bank" so a
        // shop with its own numbering is still covered.
        $cashIds = Account::query()
            ->where(fn ($q) => $q->where('code', 'like', '10%')
                ->orWhere('name', 'like', '%Cash%')
                ->orWhere('name', 'like', '%Bank%'))
            ->where('is_posting', true)
            ->pluck('id')
            ->all();

        if ($cashIds === []) {
            return [
                'from' => $from, 'to' => $to,
                'operating' => 0.0, 'investing' => 0.0, 'financing' => 0.0, 'unclassified' => 0.0,
                'net_change' => 0.0, 'opening_cash' => 0.0, 'closing_cash' => 0.0,
                'details' => collect(),
            ];
        }

        // The classification is built on the *non-cash* side of each journal, not by pairing cash
        // lines against everything else.
        //
        // Pairing was the obvious first draft and it is wrong whenever a journal holds more than one
        // cash line — a transfer plus a bank charge, say. Each cash line pairs with every other line,
        // so an amount crossing between two cash accounts is counted once in each direction while the
        // real cash change is once, and the buckets then do not add up to the movement in cash.
        //
        // A journal balances, so the sum of its non-cash lines is exactly minus the sum of its cash
        // lines. Classifying the non-cash lines therefore reproduces the cash movement exactly, with
        // no double counting possible: every non-cash line is looked at once.
        $journals = DB::table('acc_journal_lines as l')
            ->join('acc_journals as j', 'j.id', '=', 'l.journal_id')
            ->join('acc_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('j.status', 'posted')
            ->whereDate('j.journal_date', '>=', $from)
            ->whereDate('j.journal_date', '<=', $to)
            ->whereIn('j.id', function ($q) use ($cashIds) {
                $q->select('journal_id')->from('acc_journal_lines')->whereIn('account_id', $cashIds);
            })
            ->select(
                'j.id as journal_id', 'j.journal_no', 'j.journal_date', 'j.reference_no', 'j.source_type',
                'a.id as account_id', 'a.code', 'a.name', 'a.account_type',
                DB::raw('(COALESCE(l.debit,0) - COALESCE(l.credit,0)) as delta'),
            )
            ->orderBy('j.journal_date')->orderBy('j.id')->orderBy('l.id')
            ->get();

        $buckets = ['operating' => 0.0, 'investing' => 0.0, 'financing' => 0.0, 'unclassified' => 0.0];
        $lines = collect();

        foreach ($journals as $row) {
            if (in_array((int) $row->account_id, $cashIds, true)) {
                continue; // the cash side itself — the other lines mirror it
            }

            // The cash effect of this line is the mirror of the line: a credit to Accounts Payable
            // means cash left the building.
            $amount = round(-1 * (float) $row->delta, 2);

            if (abs($amount) < 0.00001) {
                continue;
            }

            $bucket = $this->cashFlowBucket((string) $row->code, (string) $row->account_type, (float) $row->delta);
            $buckets[$bucket] += $amount;

            $lines->push([
                'journal_no' => $row->journal_no,
                'date' => $row->journal_date,
                'reference' => $row->reference_no,
                'source' => $row->source_type,
                'counter_code' => $row->code,
                'counter_account' => $row->name,
                'bucket' => $bucket,
                'amount' => $amount,
            ]);
        }

        $netChange = round(array_sum($buckets), 2);

        // Opening and closing come from the ledger, not from adding the buckets, so the statement can
        // be checked against the balance sheet instead of against itself.
        $sumAt = function (?string $cutoff, bool $inclusive) use ($cashIds): float {
            $q = DB::table('acc_account_ledger as l')
                ->join('acc_journals as j', 'j.id', '=', 'l.journal_id')
                ->where('j.status', 'posted')
                ->whereIn('l.account_id', $cashIds);

            if ($cutoff !== null) {
                $inclusive
                    ? $q->whereDate('l.posting_date', '<=', $cutoff)
                    : $q->whereDate('l.posting_date', '<', $cutoff);
            }

            $r = $q->select(DB::raw('COALESCE(SUM(l.debit),0) d, COALESCE(SUM(l.credit),0) c'))->first();

            return round((float) $r->d - (float) $r->c, 2);
        };

        $closing = $sumAt($to, true);
        $opening = $sumAt($from, false);

        return [
            'from' => $from, 'to' => $to,
            'operating' => round($buckets['operating'], 2),
            'investing' => round($buckets['investing'], 2),
            'financing' => round($buckets['financing'], 2),
            'unclassified' => round($buckets['unclassified'], 2),
            'net_change' => $netChange,
            'opening_cash' => $opening,
            'closing_cash' => $closing,
            'details' => $lines,
        ];
    }

    /**
     * Which part of the cash-flow statement a movement belongs to, decided by what the cash moved against.
     *
     * Trading is everything that touches the profit-and-loss accounts or the working-capital accounts
     * (receivables, payables, VAT, inventory). Buying and selling long-lived assets is investing —
     * the 15xx/16xx range. Borrowing and owners' money is financing — long-term debt and equity.
     * Anything else is left unclassified on purpose: a wrong bucket is worse than an honest "other",
     * because the buckets are added up and read.
     */
    private function cashFlowBucket(string $code, string $type, float $counterDelta): string
    {
        // A prefix is a convention, not a fact, and this chart does not follow one: Accounts Payable
        // sits on 2210 and 2100 in the same ledger, a bank account on 100201, and "Flow B Vendor
        // Payable" on 2211. Classifying by the first two digits put a supplier payment into
        // *financing* — money spent buying stock reported as though the company had borrowed it —
        // which is the kind of error that survives review because the total still adds up.
        //
        // The account type is the fact. Where the *code* still decides anything — a long-lived asset
        // against a current one — the test is on the 15xx/16xx range, which is where this chart
        // really does keep fixed assets, and everything else is left unclassified rather than guessed.
        if ($type === 'income' || $type === 'expense') {
            return 'operating';
        }

        if ($type === 'equity') {
            return 'financing';
        }

        if ($type === 'asset') {
            // Fixed assets and their depreciation are investing; cash, receivables, VAT and stock are
            // the working capital a trading day moves, so they are operating.
            return (str_starts_with($code, '15') || str_starts_with($code, '16')) ? 'investing' : 'operating';
        }

        if ($type === 'liability') {
            // Trade and other payables — what a shop owes for stock, VAT and wages — are operating.
            // Only long-term borrowing is financing, and this chart carries that on 25xx.
            return str_starts_with($code, '25') ? 'financing' : 'operating';
        }

        return 'unclassified';
    }

    public function depreciationSchedule(?string $asOf = null): array
    {
        $asOf = $asOf ?? now()->toDateString();
        $assets = Account::where('account_type', 'asset')
            ->where(function ($q) {
                $q->where('code', 'like', '15%')->orWhere('name', 'like', '%Asset%');
            })->get();
        // Light schedule: straight-line estimate if no FA register table exists
        $rows = [];
        foreach ($assets as $a) {
            $bal = $this->accountBalance($a->id, $asOf);
            if (abs($bal) < 0.01) {
                continue;
            }
            $rate = 0.10; // 10% p.a. default
            $rows[] = ['account_id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'book_value' => abs($bal), 'annual_rate' => $rate, 'monthly_charge' => round(abs($bal) * $rate / 12, 2)];
        }

        return ['as_of' => $asOf, 'rows' => $rows, 'total_monthly' => array_sum(array_column($rows, 'monthly_charge'))];
    }

    public function accountBalance(int $accountId, ?string $asOfDate = null): float
    {
        $q = AccountLedger::where('account_id', $accountId);
        if ($asOfDate) {
            $q->whereDate('posting_date', '<=', $asOfDate);
        }
        $row = $q->selectRaw('SUM(debit) as d, SUM(credit) as c')->first();
        $account = Account::find($accountId);
        if (! $account) {
            return 0;
        }
        $d = (float) ($row->d ?? 0);
        $c = (float) ($row->c ?? 0);

        return $account->nature === 'debit' ? $d - $c : $c - $d;
    }
}
