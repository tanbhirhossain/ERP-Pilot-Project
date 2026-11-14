<?php

namespace Modules\ACCOUNTS\Services;

use Illuminate\Support\Facades\DB;
use Modules\ACCOUNTS\DTO\JournalData;
use Modules\ACCOUNTS\DTO\JournalLineData;
use Modules\ACCOUNTS\Interfaces\AccountsPostingProviderInterface;
use Modules\ACCOUNTS\Models\Payable;
use Modules\ACCOUNTS\Models\Receivable;
use Modules\ACCOUNTS\Support\ChartOfAccounts;
use Modules\CORE\Models\OpeningBalanceBatch;
use Modules\INVENTORY\Actions\ReceiveStockAction;

/**
 * Go-live take-on (Phase 1 — F4).
 *
 * A real company arrives with balances: a trial balance, unpaid customer
 * invoices, unpaid vendor bills, and stock on the shelf. Before this service
 * there was no way to enter any of them except hand-keying journals, so the
 * first month's reports were wrong from day one.
 *
 * Four batch types, each idempotent by reference (a double-click cannot double
 * the opening ledger) and each posting through the normal fiscal-gated provider:
 *
 *   coa   — account balances as of a date; the net difference lands in Opening
 *           Balance Equity (3900) so the entry always balances.
 *   ar    — per-customer open invoices → Receivable + Dr AR / Cr 3900.
 *   ap    — per-vendor open bills → Payable + Dr 3900 / Cr AP.
 *   stock — per product/warehouse qty+cost → stock IN + Dr Inventory / Cr 3900.
 *
 * The 3900 account is seeded by AccountsDemoSeeder (never a migration). Once
 * take-on is complete and audited, the accountant journals 3900 → 3200 Retained
 * Earnings to close the take-on suspense.
 */
class OpeningBalanceService
{
    public const EQUITY_CODE = '3900';

    public function __construct(private readonly AccountsPostingProviderInterface $posting) {}

    /**
     * COA opening balances.
     *
     * @param  array<int, array{account_id:int, debit:float, credit:float}>  $lines
     */
    public function postCoa(string $reference, string $asOf, array $lines): OpeningBalanceBatch
    {
        $this->assertFreshReference($reference);

        $equityId = ChartOfAccounts::requireId($this->posting, self::EQUITY_CODE, 'opening balance equity');

        $journalLines = [];
        $net = 0.0; // debit-positive

        foreach ($lines as $line) {
            $debit = round((float) ($line['debit'] ?? 0), 2);
            $credit = round((float) ($line['credit'] ?? 0), 2);

            if ($debit <= 0 && $credit <= 0) {
                continue;
            }

            $journalLines[] = new JournalLineData(
                accountId: (int) $line['account_id'],
                debit: $debit,
                credit: $credit,
                description: 'Opening balance',
            );
            $net += $debit - $credit;
        }

        if ($journalLines === []) {
            throw new \DomainException('No opening balances to post — every line is zero.');
        }

        // The net difference goes to Opening Balance Equity so the entry balances.
        $net = round($net, 2);
        if (abs($net) > 0.005) {
            $journalLines[] = $net > 0
                ? new JournalLineData(accountId: $equityId, debit: 0, credit: $net, description: 'Opening balance equity (net)')
                : new JournalLineData(accountId: $equityId, debit: -$net, credit: 0, description: 'Opening balance equity (net)');
        }

        return DB::transaction(function () use ($reference, $asOf, $journalLines): OpeningBalanceBatch {
            $journal = $this->posting->postJournal(new JournalData(
                journalDate: $asOf,
                type: 'JV',
                referenceNo: $reference,
                narration: 'Opening balance take-on (COA)',
                lines: $journalLines,
                sourceType: 'opening_balance',
            ));

            return OpeningBalanceBatch::create([
                'type' => 'coa',
                'reference' => $reference,
                'as_of_date' => $asOf,
                'journal_id' => $journal->id,
                'total_amount' => array_sum(array_map(fn ($l) => $l->debit, $journalLines)),
                'created_by' => auth()->id(),
                'meta' => ['lines' => count($journalLines)],
            ]);
        });
    }

