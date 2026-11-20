<?php

namespace Modules\ACCOUNTS\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\ACCOUNTS\DTO\JournalData;
use Modules\ACCOUNTS\Interfaces\JournalRepositoryInterface;
use Modules\ACCOUNTS\Models\Account;
use Modules\ACCOUNTS\Models\AccountLedger;
use Modules\ACCOUNTS\Models\FiscalPeriod;
use Modules\ACCOUNTS\Models\FiscalYear;
use Modules\ACCOUNTS\Models\Journal;
use Modules\ACCOUNTS\Models\Payable;
use Modules\ACCOUNTS\Models\Receivable;
use Modules\ACCOUNTS\Support\JournalBalance;
use Modules\CORE\Support\DataTableQuery;

class JournalService
{
    public function __construct(private readonly JournalRepositoryInterface $repo) {}

    public function paginate(DataTableQuery $dt): LengthAwarePaginator
    {
        return $this->repo->paginate($dt);
    }

    public function exportBuilder(DataTableQuery $dt): Builder
    {
        return $this->repo->exportBuilder($dt);
    }

    public function findOrFail(int $id): Journal
    {
        return $this->repo->findOrFail($id);
    }

    public function create(array $data): Journal
    {
        $this->assertFiscalOpen($data['journal_date'] ?? now()->toDateString());
        $this->assertAccountsPosting($data['lines'] ?? []);
        $this->assertBalanced($data['lines'] ?? []);

        return $this->repo->create($this->withTotalsFromLines($data));
    }

    public function update(Journal $journal, array $data): Journal
    {
        // create() and post() both gate on the fiscal calendar but update() did not, so a draft
        // could be re-dated into a closed or locked period. Only JournalController@update calls
        // this, so the check belongs here rather than in the repository.
        $this->assertFiscalOpen($data['journal_date'] ?? $journal->journal_date?->format('Y-m-d') ?? now()->toDateString());

        if (isset($data['lines'])) {
            $this->assertAccountsPosting($data['lines']);
            $this->assertBalanced($data['lines']);
        }

        return $this->repo->update($journal, $this->withTotalsFromLines($data));
    }

    public function delete(Journal $journal): void
    {
        $this->repo->delete($journal);
    }

    public function createFromDto(JournalData $dto): Journal
    {
        $this->assertFiscalOpen($dto->journalDate);
        $lines = array_map(fn ($l) => [
            'account_id' => $l->accountId, 'debit' => $l->debit, 'credit' => $l->credit,
            'description' => $l->description, 'partner_id' => $l->partnerId, 'partner_type' => $l->partnerType, 'cost_center' => $l->costCenter,
        ], $dto->lines);
        $this->assertAccountsPosting($lines);
        $fy = $this->resolveFiscalYear($dto->journalDate);

        return $this->repo->create([
            'journal_date' => $dto->journalDate, 'type' => $dto->type, 'reference_no' => $dto->referenceNo,
            'narration' => $dto->narration, 'total_debit' => $dto->totalDebit(), 'total_credit' => $dto->totalCredit(),
            'status' => 'draft', 'partner_id' => $dto->partnerId, 'partner_type' => $dto->partnerType,
            'source_type' => $dto->sourceType, 'source_id' => $dto->sourceId,
            'fiscal_year_id' => $fy?->id, 'fiscal_period_id' => $fy ? $this->resolvePeriod($fy, $dto->journalDate)?->id : null,
            'lines' => $lines,
        ]);
    }

    public function post(Journal $journal): Journal
    {
        if (! $journal->isDraft()) {
            throw new \DomainException('Only draft journals can be posted.');
        }
        if (! $journal->isBalanced()) {
            throw new \DomainException('Journal not balanced.');
        }
        $this->assertFiscalOpen($journal->journal_date->format('Y-m-d'));

        return DB::transaction(function () use ($journal): Journal {
            $journal->load('lines');

            // isBalanced() above only compared the stored header. The ledger rows below are written
            // from the *lines*, so a header that balances over lines that do not would move every
            // account it touches by an amount the journal never claimed. Checked inside the
            // transaction so a refusal rolls back cleanly and leaves the draft untouched.
            JournalBalance::assertBalanced($journal->lines);

            // lock accounts for balance consistency
            $accountIds = $journal->lines->pluck('account_id')->unique()->values();
            Account::whereIn('id', $accountIds)->lockForUpdate()->get();

            // create ledger entries + compute running balance (simple: current balance + delta)
            foreach ($journal->lines as $line) {
                $account = Account::find($line->account_id);
                $delta = $this->balanceDelta($account, (float) $line->debit, (float) $line->credit);
                $currentBalance = $this->currentBalance($account->id);
                $newBalance = $currentBalance + $delta;

                AccountLedger::create([
                    'account_id' => $account->id, 'journal_id' => $journal->id, 'journal_line_id' => $line->id,
                    'posting_date' => $journal->journal_date, 'debit' => $line->debit, 'credit' => $line->credit,
                    'balance_after' => $newBalance, 'reference_no' => $journal->journal_no,
                ]);
            }

            $journal->update(['status' => 'posted', 'posted_by' => auth()->id(), 'posted_at' => now()]);

            // auto-create AP/AR if partner linked and type indicates
            $this->syncPayableReceivable($journal);

            return $journal->fresh(['lines.account']);
        });
    }

