<?php

namespace Modules\SALES\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\ACCOUNTS\DTO\JournalData;
use Modules\ACCOUNTS\DTO\JournalLineData;
use Modules\ACCOUNTS\Interfaces\AccountsPostingProviderInterface;
use Modules\ACCOUNTS\Models\Bank;
use Modules\ACCOUNTS\Models\Journal;
use Modules\ACCOUNTS\Models\Receivable;
use Modules\ACCOUNTS\Support\ChartOfAccounts;
use Modules\PARTNER\Models\Partner;
use Modules\SALES\Models\PosSession;

/**
 * The POS day-end posting: one balanced journal for everything a session took.
 *
 * Before this existed, `PosService::sell()` created and posted a sales invoice per counter sale. That
 * is not how a till works. It put one ledger entry per transaction in the books — hundreds a day for a
 * busy store — and it made each instant-cash sale raise an open receivable, because
 * `JournalService::syncPayableReceivable()` books the whole `total_debit` of any `SV` journal carrying
 * a customer, whether or not the customer already paid. AR aging was full of settled counter sales,
 * and the drawer count at close reconciled to nothing, because closing a session wrote no accounting
 * at all (report item 39).
 *
 * The model here is the standard Z-report. Stock leaves the shelf when the sale happens — inventory
 * has to be true at the counter, not at midnight — and the money is accounted once, at the close:
 *
 *     Dr  Cash in Hand            cash takings
 *     Dr  Bank (each GL account)  card / bank / mobile takings, per settlement account
 *     Dr  Accounts Receivable     credit sales raised today
 *     Cr  Sales Revenue           net of discount
 *     Cr  VAT Payable             output VAT on the day
 *     Cr  Accounts Receivable     dues collected at the counter today
 *     Dr  COGS                    the day's cost, snapshotted when each sale left the shelf
 *     Cr  Inventory               the same
 *
 * which balances because cash and bank takings are the day's net-plus-VAT for settled sales plus the
 * collections, and the credit sales' net-plus-VAT is exactly the receivable raised.
 *
 * Two deliberate choices worth stating:
 *
 *  - **The journal carries no partner.** It spans every customer who walked up. Naming one would make
 *    `syncPayableReceivable()` book the entire day as that customer's debt — the defect above, one
 *    level up. Receivables are written here instead, one row per credit customer, all keyed to this
 *    journal, so the subledger and the ledger agree without the generic path guessing.
 *
 *  - **Debits and credits for receivables are shown gross, not netted.** A day that both sells on
 *    credit and collects old credit did two different things, and the Z-report the manager reads at
 *    the close shows both. Netting them would hide the day's collections inside its credit sales.
 *
 * Every total is accumulated in integer cents. These figures are summed from dozens of decimal
 * columns and then handed to a journal that refuses to post unless it balances: summing them as floats
 * is how a day ends a cent out and the close fails at 10pm with a till full of cash and a queue behind
 * it.
 */
