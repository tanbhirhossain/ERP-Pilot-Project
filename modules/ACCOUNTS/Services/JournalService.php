<?php

namespace Modules\ACCOUNTS\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
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

        try {
            return $this->repo->create($this->withTotalsFromLines($data));
        } catch (QueryException $e) {
            $this->throwIfDuplicateEntry($e, $data);

            throw $e;
        }
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

        $data = [
            'journal_date' => $dto->journalDate, 'type' => $dto->type, 'reference_no' => $dto->referenceNo,
            'narration' => $dto->narration, 'total_debit' => $dto->totalDebit(), 'total_credit' => $dto->totalCredit(),
            'status' => 'draft', 'partner_id' => $dto->partnerId, 'partner_type' => $dto->partnerType,
            'source_type' => $dto->sourceType, 'source_id' => $dto->sourceId,
            'fiscal_year_id' => $fy?->id, 'fiscal_period_id' => $fy ? $this->resolvePeriod($fy, $dto->journalDate)?->id : null,
            'lines' => $lines,
        ];

        try {
            return $this->repo->create($data);
        } catch (QueryException $e) {
            $this->throwIfDuplicateEntry($e, $data);

            throw $e;
        }
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

    /**
     * Source journals owned by another module. Voiding one from the journal screen would reverse the
     * ledger while the bill, invoice, payment, receipt or day-end that posted it still stands — the
     * source document keeps its status, its paid amounts and its stock movements, and nothing
     * reconciles afterwards. They reverse through their own service, which passes $viaSourceDoc.
     */
    private const MANAGED_SOURCES = [
        'purchase_bill', 'purchase_payment', 'purchase_return', 'purchase_receipt',
        'sales_invoice', 'sales_payment', 'sales_return', 'pos_session',
        'payroll_sheet', 'payroll_slip',
    ];

    public function void(Journal $journal, string $reason, bool $viaSourceDoc = false): Journal
    {
        if (! $journal->isPosted()) {
            throw new \DomainException('Only posted journals can be voided.');
        }

        // A reversal of a reversal puts the original effect back while both journals read void —
        // the ledger would agree with nothing on screen. Correct with a fresh manual journal.
        if ($journal->source_type === 'journal_void') {
            throw new \DomainException("{$journal->journal_no} is itself a reversal — voiding it would silently un-reverse {$journal->reference_no}. Post a correcting manual journal instead.");
        }

        // A day-end journal is one entry for a whole drawer: un-posting it would need to un-raise
        // every receivable, un-count every collection and re-open a counted drawer. The correction
        // is a return or an adjustment in the next session, never a void.
        if ($journal->source_type === 'pos_session') {
            throw new \DomainException("{$journal->journal_no} is a POS day-end posting — correct it with a return or an adjustment in the next session, not a void.");
        }

        if (in_array($journal->source_type, self::MANAGED_SOURCES, true) && ! $viaSourceDoc) {
            throw new \DomainException(
                "{$journal->journal_no} was posted by {$this->sourceLabel($journal)}, so it cannot be voided from the journal screen — that would reverse the ledger and leave the source document standing. Reverse it from the source document instead."
            );
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

            // The ledger is reversed; the subledger goes with it, or the whole void rolls back.
            $this->reverseSubledger($journal);

            $journal->update(['status' => 'void', 'voided_by' => auth()->id(), 'voided_at' => now(), 'void_reason' => $reason]);

            return $journal->fresh();
        });
    }

    /**
     * Translate the two races the database backstops into words an operator can act on.
     *
     * Anything else — a genuine constraint the caller tripped — is rethrown untouched: this only
     * speaks for the duplicate-entry family, detected across SQLite ("UNIQUE constraint failed:
     * acc_journals.source_type, ...") and MySQL ("Duplicate entry ... for key
     * 'acc_journals_source_unique'").
     */
    private function throwIfDuplicateEntry(QueryException $e, array $data): void
    {
        $message = $e->getMessage();
        $isDuplicate = str_contains($message, 'UNIQUE constraint failed')
            || str_contains($message, 'Duplicate entry')
            || str_contains($message, 'unique constraint');

        if (! $isDuplicate) {
            return;
        }

        // Only the constraint part may be tested, never the whole message: the message also
        // carries the full INSERT column list, which names every column including journal_no —
        // matching that would mistake every violation for a number collision.
        $constraint = $message;

        if (preg_match('/UNIQUE constraint failed:\s*([^\n(]+)/', $message, $m)) {
            $constraint = $m[1]; // SQLite: "acc_journals.source_type, acc_journals.source_id"
        } elseif (preg_match("/for key '([^']+)'/", $message, $m)) {
            $constraint = $m[1]; // MySQL: the key name
        } elseif (preg_match('/unique constraint "([^"]+)"/i', $message, $m)) {
            $constraint = $m[1]; // Postgres: the constraint name
        }

        if (str_contains($constraint, 'journal_no')) {
            throw new \DomainException(
                'That journal number was just taken by another posting — retry the post. Nothing was written.'
            );
        }

        if (str_contains($constraint, 'source_type') || str_contains($constraint, 'source_unique')) {
            $label = $this->sourceLabelFromData($data);

            throw new \DomainException(
                "{$label} already has its journal — it was posted in another window just now. Refresh and check before retrying; nothing was written twice."
            );
        }
    }

    private function sourceLabelFromData(array $data): string
    {
        $ref = $data['reference_no'] ?? null;

        if ($ref === null && isset($data['source_type'], $data['source_id'])) {
            // Name the document the operator knows: the journal that won the race carries it.
            $ref = Journal::where('source_type', $data['source_type'])
                ->where('source_id', $data['source_id'])
                ->value('reference_no');
        }

        $ref ??= '#'.($data['source_id'] ?? '?');

        return match ($data['source_type'] ?? null) {
            'purchase_bill' => "Vendor bill {$ref}",
            'purchase_payment' => "Vendor payment {$ref}",
            'purchase_return' => "Purchase return {$ref}",
            'purchase_receipt' => "GRN {$ref}",
            'sales_invoice' => "Sales invoice {$ref}",
            'sales_payment' => "Customer collection {$ref}",
            'sales_return' => "Sales return {$ref}",
            'payroll_sheet' => "Payroll sheet {$ref}",
            'payroll_slip' => "Payroll slip {$ref}",
            'pos_session' => "POS session {$ref}",
            'journal_void' => "That void {$ref}",
            default => "That document ({$data['source_type']} #{$data['source_id']})",
        };
    }

    private function sourceLabel(Journal $journal): string
    {
        return match ($journal->source_type) {
            'purchase_bill' => "vendor bill {$journal->reference_no}",
            'purchase_payment' => "vendor payment {$journal->reference_no}",
            'purchase_return' => "purchase return {$journal->reference_no}",
            'purchase_receipt' => "GRN {$journal->reference_no}",
            'sales_invoice' => "sales invoice {$journal->reference_no}",
            'sales_payment' => "customer collection {$journal->reference_no}",
            'sales_return' => "sales return {$journal->reference_no}",
            'payroll_sheet' => "payroll sheet {$journal->reference_no}",
            'payroll_slip' => "payroll slip {$journal->reference_no}",
            default => "its source document ({$journal->source_type} #{$journal->source_id})",
        };
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
            $control = $byCode((string) config('accounts.defaults.payable_code', '2100'));
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
                $query = Payable::query();
                if ($journal->source_type === 'purchase_payment') {
                    // This payment was explicitly made against a bill; FIFO could close a
                    // different vendor bill and make the purchase and AP registers disagree.
                    $billJournalId = DB::table('purchase_payments as p')
                        ->join('purchase_bills as b', 'b.id', '=', 'p.bill_id')
                        ->where('p.id', $journal->source_id)->value('b.journal_id');
                    if (! $billJournalId) {
                        throw new \DomainException('Payment has no posted vendor bill journal to settle.');
                    }
                    $query->where('journal_id', $billJournalId);
                } elseif ($journal->source_type === 'purchase_return') {
                    // A debit note reduces what we owe on ITS bill, not the oldest one. Without
                    // the link the credit lands on whatever happens to be open and the returned
                    // bill keeps showing the full balance.
                    $billJournalId = DB::table('purchase_returns as r')
                        ->join('purchase_bills as b', 'b.id', '=', 'r.bill_id')
                        ->where('r.id', $journal->source_id)->value('b.journal_id');
                    if ($billJournalId) {
                        $query->where('journal_id', $billJournalId);
                    }
                }
                $allowNegative = $journal->source_type === 'purchase_return';
                $unapplied = $this->settleOpen($query, $journal, $debit, 'bill_date', $allowNegative);
                if ($journal->source_type === 'purchase_payment' && $unapplied > 0.005) {
                    throw new \DomainException('Payment exceeds the linked payable balance; reconcile the bill before paying.');
                }
            }

            return;
        }

        if ($journal->partner_type === 'customer') {
            $control = $byCode((string) config('accounts.defaults.receivable_code', '1100'));
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
                $query = Receivable::query();
                if ($journal->source_type === 'sales_payment') {
                    // Same rule as the vendor side: a collection was taken against one invoice,
                    // so it settles that invoice's receivable. FIFO used to close the oldest
                    // invoice instead, and the invoice and AR registers disagreed about who owed
                    // what — the exact defect the purchase side carried before it was targeted.
                    $invoiceJournalId = DB::table('sales_payments as p')
                        ->join('sales_invoices as b', 'b.id', '=', 'p.invoice_id')
                        ->where('p.id', $journal->source_id)->value('b.journal_id');
                    if (! $invoiceJournalId) {
                        throw new \DomainException('Collection has no posted sales invoice journal to settle.');
                    }
                    $query->where('journal_id', $invoiceJournalId);
                } elseif ($journal->source_type === 'sales_return') {
                    // A credit note reduces ITS invoice. FIFO would quietly pay down some other
                    // invoice and leave the returned one looking fully owed.
                    $invoiceJournalId = DB::table('sales_returns as r')
                        ->join('sales_invoices as b', 'b.id', '=', 'r.invoice_id')
                        ->where('r.id', $journal->source_id)->value('b.journal_id');
                    if ($invoiceJournalId) {
                        $query->where('journal_id', $invoiceJournalId);
                    }
                }
                $allowNegative = $journal->source_type === 'sales_return';
                $unapplied = $this->settleOpen($query, $journal, $credit, 'invoice_date', $allowNegative);
                if ($journal->source_type === 'sales_payment' && $unapplied > 0.005) {
                    throw new \DomainException('Collection exceeds the linked receivable balance; reconcile the invoice before collecting.');
                }
            }
        }
    }

    /**
     * Apply money received/paid against the oldest open documents, oldest first.
     *
     * Anything left over after every open document is settled is deliberately dropped rather than
     * turned into a negative-balance row: a partner who has paid more than they owed is an advance,
     * which belongs in its own accounting treatment, not in the receivables list as a phantom debt.
     * Returns are the exception: a credit/debit note against an already-settled document IS the
     * refund the business now owes (or is owed), so the remainder is applied to the oldest document
     * anyway and its balance goes negative. Dropping it instead would move the control account
     * without moving the subledger, which is the one disagreement this method exists to prevent.
     */
    private function settleOpen($query, Journal $journal, float $amount, string $dateColumn, bool $allowNegative = false): float
    {
        $open = $query->where('partner_id', $journal->partner_id)
            ->whereNotIn('status', ['paid', 'cancelled', 'void'])
            ->orderBy($dateColumn)->orderBy('id')->lockForUpdate()->get();

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
            $this->applySettlement($doc, $applied);
            $left = round($left - $applied, 2);
        }

        // A return with nowhere left to go: the business owes (or is owed) the remainder, and the
        // subledger has to say so on the oldest document rather than swallow it.
        if ($allowNegative && $left > 0.005 && $open->isNotEmpty()) {
            $this->applySettlement($open->first(), $left);
            $left = 0;
        }

        return $left;
    }

    /**
     * Record one settlement application. `balance` is `amount - paid_amount` (or
     * `received_amount`) as a stored generated column, so writing it is both rejected by the
     * database and unnecessary: record what was paid and let it recompute.
     */
    private function applySettlement($doc, float $applied): void
    {
        $paidColumn = array_key_exists('paid_amount', (array) $doc->getAttributes()) ? 'paid_amount' : 'received_amount';
        $newPaid = round((float) $doc->{$paidColumn} + $applied, 2);
        $doc->update([
            $paidColumn => $newPaid,
            'status' => $this->subledgerStatus((float) $doc->amount, $newPaid),
        ]);
    }

    /**
     * One status rule for every writer: fully covered is paid (a negative balance is a refund due,
     * not a debt), untouched is open, anything between is partial.
     */
    private function subledgerStatus(float $amount, float $paid): string
    {
        if (round($amount - $paid, 2) <= 0.005) {
            return 'paid';
        }
        if ($paid <= 0.005) {
            return 'open';
        }

        return 'partial';
    }

    /**
     * Reverse what syncPayableReceivable() did for this journal, exactly.
     *
     * A void used to reverse the ledger and leave the subledger standing: voiding a bill journal
     * kept its payable open, so AP aging showed a debt the books no longer carried, and voiding a
     * payment journal kept the settlement applied, so the bill looked paid for money that had been
     * un-posted. Both directions are reversed here, under the same locks, inside the void
     * transaction — a refusal rolls the whole void back.
     *
     * Settlement journals are only reversible through their source document. A manual journal that
     * paid down payables went through FIFO, and FIFO cannot be un-applied exactly: the rows it
     * touched are not recorded anywhere. Refusing loudly beats guessing.
     */
    private function reverseSubledger(Journal $journal): void
    {
        if (! $journal->partner_id || ! $journal->partner_type) {
            return;
        }

        $lines = $journal->lines()->with('account')->get();
        $byCode = fn (string $code) => $lines->first(fn ($l) => $l->account && $l->account->code === $code);

        if ($journal->partner_type === 'vendor') {
            $control = $byCode((string) config('accounts.defaults.payable_code', '2100'));
            if ($control === null) {
                return;
            }
            $debit = (float) $control->debit;
            $credit = (float) $control->credit;

            if ($credit > 0) {
                // This journal opened a payable (a bill). It can only be un-opened untouched:
                // applied money belongs to a payment journal that must be reversed first.
                $row = Payable::where('journal_id', $journal->id)->lockForUpdate()->first();
                if ($row !== null) {
                    if ((float) $row->paid_amount > 0.005) {
                        throw new \DomainException(
                            "Payable {$row->bill_no} has payments applied, so its journal cannot be voided. Reverse the payments first."
                        );
                    }
                    $row->update(['status' => 'void']);
                }

                return;
            }

            if ($debit > 0) {
                $billJournalId = match ($journal->source_type) {
                    'purchase_payment' => DB::table('purchase_payments as p')
                        ->join('purchase_bills as b', 'b.id', '=', 'p.bill_id')
                        ->where('p.id', $journal->source_id)->value('b.journal_id'),
                    'purchase_return' => DB::table('purchase_returns as r')
                        ->join('purchase_bills as b', 'b.id', '=', 'r.bill_id')
                        ->where('r.id', $journal->source_id)->value('b.journal_id'),
                    default => null,
                };
                if (! $billJournalId) {
                    throw new \DomainException(
                        'This journal paid down vendor balances without a linked bill, so it cannot be un-applied exactly. Correct it with a manual journal instead.'
                    );
                }
                $row = Payable::where('journal_id', $billJournalId)->lockForUpdate()->firstOrFail();
                $newPaid = round((float) $row->paid_amount - $debit, 2);
                if ($newPaid < -0.005) {
                    throw new \DomainException(
                        "Payable {$row->bill_no} would go to {$newPaid} by reversing this journal — the subledger and the payment disagree. Reconcile before voiding."
                    );
                }
                $row->update([
                    'paid_amount' => $newPaid,
                    'status' => $row->status === 'void' ? 'void' : $this->subledgerStatus((float) $row->amount, $newPaid),
                ]);
            }

            return;
        }

        if ($journal->partner_type === 'customer') {
            $control = $byCode((string) config('accounts.defaults.receivable_code', '1100'));
            if ($control === null) {
                return;
            }
            $debit = (float) $control->debit;
            $credit = (float) $control->credit;

            if ($debit > 0) {
                $row = Receivable::where('journal_id', $journal->id)->lockForUpdate()->first();
                if ($row !== null) {
                    if ((float) $row->received_amount > 0.005) {
                        throw new \DomainException(
                            "Receivable {$row->invoice_no} has collections applied, so its journal cannot be voided. Reverse the collections first."
                        );
                    }
                    $row->update(['status' => 'void']);
                }

                return;
            }

            if ($credit > 0) {
                $invoiceJournalId = match ($journal->source_type) {
                    'sales_payment' => DB::table('sales_payments as p')
                        ->join('sales_invoices as b', 'b.id', '=', 'p.invoice_id')
                        ->where('p.id', $journal->source_id)->value('b.journal_id'),
                    'sales_return' => DB::table('sales_returns as r')
                        ->join('sales_invoices as b', 'b.id', '=', 'r.invoice_id')
                        ->where('r.id', $journal->source_id)->value('b.journal_id'),
                    default => null,
                };
                if (! $invoiceJournalId) {
                    throw new \DomainException(
                        'This journal settled customer balances without a linked invoice, so it cannot be un-applied exactly. Correct it with a manual journal instead.'
                    );
                }
                $row = Receivable::where('journal_id', $invoiceJournalId)->lockForUpdate()->firstOrFail();
                $newReceived = round((float) $row->received_amount - $credit, 2);
                if ($newReceived < -0.005) {
                    throw new \DomainException(
                        "Receivable {$row->invoice_no} would go to {$newReceived} by reversing this journal — the subledger and the collection disagree. Reconcile before voiding."
                    );
                }
                $row->update([
                    'received_amount' => $newReceived,
                    'status' => $row->status === 'void' ? 'void' : $this->subledgerStatus((float) $row->amount, $newReceived),
                ]);
            }
        }
    }
}