    public function void(Journal $journal, string $reason): Journal
    {
        if (! $journal->isPosted()) {
            throw new \DomainException('Only posted journals can be voided.');
        }

        // A void writes a brand-new *posted* journal dated today, so it is a posting into whatever
        // period covers now() and was the only writing path here that never consulted the calendar.
        // Without this a year could be closed and then quietly reopened by reversal entries.
        $this->assertFiscalOpen(now()->toDateString());

        return DB::transaction(function () use ($journal, $reason): Journal {
            // reverse ledger with mirror entry via new void journal
            $voidJournal = $this->repo->create([
                'journal_date' => now()->toDateString(), 'type' => $journal->type, 'reference_no' => $journal->journal_no,
                'narration' => "Void of {$journal->journal_no}: {$reason}", 'total_debit' => $journal->total_credit, 'total_credit' => $journal->total_debit,
                'status' => 'posted', 'source_type' => 'journal_void', 'source_id' => $journal->id,
                'posted_by' => auth()->id(), 'posted_at' => now(),
                'lines' => $journal->lines->map(fn ($l) => [
                    'account_id' => $l->account_id, 'debit' => $l->credit, 'credit' => $l->debit, 'description' => "Reversal: {$l->description}",
                ])->toArray(),
            ]);
            // ledger for reversal
            foreach ($voidJournal->lines as $line) {
                $account = Account::find($line->account_id);
                $delta = $this->balanceDelta($account, (float) $line->debit, (float) $line->credit);
                $currentBalance = $this->currentBalance($account->id);
                AccountLedger::create([
                    'account_id' => $account->id, 'journal_id' => $voidJournal->id, 'journal_line_id' => $line->id,
                    'posting_date' => $voidJournal->journal_date, 'debit' => $line->debit, 'credit' => $line->credit,
                    'balance_after' => $currentBalance + $delta, 'reference_no' => $voidJournal->journal_no,
                ]);
            }
            $journal->update(['status' => 'void', 'voided_by' => auth()->id(), 'voided_at' => now(), 'void_reason' => $reason]);

            return $journal->fresh();
        });
    }

    /**
     * Delegated to JournalBalance, which is also what StoreJournalRequest validates against and
     * what Journal::isBalanced() effectively requires at post time. It used to be a one-cent tolerance
     * here against an exact 2dp comparison there, and the gap between the two produced drafts that
     * could never be posted.
     *
     * @param  iterable<int, array<string, mixed>|object>  $lines
     */
    private function assertBalanced(iterable $lines): void
    {
        JournalBalance::assertBalanced($lines);
    }