class PosDayEndPostingService
{
    /**
     * Aggregate the session, post its one journal, raise the receivables for its credit sales, and
     * stamp the totals onto the session.
     *
     * Returns the totals it wrote, as decimal strings, so the caller can reconcile the drawer
     * against them without a second read.
     *
     * @return array<string, string>
     */
    public function postSession(PosSession $session): array
    {
        if ($session->isPosted()) {
            throw new \DomainException(
                "Session {$session->session_no} has already been posted to the ledger (journal {$session->journal_id}). "
                .'A day can only be closed once; correct it with a return or an adjustment in the next session.'
            );
        }

        if (! app()->bound(AccountsPostingProviderInterface::class)) {
            throw new \DomainException(
                "Cannot close session {$session->session_no}: the accounting module is not registered, so the day's "
                .'takings would be recorded in the till and nowhere in the books. The session was left open.'
            );
        }

        return DB::transaction(function () use ($session): array {
            // Locked and re-checked: two closes racing each other used to post the same day twice,
            // and the only caller holds this same lock — this is the backstop for the next one.
            $session = PosSession::whereKey($session->getKey())->lockForUpdate()->first() ?? $session;

            if ($session->isPosted()) {
                throw new \DomainException(
                    "Session {$session->session_no} has already been posted to the ledger (journal {$session->journal_id}). "
                    .'A day can only be closed once; correct it with a return or an adjustment in the next session.'
                );
            }

            $session->load(['sales', 'collections.sale']);

            $totals = $this->aggregate($session);

            $journal = $this->post($session, $totals);

            $session->update([
                'journal_id' => $journal?->id,
                'posted_at' => $journal?->id ? now() : null,
                'discount_total' => $this->money($totals['discount']),
                'net_sales_total' => $this->money($totals['net']),
                'vat_total' => $this->money($totals['vat']),
                'cash_total' => $this->money($totals['cash']),
                'bank_total' => $this->money($totals['bank']),
                'due_total' => $this->money($totals['due']),
                'collected_total' => $this->money($totals['collected']),
                'cogs_total' => $this->money($totals['cogs']),
                'void_count' => $totals['voids'],
            ]);

            if ($journal !== null) {
                $this->raiseReceivables($session, $journal, $totals['dueByCustomer']);
            }

            return [
                'cash_total' => (string) $this->money($totals['cash']),
                'bank_total' => (string) $this->money($totals['bank']),
                'due_total' => (string) $this->money($totals['due']),
                'collected_total' => (string) $this->money($totals['collected']),
                'net_sales_total' => (string) $this->money($totals['net']),
                'vat_total' => (string) $this->money($totals['vat']),
                'cogs_total' => (string) $this->money($totals['cogs']),
                'expected_cash' => (string) $this->money($totals['openingCash'] + $totals['cash']),
            ];
        });
    }

