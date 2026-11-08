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
            $session->load(['sales', 'collections']);

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

            $saleCash = $this->cents($sale->cash_amount);
            $cash += $saleCash;

            $saleBank = $this->cents($sale->bank_amount);
            $bank += $saleBank;

            // Tender settles into a GL account: cash into the cash account, card/bank/mobile into
            // the bank's own account. A bank with no GL account falls back to cash, which is what
            // InvoiceService does — `accounts.defaults.bank_code` is 1002, a *group*, and posting
            // to a group header is refused, so it cannot be the fallback.
            if ($saleCash > 0) {
                $moneyAccounts[$cashAccountId] = ($moneyAccounts[$cashAccountId] ?? 0) + $saleCash;
            }

            if ($saleBank > 0) {
                $accountId = $this->bankAccountId($sale->bank_id) ?? $cashAccountId;
                $moneyAccounts[$accountId] = ($moneyAccounts[$accountId] ?? 0) + $saleBank;
            }

            if ($saleDue > 0 && $sale->customer_id) {
                $dueByCustomer[$sale->customer_id] = ($dueByCustomer[$sale->customer_id] ?? 0) + $saleDue;
            }
        }

        foreach ($session->collections as $collection) {
            $amount = $this->cents($collection->amount);
            $collected += $amount;

            if ($collection->isCash()) {
                $cash += $amount;
                $moneyAccounts[$cashAccountId] = ($moneyAccounts[$cashAccountId] ?? 0) + $amount;
            } else {
                $bank += $amount;
                $accountId = $this->bankAccountId($collection->bank_id) ?? $cashAccountId;
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
     *  - **Dues collected in this same drawer are born part-paid.** The journal posts Dr AR for the
     *    gross due and Cr AR for the gross collected, so a receivable showing `received_amount` 0
     *    would disagree with the entry that created it. Only collections against a sale *of this
     *    session* count: money taken here against an earlier day's sale settles that day's receivable
     *    in `collectDue()`, and counting it again would settle the same money twice.
     *
     * @param  array<int, int>  $dueByCustomer  partner id => cents
     */
    private function raiseReceivables(PosSession $session, Journal $journal, array $dueByCustomer): void
    {
        $date = Carbon::parse($journal->journal_date);
        $fallbackDays = (int) config('sales.pos.credit_days', 30);

        $collectedHere = [];

        foreach ($session->collections as $collection) {
            $sale = $collection->sale;

            if ($sale === null || (int) $sale->session_id !== (int) $session->id || $sale->customer_id === null) {
                continue;
            }

            $key = (int) $sale->customer_id;
            $collectedHere[$key] = ($collectedHere[$key] ?? 0) + $this->cents($collection->amount);
        }

        foreach ($dueByCustomer as $partnerId => $amountCents) {
            if ($amountCents <= 0) {
                continue;
            }

            $terms = (int) (Partner::find($partnerId)?->payment_terms_days ?? 0);
            $receivedCents = min($collectedHere[(int) $partnerId] ?? 0, $amountCents);

            Receivable::create([
                'partner_id' => $partnerId,
                'journal_id' => $journal->id,
                'invoice_no' => $session->session_no,
                'invoice_date' => $date->toDateString(),
                'due_date' => $date->copy()->addDays($terms > 0 ? $terms : $fallbackDays)->toDateString(),
                'amount' => $this->money($amountCents),
                'received_amount' => $this->money($receivedCents),
                'status' => $receivedCents <= 0 ? 'open' : ($receivedCents >= $amountCents ? 'paid' : 'partial'),
            ]);
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

    private function bankAccountId(?int $bankId): ?int
    {
        if (! $bankId) {
            return null;
        }

        $accountId = Bank::where('id', $bankId)->value('account_id');

        return $accountId !== null ? (int) $accountId : null;
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