    /**
     * The header totals are a derived value: they are whatever the lines sum to, rounded to cents.
     * Callers may pass their own — JournalController@store recomputes them inline — but the service
     * is the boundary other modules post through, so the invariant is enforced here rather than
     * left to each caller to remember.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withTotalsFromLines(array $data): array
    {
        if (! isset($data['lines']) || $data['lines'] === null || $data['lines'] === []) {
            return $data;
        }

        return array_merge($data, JournalBalance::totals($data['lines']));
    }

    private function assertAccountsPosting(array $lines): void
    {
        foreach ($lines as $line) {
            $accountId = $line['account_id'] ?? $line->accountId ?? null;
            if (! $accountId) {
                continue;
            }
            $account = Account::find($accountId);
            if (! $account) {
                throw new \DomainException("Account #{$accountId} not found.");
            }
            if (! $account->is_active) {
                throw new \DomainException("Account {$account->code} is inactive.");
            }
            if ($account->is_group) {
                throw new \DomainException("Account {$account->code} is a group header — post to a child account.");
            }
        }
    }

    private function assertFiscalOpen(string $date): void
    {
        if (! config('accounts.posting.require_fiscal_open', true)) {
            return;
        }
        $fy = FiscalYear::whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date)->first();
        if (! $fy) {
            throw new \DomainException("No fiscal year covers {$date}. Create or open one.");
        }
        if ($fy->status === 'closed' || $fy->status === 'locked') {
            throw new \DomainException("Fiscal year {$fy->name} is {$fy->status} — posting blocked.");
        }
        $period = FiscalPeriod::whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date)->where('fiscal_year_id', $fy->id)->first();
        if ($period && ($period->status === 'closed' || $period->status === 'locked')) {
            throw new \DomainException("Period {$period->name} is {$period->status} — posting blocked.");
        }
    }

    private function resolveFiscalYear(string $date): ?FiscalYear
    {
        return FiscalYear::whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date)->first();
    }

    private function resolvePeriod(FiscalYear $fy, string $date): ?FiscalPeriod
    {
        return FiscalPeriod::whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date)->where('fiscal_year_id', $fy->id)->first();
    }

    private function balanceDelta(Account $account, float $debit, float $credit): float
    {
        // asset/expense normal debit: debit +, credit -
        // liability/equity/income normal credit: credit +, debit -
        $isDebitNature = $account->nature === 'debit';
        if ($isDebitNature) {
            return $debit - $credit;
        }

        return $credit - $debit;
    }

    private function currentBalance(int $accountId): float
    {
        return (float) (AccountLedger::where('account_id', $accountId)->orderByDesc('id')->value('balance_after') ?? 0);
    }

    private function syncPayableReceivable(Journal $journal): void
    {
        // Keep the AP/AR sub-ledgers in step with the control accounts.
        //
        // This used to take the amount from `$journal->total_debit` — the whole journal — and treat
        // every RV as a new receivable. Both are wrong, and wrong in a way no report could see:
        //
        //   • `total_debit` is every debit line added together. A sale journal debits Accounts
        //     Receivable *and* Cost of Goods Sold, so the receivable it opened was overstated by the
        //     cost of the goods sold — 6,275.42 booked against an invoice of 3,502.25.
        //   • An RV is money *coming in*: it debits cash and credits the receivable. Recording that
        //     as `status = open` opened a fresh receivable for every collection, so paying a customer
        //     invoice made the customer owe more. Nothing ever closed a receivable, because nothing
        //     ever looked for the one it was settling.
        //
        // The amount now comes from the control line itself — Account 1100 for a receivable, 2100 for
        // a payable — which is the only line that knows what the partner actually owes. Collections
        // settle the oldest open document rather than opening a new one, and an amount that exceeds
        // everything outstanding is left unapplied instead of being invented as a debt.
        if (! $journal->partner_id || ! $journal->partner_type) {
            return;
        }

        $lines = $journal->lines()->with('account')->get();
        $byCode = fn (string $code) => $lines->first(fn ($l) => $l->account && $l->account->code === $code);

        if ($journal->partner_type === 'vendor') {
            $control = $byCode('2100');
            if ($control === null) {
                return;
            }
            $debit = (float) $control->debit;
            $credit = (float) $control->credit;

            // A credit on the payable control is a new bill; a debit is us paying one down.
            if ($credit > 0) {
                Payable::create([
                    'partner_id' => $journal->partner_id, 'journal_id' => $journal->id,
                    'bill_no' => $journal->reference_no, 'bill_date' => $journal->journal_date,
                    'due_date' => $journal->journal_date->copy()->addDays(30),
                    'amount' => $credit, 'status' => 'open',
                ]);
            } elseif ($debit > 0) {
                $this->settleOpen(Payable::query(), $journal, $debit);
            }

            return;
        }

        if ($journal->partner_type === 'customer') {
            $control = $byCode('1100');
            if ($control === null) {
                return;
            }
            $debit = (float) $control->debit;
            $credit = (float) $control->credit;

            // A debit on the receivable control is a new invoice; a credit is the customer paying.
            if ($debit > 0) {
                Receivable::create([
                    'partner_id' => $journal->partner_id, 'journal_id' => $journal->id,
                    'invoice_no' => $journal->reference_no, 'invoice_date' => $journal->journal_date,
                    'due_date' => $journal->journal_date->copy()->addDays(30),
                    'amount' => $debit, 'status' => 'open',
                ]);
            } elseif ($credit > 0) {
                $this->settleOpen(Receivable::query(), $journal, $credit);
            }
        }
    }

    /**
     * Apply money received/paid against the oldest open documents, oldest first.
     *
     * Anything left over after every open document is settled is deliberately dropped rather than
     * turned into a negative-balance row: a partner who has paid more than they owed is an advance,
     * which belongs in its own accounting treatment, not in the receivables list as a phantom debt.
     */
    private function settleOpen($query, Journal $journal, float $amount): void
    {
        $open = $query->where('partner_id', $journal->partner_id)
            ->whereNotIn('status', ['paid', 'cancelled', 'void'])
            ->orderBy('invoice_date')->orderBy('id')->get();

        $left = $amount;
        foreach ($open as $doc) {
            if ($left <= 0) {
                break;
            }
            $outstanding = (float) $doc->balance;
            if ($outstanding <= 0) {
                continue;
            }
            $applied = min($outstanding, $left);
            $newBalance = round($outstanding - $applied, 2);
            // `balance` is `amount - paid_amount` as a stored generated column, so writing it is both
            // rejected by the database and unnecessary: record what was paid and let it recompute.
            $paidColumn = array_key_exists('paid_amount', (array) $doc->getAttributes()) ? 'paid_amount' : 'received_amount';
            $doc->update([
                $paidColumn => round((float) $doc->{$paidColumn} + $applied, 2),
                'status' => $newBalance <= 0.005 ? 'paid' : 'partial',
            ]);
            $left = round($left - $applied, 2);
        }
    }
}