    /**
     * AR take-on: per-customer open invoices.
     *
     * @param  array<int, array{partner_id:int, invoice_no:string, invoice_date:string, due_date:?string, amount:float}>  $rows
     */
    public function postAr(string $reference, string $asOf, array $rows): OpeningBalanceBatch
    {
        $this->assertFreshReference($reference);
        $arId = ChartOfAccounts::requireId($this->posting, config('accounts.defaults.receivable_code', '1100'), 'accounts receivable');
        $equityId = ChartOfAccounts::requireId($this->posting, self::EQUITY_CODE, 'opening balance equity');

        return DB::transaction(function () use ($reference, $asOf, $rows, $arId, $equityId): OpeningBalanceBatch {
            $total = 0.0;
            $journalIds = [];

            foreach ($rows as $row) {
                $amount = round((float) $row['amount'], 2);
                if ($amount <= 0) {
                    continue;
                }

                $journal = $this->posting->postJournal(new JournalData(
                    journalDate: $row['invoice_date'] ?: $asOf,
                    type: 'JV',
                    referenceNo: $row['invoice_no'],
                    narration: 'Opening AR take-on',
                    lines: [
                        new JournalLineData(accountId: $arId, debit: $amount, credit: 0, description: 'Opening receivable', partnerId: (int) $row['partner_id'], partnerType: 'customer'),
                        new JournalLineData(accountId: $equityId, debit: 0, credit: $amount, description: 'Opening balance equity'),
                    ],
                    partnerId: (int) $row['partner_id'],
                    partnerType: 'customer',
                    sourceType: 'opening_balance',
                ));

                Receivable::create([
                    'partner_id' => (int) $row['partner_id'],
                    'journal_id' => $journal->id,
                    'invoice_no' => $row['invoice_no'],
                    'invoice_date' => $row['invoice_date'] ?: $asOf,
                    'due_date' => $row['due_date'] ?? null,
                    'amount' => $amount,
                    'received_amount' => 0,
                    'status' => 'open',
                ]);

                $journalIds[] = $journal->id;
                $total += $amount;
            }

            if ($journalIds === []) {
                throw new \DomainException('No AR rows to post — every amount is zero.');
            }

            return OpeningBalanceBatch::create([
                'type' => 'ar',
                'reference' => $reference,
                'as_of_date' => $asOf,
                'journal_id' => $journalIds[0],
                'total_amount' => $total,
                'created_by' => auth()->id(),
                'meta' => ['rows' => count($journalIds), 'journal_ids' => $journalIds],
            ]);
        });
    }

    /**
     * AP take-on: per-vendor open bills.
     *
     * @param  array<int, array{partner_id:int, bill_no:string, bill_date:string, due_date:?string, amount:float}>  $rows
     */
    public function postAp(string $reference, string $asOf, array $rows): OpeningBalanceBatch
    {
        $this->assertFreshReference($reference);
        $apId = ChartOfAccounts::requireId($this->posting, config('accounts.defaults.payable_code', '2100'), 'accounts payable');
        $equityId = ChartOfAccounts::requireId($this->posting, self::EQUITY_CODE, 'opening balance equity');

        return DB::transaction(function () use ($reference, $asOf, $rows, $apId, $equityId): OpeningBalanceBatch {
            $total = 0.0;
            $journalIds = [];

            foreach ($rows as $row) {
                $amount = round((float) $row['amount'], 2);
                if ($amount <= 0) {
                    continue;
                }

                $journal = $this->posting->postJournal(new JournalData(
                    journalDate: $row['bill_date'] ?: $asOf,
                    type: 'JV',
                    referenceNo: $row['bill_no'],
                    narration: 'Opening AP take-on',
                    lines: [
                        new JournalLineData(accountId: $equityId, debit: $amount, credit: 0, description: 'Opening balance equity'),
                        new JournalLineData(accountId: $apId, debit: 0, credit: $amount, description: 'Opening payable', partnerId: (int) $row['partner_id'], partnerType: 'vendor'),
                    ],
                    partnerId: (int) $row['partner_id'],
                    partnerType: 'vendor',
                    sourceType: 'opening_balance',
                ));

                Payable::create([
                    'partner_id' => (int) $row['partner_id'],
                    'journal_id' => $journal->id,
                    'bill_no' => $row['bill_no'],
                    'bill_date' => $row['bill_date'] ?: $asOf,
                    'due_date' => $row['due_date'] ?? null,
                    'amount' => $amount,
                    'paid_amount' => 0,
                    'status' => 'open',
                ]);

                $journalIds[] = $journal->id;
                $total += $amount;
            }

            if ($journalIds === []) {
                throw new \DomainException('No AP rows to post — every amount is zero.');
            }

            return OpeningBalanceBatch::create([
                'type' => 'ap',
                'reference' => $reference,
                'as_of_date' => $asOf,
                'journal_id' => $journalIds[0],
                'total_amount' => $total,
                'created_by' => auth()->id(),
                'meta' => ['rows' => count($journalIds), 'journal_ids' => $journalIds],
            ]);
        });
    }