    /**
     * Everything the day did, in integer cents.
     *
     * @return array{
     *     net: int, vat: int, discount: int, cogs: int, due: int, cash: int, bank: int,
     *     collected: int, openingCash: int, voids: int, sales: int,
     *     moneyAccounts: array<int, int>, dueByCustomer: array<int, int>
     * }
     */
    private function aggregate(PosSession $session): array
    {
        $net = $vat = $discount = $cogs = $due = $cash = $bank = $collected = 0;
        $salesCount = $voids = 0;
        $moneyAccounts = [];
        $dueByCustomer = [];

        $cashAccountId = $this->cashAccountId();

        foreach ($session->sales as $sale) {
            if ($sale->status === 'void') {
                $voids++;

                continue;
            }

            $salesCount++;

            $lineDiscount = $this->cents($sale->discount_amount);
            $discount += $lineDiscount;
            $net += $this->cents($sale->subtotal) - $lineDiscount;
            $vat += $this->cents($sale->vat_amount);
            $cogs += $this->cents($sale->cost_total);

            $saleDue = $this->cents($sale->due_amount);
            $due += $saleDue;

            // The journal below debits AR for the gross due and the receivables are raised one row
            // per customer: a due owed by nobody would sit in the ledger with no subledger row to
            // chase, collect or age. The till refuses such a sale at the counter, so reaching here
            // means the data was written around the service — refuse loudly rather than post a debt
            // nobody owns.
            if ($saleDue > 0 && ! $sale->customer_id) {
                throw new \DomainException(
                    "Cannot close session {$session->session_no}: POS {$sale->pos_no} is owed ".
                    $this->money($saleDue).' BDT by no customer. Attach the customer to the sale first — a debt without a debtor cannot be posted.'
                );
            }

            $saleCash = $this->cents($sale->cash_amount);
            $cash += $saleCash;

            $saleBank = $this->cents($sale->bank_amount);
            $bank += $saleBank;

            // Tender settles into a GL account: cash into the cash account, card/bank/mobile into
            // the bank's own account. There is deliberately no fallback to cash: posting card
            // takings as drawer cash is what used to make the Z-report reconcile to a count that
            // never happened. The till only accepts electronic money into an active, linked bank,
            // so a missing one here means the bank was deactivated or unlinked mid-day — say so.
            if ($saleCash > 0) {
                $moneyAccounts[$cashAccountId] = ($moneyAccounts[$cashAccountId] ?? 0) + $saleCash;
            }

            if ($saleBank > 0) {
                $accountId = $this->bankAccountId($sale->bank_id, "POS {$sale->pos_no} ({$session->session_no})");
                $moneyAccounts[$accountId] = ($moneyAccounts[$accountId] ?? 0) + $saleBank;
            }

            if ($saleDue > 0 && $sale->customer_id) {
                $dueByCustomer[$sale->customer_id] = ($dueByCustomer[$sale->customer_id] ?? 0) + $saleDue;
            }
        }

        foreach ($session->collections as $collection) {
            $amount = $this->cents($collection->amount);

            if ($amount <= 0) {
                continue;
            }

            // A collection against a cancelled sale is money with no sale behind it: the void took
            // the revenue out of the day but this row would still credit receivables and debit
            // cash. Voids are refused once money has been collected, so this is data written around
            // the service — repair it, do not post it.
            $collectionSale = $collection->sale;

            if ($collectionSale === null || $collectionSale->status === 'void') {
                throw new \DomainException(
                    "Cannot close session {$session->session_no}: collection {$collection->collection_no} was taken against ".
                    ($collectionSale === null ? 'a sale that no longer exists' : "POS {$collectionSale->pos_no}, which is void")
                    .'. Reverse the collection through the customer ledger first.'
                );
            }

            $collected += $amount;

            if ($collection->isCash()) {
                $cash += $amount;
                $moneyAccounts[$cashAccountId] = ($moneyAccounts[$cashAccountId] ?? 0) + $amount;
            } else {
                $bank += $amount;
                $accountId = $this->bankAccountId($collection->bank_id, "collection {$collection->collection_no} ({$session->session_no})");
                $moneyAccounts[$accountId] = ($moneyAccounts[$accountId] ?? 0) + $amount;
            }
        }

        return [
            'net' => $net, 'vat' => $vat, 'discount' => $discount, 'cogs' => $cogs, 'due' => $due,
            'cash' => $cash, 'bank' => $bank, 'collected' => $collected,
            'openingCash' => $this->cents($session->opening_cash),
            'voids' => $voids, 'sales' => $salesCount,
            'moneyAccounts' => $moneyAccounts, 'dueByCustomer' => $dueByCustomer,
        ];
    }

