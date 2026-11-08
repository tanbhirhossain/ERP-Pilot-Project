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

    public function trialBalance(?string $asOf = null): Collection
    {
        $asOf = $asOf ?? now()->toDateString();

        // aggregate ledger per account (posted only via ledger table)
        return DB::table('acc_account_ledger as l')
            ->join('acc_accounts as a', 'a.id', '=', 'l.account_id')
            ->whereDate('l.posting_date', '<=', $asOf)
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
        $tb = $this->trialBalance($to);
        // filter to income/expense and respect date window via ledger query
        $income = $tb->where('account_type', 'income')->sum(fn ($r) => abs($r['balance']));
        $expense = $tb->where('account_type', 'expense')->sum(fn ($r) => abs($r['balance']));
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
        $assets = $tb->where('account_type', 'asset')->sum(fn ($r) => abs($r['balance']));
        $liabilities = $tb->where('account_type', 'liability')->sum(fn ($r) => abs($r['balance']));
        $equity = $tb->where('account_type', 'equity')->sum(fn ($r) => abs($r['balance']));
        // retained earnings = P&L to date
        $pl = $this->profitAndLoss(null, $asOf);
        $retained = $pl['net_profit'];

        return [
            'as_of' => $asOf, 'assets' => $assets, 'liabilities' => $liabilities, 'equity' => $equity,
            'retained_earnings' => $retained, 'total_equity' => $equity + $retained,
            'balanced' => abs($assets - ($liabilities + $equity + $retained)) < 0.01,
            'details' => $tb->whereIn('account_type', ['asset', 'liability', 'equity'])->values(),
        ];
    }

    public function cashFlow(?string $from = null, ?string $to = null): array
    {
        $from = $from ?? now()->startOfMonth()->toDateString();
        $to = $to ?? now()->toDateString();
        $tb = $this->trialBalance($to);
        // Indirect method: start from P&L + adjust via cash/bank movements
        $pl = $this->profitAndLoss($from, $to);
        $cashAccounts = Account::whereIn('code', ['1010', '1011', '1020'])->orWhere('account_type', 'asset')->where('name', 'like', '%Cash%')->orWhere('name', 'like', '%Bank%')->pluck('id');
        // Fallback: all asset cash-like
        $operating = $pl['net_profit'];
        $investing = 0;
        $financing = 0;
        // Rough buckets by account code prefix if available
        foreach ($tb->where('account_type', 'asset') as $r) {
            $code = $r['code'] ?? '';
            if (str_starts_with($code, '15') || str_starts_with($code, '16')) {
                $investing += (float) $r['balance'];
            }
        }
        foreach ($tb->where('account_type', 'liability') as $r) {
            $code = $r['code'] ?? '';
            if (str_starts_with($code, '21') || str_starts_with($code, '31')) {
                $financing += (float) $r['balance'];
            }
        }

        return [
            'from' => $from, 'to' => $to,
            'operating' => $operating, 'investing' => $investing, 'financing' => $financing,
            'net_change' => $operating + $investing + $financing,
            'details' => $tb->values(),
        ];
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