    /**
     * Stock take-on: per product/warehouse qty + unit cost.
     *
     * @param  array<int, array{product_id:int, warehouse_id:int, qty:float, unit_cost:float}>  $rows
     */
    public function postStock(string $reference, string $asOf, array $rows): OpeningBalanceBatch
    {
        $this->assertFreshReference($reference);
        $invId = ChartOfAccounts::requireId($this->posting, config('accounts.defaults.inventory_code', '1200'), 'inventory');
        $equityId = ChartOfAccounts::requireId($this->posting, self::EQUITY_CODE, 'opening balance equity');

        return DB::transaction(function () use ($reference, $asOf, $rows, $invId, $equityId): OpeningBalanceBatch {
            $items = [];
            $total = 0.0;

            foreach ($rows as $row) {
                $qty = (float) $row['qty'];
                $cost = (float) $row['unit_cost'];
                if ($qty <= 0) {
                    continue;
                }
                $items[] = [
                    'product_id' => (int) $row['product_id'],
                    'warehouse_id' => (int) $row['warehouse_id'],
                    'qty' => $qty,
                    'unit_cost' => $cost,
                ];
                $total += round($qty * $cost, 2);
            }

            if ($items === []) {
                throw new \DomainException('No stock rows to post — every quantity is zero.');
            }

            // Stock IN at cost (weighted-average recalc happens in StockService).
            app(ReceiveStockAction::class)->execute($items, 'opening', $reference, 'Opening stock take-on');

            $journal = null;
            if ($total > 0.005) {
                $journal = $this->posting->postJournal(new JournalData(
                    journalDate: $asOf,
                    type: 'JV',
                    referenceNo: $reference,
                    narration: 'Opening stock take-on',
                    lines: [
                        new JournalLineData(accountId: $invId, debit: $total, credit: 0, description: 'Opening inventory'),
                        new JournalLineData(accountId: $equityId, debit: 0, credit: $total, description: 'Opening balance equity'),
                    ],
                    sourceType: 'opening_balance',
                ));
            }

            return OpeningBalanceBatch::create([
                'type' => 'stock',
                'reference' => $reference,
                'as_of_date' => $asOf,
                'journal_id' => $journal?->id,
                'total_amount' => $total,
                'created_by' => auth()->id(),
                'meta' => ['rows' => count($items)],
            ]);
        });
    }

    private function assertFreshReference(string $reference): void
    {
        if (trim($reference) === '') {
            throw new \DomainException('A batch reference is required (e.g. OB-2026-001).');
        }

        if (OpeningBalanceBatch::where('reference', $reference)->exists()) {
            throw new \DomainException("Opening-balance batch \"{$reference}\" was already posted. Use a new reference — re-posting would double the opening ledger.");
        }
    }
}