    /**
     * Post the one journal, or return null when the drawer did nothing all day.
     *
     * An empty session is not an error — a till can open and close without a sale — but posting a
     * zero journal would be: `JournalData` refuses a zero total, and a ledger full of empty day-end
     * entries is noise that hides the days that matter.
     */
    private function post(PosSession $session, array $totals): ?Journal
    {
        $posting = app(AccountsPostingProviderInterface::class);
        $purpose = "the POS day-end posting for session {$session->session_no}";

        $lines = [];

        foreach ($totals['moneyAccounts'] as $accountId => $amountCents) {
            if ($amountCents <= 0) {
                continue;
            }

            $lines[] = new JournalLineData(
                accountId: $accountId,
                debit: $this->money($amountCents),
                description: "POS {$session->session_no} takings",
            );
        }

        if ($totals['due'] > 0) {
            $arId = ChartOfAccounts::requireId($posting, config('accounts.defaults.receivable_code', '1100'), $purpose);
            $lines[] = new JournalLineData(
                accountId: $arId,
                debit: $this->money($totals['due']),
                description: "POS {$session->session_no} credit sales",
            );
        }

        if ($totals['net'] > 0) {
            $salesId = ChartOfAccounts::requireId($posting, config('accounts.defaults.sales_code', '4001'), $purpose);
            $lines[] = new JournalLineData(
                accountId: $salesId,
                credit: $this->money($totals['net']),
                description: "POS {$session->session_no} sales revenue ({$totals['sales']} transactions)",
            );
        }

        if ($totals['vat'] > 0) {
            $vatId = ChartOfAccounts::requireId($posting, config('accounts.defaults.vat_payable_code', '2110'), $purpose);
            $lines[] = new JournalLineData(
                accountId: $vatId,
                credit: $this->money($totals['vat']),
                description: "POS {$session->session_no} output VAT",
            );
        }

        // Gross, not netted against the debit above: collecting an old debt and selling on credit
        // today are different facts and the Z-report shows both.
        if ($totals['collected'] > 0) {
            $arId = ChartOfAccounts::requireId($posting, config('accounts.defaults.receivable_code', '1100'), $purpose);
            $lines[] = new JournalLineData(
                accountId: $arId,
                credit: $this->money($totals['collected']),
                description: "POS {$session->session_no} dues collected at the counter",
            );
        }

        if ($totals['cogs'] > 0) {
            $cogsId = ChartOfAccounts::requireId($posting, config('accounts.defaults.cogs_code', '5001'), $purpose);
            $invId = ChartOfAccounts::requireId($posting, config('accounts.defaults.inventory_code', '1200'), $purpose);
            $cost = $this->money($totals['cogs']);
            $lines[] = new JournalLineData(accountId: $cogsId, debit: $cost, description: "POS {$session->session_no} cost of goods sold");
            $lines[] = new JournalLineData(accountId: $invId, credit: $cost, description: "POS {$session->session_no} inventory out");
        }

        if (count($lines) < 2) {
            return null;
        }

        $date = $session->sales->max('sale_date') ?? now()->toDateString();

        $dto = new JournalData(
            journalDate: Carbon::parse($date)->format('Y-m-d'),
            type: 'SV',
            referenceNo: $session->session_no,
            narration: "POS day-end {$session->session_no} — {$session->sales->count()} sale(s), "
                .$totals['sales'].' posted, '.$totals['voids'].' voided',
            lines: $lines,
            // No partner, deliberately: this journal spans every customer of the day, and naming one
            // would make syncPayableReceivable() book the whole day as that customer's debt.
            partnerId: null,
            partnerType: null,
            sourceType: 'pos_session',
            sourceId: $session->id,
        );

        return $posting->postJournal($dto);
    }

