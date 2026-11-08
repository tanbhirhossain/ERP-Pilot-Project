<?php

namespace Modules\ACCOUNTS\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\ACCOUNTS\Interfaces\FiscalYearRepositoryInterface;
use Modules\ACCOUNTS\Models\Account;
use Modules\ACCOUNTS\Models\AccountLedger;
use Modules\ACCOUNTS\Models\ClosingEntry;
use Modules\ACCOUNTS\Models\FiscalYear;
use Modules\ACCOUNTS\Models\Journal;
use Modules\ACCOUNTS\Models\JournalLine;
use Modules\CORE\Support\DataTableQuery;

class FiscalYearService
{
    public function __construct(private readonly FiscalYearRepositoryInterface $repo) {}

    public function paginate(DataTableQuery $dt): LengthAwarePaginator
    {
        return $this->repo->paginate($dt);
    }

    public function findOrFail(int $id): FiscalYear
    {
        return $this->repo->findOrFail($id);
    }

    public function currentOpen(): ?FiscalYear
    {
        return $this->repo->currentOpen();
    }

    public function create(array $data): FiscalYear
    {
        return $this->repo->create($data);
    }

    public function update(FiscalYear $fy, array $data): FiscalYear
    {
        return $this->repo->update($fy, $data);
    }

    public function close(FiscalYear $fy): FiscalYear
    {
        if ($fy->isClosed()) {
            throw new \DomainException("Fiscal year {$fy->name} already closed.");
        }

        return DB::transaction(function () use ($fy): FiscalYear {
            $drafts = Journal::where('fiscal_year_id', $fy->id)->where('status', 'draft')->exists();
            if ($drafts) {
                throw new \DomainException('Cannot close fiscal year with draft journals. Post or void them first.');
            }
            // ─ Retained-earnings closing (P&L → Equity 3200) ─
            $ledger = app(LedgerService::class);
            $pl = $ledger->profitAndLoss($fy->start_date->format('Y-m-d'), $fy->end_date->format('Y-m-d'));
            $net = round((float) ($pl['net_profit'] ?? 0), 2);
            $retained = Account::where('code', '3200')->first();
            if ($retained && abs($net) > 0.005) {
                $fyPeriod = $fy->periods()->where('month_no', 12)->first() ?? $fy->periods()->first();
                // Build closing lines: close each revenue/expense account to retained earnings for audit trail
                $pnlAccounts = Account::whereIn('account_type', ['revenue', 'income', 'expense'])->get();
                // fallback: codes 4xxx / 5xxx
                if ($pnlAccounts->isEmpty()) {
                    $pnlAccounts = Account::where(fn ($q) => $q->where('code', 'like', '4%')->orWhere('code', 'like', '5%'))->get();
                }
                $lines = [];
                // aggregate net per account from ledger lines in FY range
                foreach ($pnlAccounts as $acc) {
                    $bal = DB::table('acc_ledger_lines as l')
                        ->join('acc_journals as j', 'j.id', '=', 'l.journal_id')
                        ->where('l.account_id', $acc->id)->where('j.status', 'posted')
                        ->whereBetween('j.journal_date', [$fy->start_date->format('Y-m-d'), $fy->end_date->format('Y-m-d')])
                        ->selectRaw('COALESCE(SUM(l.debit),0) as d, COALESCE(SUM(l.credit),0) as c')->first();
                    $d = (float) ($bal->d ?? 0);
                    $c = (float) ($bal->c ?? 0);
                    // income nature credit: balance = c - d ; expense debit: balance = d - c
                    $accountBalance = $acc->nature === 'credit' ? ($c - $d) : ($d - $c);
                    if (abs($accountBalance) < 0.005) {
                        continue;
                    }
                    if ($acc->nature === 'credit') {
                        // revenue credit balance → debit revenue, credit retained
                        $lines[] = ['account_id' => $acc->id, 'debit' => $accountBalance, 'credit' => 0, 'description' => "Close {$acc->code} {$acc->name} FY {$fy->code}"];
                        $lines[] = ['account_id' => $retained->id, 'debit' => 0, 'credit' => $accountBalance, 'description' => "Retained earnings from {$acc->code}"];
                    } else {
                        // expense debit balance → credit expense, debit retained
                        $lines[] = ['account_id' => $acc->id, 'debit' => 0, 'credit' => $accountBalance, 'description' => "Close {$acc->code} {$acc->name} FY {$fy->code}"];
                        $lines[] = ['account_id' => $retained->id, 'debit' => $accountBalance, 'credit' => 0, 'description' => "Retained earnings from {$acc->code}"];
                    }
                }
                if (! empty($lines)) {
                    $totalD = array_sum(array_column($lines, 'debit'));
                    $totalC = array_sum(array_column($lines, 'credit'));
                    $journal = Journal::create([
                        'journal_no' => 'CLS-'.strtoupper($fy->code).'-'.now()->format('YmdHis'),
                        'journal_date' => $fy->end_date->format('Y-m-d'),
                        'fiscal_year_id' => $fy->id,
                        'fiscal_period_id' => $fyPeriod?->id,
                        'source_type' => 'FiscalYear', 'source_id' => $fy->id,
                        'narration' => "Year-end closing FY {$fy->name} — P&L {$net} BDT to Retained Earnings (auto)",
                        'total_debit' => $totalD, 'total_credit' => $totalC,
                        'status' => 'posted', 'created_by' => auth()->id(), 'posted_by' => auth()->id(), 'posted_at' => now(),
                    ]);
                    $lineNo = 1;
                    $createdLines = [];
                    foreach ($lines as $ln) {
                        $createdLines[] = JournalLine::create(['journal_id' => $journal->id, 'line_no' => $lineNo++, 'account_id' => $ln['account_id'], 'debit' => $ln['debit'], 'credit' => $ln['credit'], 'description' => $ln['description']]);
                    }
                    // ledger entries for FY close (posted journal → ledger)
                    foreach ($createdLines as $jl) {
                        $acc = Account::find($jl->account_id);
                        $delta = $acc && $acc->nature === 'debit' ? ((float) $jl->debit - (float) $jl->credit) : ((float) $jl->credit - (float) $jl->debit);
                        $cur = (float) (AccountLedger::where('account_id', $jl->account_id)->orderByDesc('id')->value('balance_after') ?? 0);
                        AccountLedger::create(['account_id' => $jl->account_id, 'journal_id' => $journal->id, 'journal_line_id' => $jl->id, 'posting_date' => $journal->journal_date, 'debit' => $jl->debit, 'credit' => $jl->credit, 'balance_after' => $cur + $delta, 'reference_no' => $journal->journal_no]);
                    }
                    ClosingEntry::create(['fiscal_year_id' => $fy->id, 'journal_id' => $journal->id, 'type' => 'retained_earnings', 'amount' => abs($net), 'created_by' => auth()->id()]);
                }
            }
            $fy->periods()->update(['status' => 'locked']);
            $fy->update(['status' => 'closed', 'closed_by' => auth()->id(), 'closed_at' => now()]);

            return $fy->fresh('periods');
        });
    }

    public function reopen(FiscalYear $fy): FiscalYear
    {
        if (! $fy->isClosed()) {
            throw new \DomainException('Only closed years can be reopened.');
        }
        $fy->update(['status' => 'open', 'closed_by' => null, 'closed_at' => null]);
        $fy->periods()->update(['status' => 'open']);

        return $fy->fresh('periods');
    }
}