    /**
     * One receivable per credit customer, keyed to the day-end journal.
     *
     * Two rules, both of which the ledger above already assumes:
     *
     *  - **The due date comes from the customer's own `payment_terms_days`**, falling back to
     *    `sales.pos.credit_days` only for a customer with no terms set (the column defaults to 0, and
     *    every seeded walk-in is in that state). A global default would date a 7-day customer's debt
     *    30 days out, which ages it wrong and hides it from the chase list for three weeks.
     *  - **Dues collected anywhere against this day's sales are born part-paid.** The journal posts
     *    Dr AR for the gross due, so a receivable showing `received_amount` 0 would disagree with the
     *    entry that created it — and a due can be collected at a *later* till before this session
     *    closes. Counting only this drawer's collections used to lose those: the other session's
     *    journal credited AR for money this receivable then showed as still owed. So the pool is keyed
     *    on the *sale's* session, not the collection's, across every drawer. Money taken here against
     *    an earlier day's sale still settles that day's receivable in `collectDue()`, and this pool
     *    never sees it — the two paths are mutually exclusive by whether the selling day had posted
     *    when the money was taken, so nothing settles twice.
     *
     * @param  array<int, int>  $dueByCustomer  partner id => cents
     */
    private function raiseReceivables(PosSession $session, Journal $journal, array $dueByCustomer): void
    {
        $date = Carbon::parse($journal->journal_date);
        $fallbackDays = (int) config('sales.pos.credit_days', 30);

        // Every collection taken against this session's sales, in whatever drawer took it.
        $collectedHere = DB::table('pos_collections as c')
            ->join('pos_sales as s', 's.id', '=', 'c.pos_sale_id')
            ->where('s.session_id', $session->id)
            ->whereNotNull('s.customer_id')
            ->whereNull('s.deleted_at')
            ->groupBy('s.customer_id')
            ->selectRaw('s.customer_id AS customer_id, ROUND(SUM(c.amount) * 100) AS cents')
            ->pluck('cents', 'customer_id');

        foreach ($dueByCustomer as $partnerId => $amountCents) {
            if ($amountCents <= 0) {
                continue;
            }

            $terms = (int) (Partner::find($partnerId)?->payment_terms_days ?? 0);
            $receivedCents = min((int) ($collectedHere[(int) $partnerId] ?? 0), $amountCents);

            $receivable = Receivable::create([
                'partner_id' => $partnerId,
                'journal_id' => $journal->id,
                'invoice_no' => $session->session_no,
                'invoice_date' => $date->toDateString(),
                'due_date' => $date->copy()->addDays($terms > 0 ? $terms : $fallbackDays)->toDateString(),
                'amount' => $this->money($amountCents),
                'received_amount' => $this->money($receivedCents),
                'status' => $receivedCents <= 0 ? 'open' : ($receivedCents >= $amountCents ? 'paid' : 'partial'),
            ]);

            // Link the collections this receivable was born paid by — including the ones taken in
            // other drawers, which `collectDue()` could not link because this row did not exist yet.
            // Already-linked rows (settled directly at collect time) are never touched.
            if ($receivedCents > 0) {
                // A subquery, not a joined update: SQLite — this app's database — refuses the join.
                DB::table('pos_collections')
                    ->whereNull('receivable_id')
                    ->whereIn('pos_sale_id', function ($query) use ($session, $partnerId): void {
                        $query->select('id')->from('pos_sales')
                            ->where('session_id', $session->id)
                            ->where('customer_id', $partnerId);
                    })
                    ->update(['receivable_id' => $receivable->id]);
            }
        }
    }

    /** The receivable raised for one customer by one session's day-end posting. */
    public function receivableFor(PosSession $session, int $partnerId): ?Receivable
    {
        if (! $session->journal_id) {
            return null;
        }

        return Receivable::where('journal_id', $session->journal_id)
            ->where('partner_id', $partnerId)
            ->first();
    }

    private function cashAccountId(): int
    {
        $posting = app(AccountsPostingProviderInterface::class);

        return ChartOfAccounts::requireId(
            $posting,
            config('accounts.defaults.cash_code', '1001'),
            'the POS day-end posting, which settles cash takings into the cash account'
        );
    }

    /**
     * The GL account electronic money settles into — required, never defaulted.
     *
     * Falling back to cash when the bank was missing used to post card takings as drawer cash, and
     * the Z-report reconciled to a count that never happened. The till only takes electronic money
     * into an active bank linked to a ledger account, so failing here means the bank was deactivated
     * or unlinked between the sale and the close: the fix is to restore the bank, not to guess where
     * its money went.
     */
    private function bankAccountId(?int $bankId, string $context): int
    {
        $bank = $bankId ? Bank::active()->find($bankId) : null;

        if ($bank === null || ! $bank->account_id) {
            throw new \DomainException(
                "Cannot close: {$context} was taken electronically into ".
                ($bank === null ? 'a bank or wallet that no longer exists or is no longer active' : "{$bank->name}, which is no longer linked to a ledger account")
                .'. Reactivate or re-link the bank and close again — its money cannot be posted to cash.'
            );
        }

        return (int) $bank->account_id;
    }

    /** A decimal column read back, in integer cents. */
    private function cents(mixed $value): int
    {
        return (int) round(((float) $value) * 100);
    }

    private function money(int $cents): float
    {
        return round($cents / 100, 2);
    }
}
