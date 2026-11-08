<?php

namespace Modules\SALES\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\ACCOUNTS\Models\Receivable;
use Modules\CORE\Support\DataTableQuery;
use Modules\INVENTORY\Interfaces\InventoryStockProviderInterface;
use Modules\INVENTORY\Interfaces\InventoryValuationProviderInterface;
use Modules\INVENTORY\Models\Product;
use Modules\INVENTORY\Services\ValuationService;
use Modules\PARTNER\Models\Partner;
use Modules\SALES\DTO\PosSaleData;
use Modules\SALES\Interfaces\PosRepositoryInterface;
use Modules\SALES\Models\PosCollection;
use Modules\SALES\Models\PosSale;
use Modules\SALES\Models\PosSession;

/**
 * The till.
 *
 * A POS sale is a sale, not an invoice. It records the transaction and takes the stock off the shelf
 * immediately — inventory has to be true at the counter, not at midnight — and it stops there. The
 * accounting happens once, when the drawer closes: `PosDayEndPostingService` writes a single balanced
 * journal for the day's cash, card, credit sales, VAT, collections and cost of goods (report item 39).
 *
 * This service used to create *and post* a `sales_invoices` row for every counter sale. Two things
 * followed from that, both of them accounting defects rather than noise: each instant-cash sale raised
 * an open receivable, because `JournalService::syncPayableReceivable()` books the whole debit of any
 * `SV` journal that names a customer, so AR aging filled with money nobody owed; and the drawer count
 * at the close reconciled to nothing, because closing a session wrote no accounting at all.
 *
 * `InvoiceService` is untouched: a sales invoice still posts its own journal when it is posted, which
 * is correct for credit and counter-trade sales that are not till transactions.
 */
class PosService
{
    public function __construct(
        private readonly PosRepositoryInterface $repo,
        private readonly PosDayEndPostingService $dayEnd,
    ) {}

    public function sessionsPaginate(DataTableQuery $dt): LengthAwarePaginator
    {
        return $this->repo->sessionsPaginate($dt);
    }

    public function salesPaginate(DataTableQuery $dt): LengthAwarePaginator
    {
        return $this->repo->salesPaginate($dt);
    }

    public function findSessionOrFail(int $id): PosSession
    {
        return $this->repo->findSessionOrFail($id);
    }

    public function findSaleOrFail(int $id): PosSale
    {
        return $this->repo->findSaleOrFail($id);
    }

    public function openSession(array $data): PosSession
    {
        return $this->repo->openSession($data);
    }

    /**
     * Close the drawer: post the day, then reconcile the count and close.
     *
     * The posting comes first and inside the same transaction, so a day whose journal cannot be
     * written — a closed fiscal year, a missing account — leaves the session open with nothing
     * recorded. Closing first and posting after is how a till ends the day with its money in the
     * drawer and nothing in the books.
     */
    public function closeSession(PosSession $s, array $data): PosSession
    {
        return DB::transaction(function () use ($s, $data): PosSession {
            $session = $s->fresh() ?? $s;

            if (! $session->isOpen()) {
                throw new \DomainException("Session {$session->session_no} is already {$session->status}. Only an open drawer can be closed.");
            }

            $totals = $this->dayEnd->postSession($session);
            $session->refresh();

            $expectedCents = (int) round((float) $totals['expected_cash'] * 100);
            $update = [
                'expected_cash' => round($expectedCents / 100, 2),
                'status' => 'closed',
                'closed_by' => auth()->id(),
                'closed_at' => now(),
            ];

            // The counted cash is optional: a session can be closed without a count, in which case
            // there is no variance to report and pretending there is one would be worse than null.
            if (array_key_exists('closing_cash', $data) && $data['closing_cash'] !== null && $data['closing_cash'] !== '') {
                $closingCents = (int) round(((float) $data['closing_cash']) * 100);
                $update['closing_cash'] = round($closingCents / 100, 2);
                $update['cash_difference'] = round(($closingCents - $expectedCents) / 100, 2);
            }

            if (array_key_exists('notes', $data)) {
                $update['notes'] = $data['notes'];
            }

            $session->update($update);

            return $session->fresh();
        });
    }

    /**
     * Ring up a sale.
     *
     * Writes the sale and its lines, snapshots the cost of what left the shelf, and takes the stock
     * out. No invoice and no journal: the day-end posting owns the ledger.
     */
    public function sell(PosSaleData $dto): PosSale
    {
        return DB::transaction(function () use ($dto): PosSale {
            // A named session must be open. Selling into a closed drawer puts money in a till whose
            // day is already in the ledger, where nothing will ever account for it. A sale with no
            // session at all is still accepted — `PosSessionAggregateTest` pins that behaviour, and
            // the collection report surfaces those sales as unattached rather than letting them hide.
            $session = $dto->sessionId !== null ? PosSession::find($dto->sessionId) : null;

            if ($dto->sessionId !== null && $session === null) {
                throw new \DomainException("Session {$dto->sessionId} does not exist.");
            }

            if ($session !== null && ! $session->isOpen()) {
                throw new \DomainException(
                    "Session {$session->session_no} is {$session->status}, so it cannot take a sale. "
                    .'Open a session for this till first: a sale in a closed drawer is money the ledger has already counted.'
                );
            }

            $items = [];
            $subtotalCents = 0;
            $discountCents = 0;
            $vatCents = 0;
            $costCents = 0;

            foreach ($dto->lines as $line) {
                $product = Product::findOrFail($line->productId);

                $grossCents = (int) round($line->qty * $line->unitPrice * 100);
                $lineDiscountCents = (int) round($grossCents * ($line->discountPct / 100));
                $netCents = $grossCents - $lineDiscountCents;
                $lineVatCents = (int) round($netCents * ($line->vatRate / 100));

                // Snapshotted before the stock moves, because a FIFO warehouse values what leaves at
                // the layers it had a moment ago. Re-deriving it at the close would cost the day at
                // whatever the next delivery happened to charge.
                $unitCost = $this->unitCostFor($product, $line->variantId, $dto->warehouseId, $line->qty);
                $lineCostCents = (int) round($unitCost * $line->qty * 100);

                $items[] = [
                    'product_id' => $line->productId,
                    'variant_id' => $line->variantId,
                    'product_name' => $product->name,
                    'sku' => $product->sku,
                    'qty' => $line->qty,
                    'unit_price' => $line->unitPrice,
                    'discount_pct' => $line->discountPct,
                    'line_total' => round(($netCents + $lineVatCents) / 100, 2),
                    'cost_price' => $unitCost,
                ];

                $subtotalCents += $grossCents;
                $discountCents += $lineDiscountCents;
                $vatCents += $lineVatCents;
                $costCents += $lineCostCents;
            }

            $totalCents = $subtotalCents - $discountCents + $vatCents;

            // Payment mapping: cash stays in the drawer, bank/bkash/card settle into a bank account,
            // split is a manual division of the two, and due is a credit sale to a named customer.
            $method = $dto->paymentMethod;
            $isDue = $method === 'due';

            if ($isDue && ! $dto->customerId) {
                throw new \DomainException(
                    'A credit sale at the till needs a customer. The due is raised against somebody in the '
                    .'receivables ledger at the close of the day, and a debt owed by "Walk-in Customer" can '
                    .'never be chased, collected or aged.'
                );
            }

            /*
             * A credit sale is not necessarily an unpaid one. The operator takes whatever the
             * customer has in hand and the rest becomes the due, so `paid_amount` is a tendered
             * figure clamped to [0, total] rather than the hardcoded 0 it used to be — the split
             * guard below even told cashiers to "take what was paid and ring the rest up as due",
             * which the till could not record. On a settled sale the same clamp does not apply:
             * paid is the total, and anything handed over above it is change.
             */
            $paidCents = $isDue
                ? max(0, min((int) round($dto->paidAmount * 100), $totalCents))
                : $totalCents;
            $dueCents = $totalCents - $paidCents;
            $tenderedCents = max(0, (int) round($dto->tenderedAmount * 100));
            $changeCents = (! $isDue && $tenderedCents > $totalCents) ? $tenderedCents - $totalCents : 0;

            // Credit control runs before the sale is written and before the stock moves. Refusing
            // afterwards would leave the customer holding goods the business has no receivable for.
            // It is the *owed* remainder that has to fit the limit, not the whole sale: a customer
            // who pays half now is exposing the business to half.
            if ($dueCents > 0) {
                $this->assertWithinCreditLimit($dto->customerId, $dueCents);
            }

            $isBankLike = in_array($method, ['bank', 'bkash', 'card'], true);
            $cashCents = $method === 'split' ? (int) round($dto->cashAmount * 100) : ($method === 'cash' ? $totalCents : 0);
            $bankCents = $method === 'split' ? (int) round($dto->bankAmount * 100) : ($isBankLike ? $totalCents : 0);
            // Money taken on a part-paid credit sale is cash in the drawer unless a bank was named,
            // and it has to land in `cash_amount` or the day-end drawer count misses it.
            if ($isDue && $paidCents > 0) {
                if ($dto->bankId) {
                    $bankCents = $paidCents;
                } else {
                    $cashCents = $paidCents;
                }
            }

            if ($method === 'split' && abs(($cashCents + $bankCents) - $totalCents) > 1) {
                throw new \DomainException(sprintf(
                    'Split amounts (%s cash + %s bank) must equal the total %s BDT. A part-paid sale is a credit sale: take what was paid and ring the rest up as due.',
                    number_format($cashCents / 100, 2),
                    number_format($bankCents / 100, 2),
                    number_format($totalCents / 100, 2),
                ));
            }

            $sale = $this->repo->createSale([
                'session_id' => $dto->sessionId,
                'customer_id' => $dto->customerId,
                'customer_name' => $dto->customerName,
                'warehouse_id' => $dto->warehouseId,
                'sale_date' => now()->toDateString(),
                'subtotal' => round($subtotalCents / 100, 2),
                'discount_amount' => round($discountCents / 100, 2),
                'vat_amount' => round($vatCents / 100, 2),
                'total_amount' => round($totalCents / 100, 2),
                'paid_amount' => round($paidCents / 100, 2),
                'due_amount' => round($dueCents / 100, 2),
                'collected_amount' => 0,
                'change_amount' => round($changeCents / 100, 2),
                'payment_method' => $method,
                'cash_amount' => round($cashCents / 100, 2),
                'bank_amount' => round($bankCents / 100, 2),
                'cost_total' => round($costCents / 100, 2),
                'bank_id' => $dto->bankId,
                'status' => 'completed',
                'items' => $items,
            ]);

            // Stock leaves now. Waiting for the day-end would let the till sell the same unit twice.
            if (app()->bound(InventoryStockProviderInterface::class)) {
                $stockItems = collect($items)->map(fn (array $it): array => [
                    'product_id' => $it['product_id'],
                    'variant_id' => $it['variant_id'],
                    'warehouse_id' => $dto->warehouseId,
                    'qty' => (float) $it['qty'],
                    'unit_cost' => (float) $it['cost_price'],
                ])->all();

                app(InventoryStockProviderInterface::class)->deductStock($stockItems, $sale->pos_no, 'pos_sale');
            }

            return $sale->fresh(['items']);
        });
    }

    /**
     * Take money at the counter against a credit sale raised earlier.
     *
     * Writes no journal of its own. The collection belongs to the drawer that took it, and that
     * drawer's day-end posting carries it as Dr cash/bank, Cr receivables — which is what makes the
     * day's collection total user-wise, and keeps one posting per day.
     *
     * @param  array{amount: float|string, method?: string, bank_id?: int|null, session_id: int, notes?: string|null}  $data
     */
    public function collectDue(PosSale $sale, array $data): PosCollection
    {
        $session = PosSession::find((int) ($data['session_id'] ?? 0));

        if ($session === null) {
            throw new \DomainException('A collection must name the open session whose drawer is taking the money.');
        }

        if (! $session->isOpen()) {
            throw new \DomainException(
                "Session {$session->session_no} is {$session->status}, and its day is already in the ledger, so it cannot take money. "
                .'Open a session for this till and collect it there.'
            );
        }

        $amountCents = (int) round(((float) ($data['amount'] ?? 0)) * 100);

        if ($amountCents <= 0) {
            throw new \DomainException('A collection must be a positive amount.');
        }

        $outstandingCents = $sale->outstandingDueCents();

        if ($outstandingCents <= 0) {
            throw new \DomainException("POS {$sale->pos_no} has nothing outstanding to collect.");
        }

        if ($amountCents > $outstandingCents) {
            throw new \DomainException(sprintf(
                'POS %s owes %s BDT, so %s cannot be collected against it. Take the payment on account through the customer ledger instead.',
                $sale->pos_no,
                number_format($outstandingCents / 100, 2),
                number_format($amountCents / 100, 2),
            ));
        }

        $method = $data['method'] ?? 'cash';

        if (! in_array($method, ['cash', 'bank', 'bkash', 'card'], true)) {
            throw new \DomainException("Unknown collection method '{$method}'.");
        }

        return DB::transaction(function () use ($sale, $session, $amountCents, $method, $data): PosCollection {
            $collection = $this->repo->createCollection([
                'session_id' => $session->id,
                'pos_sale_id' => $sale->id,
                'partner_id' => $sale->customer_id,
                'amount' => round($amountCents / 100, 2),
                'method' => $method,
                'bank_id' => $data['bank_id'] ?? null,
                'collected_by' => auth()->id(),
                'collected_at' => now(),
                'notes' => $data['notes'] ?? null,
            ]);

            $sale->increment('collected_amount', round($amountCents / 100, 2));

            // The receivable was raised by the day-end posting of the session that SOLD on credit,
            // which is usually an earlier one. If that day has not been posted yet — credit sold and
            // collected inside the same open session — there is nothing to settle yet, and the
            // receivable is born part-paid when the day closes.
            $receivable = $sale->session_id
                ? $this->dayEnd->receivableFor(PosSession::find($sale->session_id) ?? $session, (int) $sale->customer_id)
                : null;

            if ($receivable !== null) {
                $receivedCents = (int) round((float) $receivable->received_amount * 100) + $amountCents;
                $owedCents = (int) round((float) $receivable->amount * 100);

                $receivable->update([
                    'received_amount' => round($receivedCents / 100, 2),
                    'status' => $receivedCents >= $owedCents ? 'paid' : 'partial',
                ]);

                $collection->update(['receivable_id' => $receivable->id]);
            }

            return $collection->fresh();
        });
    }

    /**
     * Undo a sale before its day is posted.
     *
     * Once the session has closed, the day's revenue, VAT and cash are in the ledger. Voiding then
     * would put the stock back and leave the money in the books, with no reversal anywhere — so it is
     * refused, and the correction is a sales return in the current session, which posts its own
     * reversal.
     */
    public function void(PosSale $sale): PosSale
    {
        if ($sale->status === 'void') {
            throw new \DomainException('Already voided.');
        }

        $session = $sale->session_id ? PosSession::find($sale->session_id) : null;

        if ($session !== null && ! $session->isOpen()) {
            throw new \DomainException(
                "POS {$sale->pos_no} belongs to session {$session->session_no}, which is closed and its day has been "
                .($session->isPosted() ? 'posted to the ledger' : 'closed').'. Voiding it now would put the stock back '
                ."and leave the day's revenue, VAT and cash in the books with nothing reversing them. Raise a sales "
                .'return in the current session instead.'
            );
        }

        return DB::transaction(function () use ($sale): PosSale {
            if (app()->bound(InventoryStockProviderInterface::class)) {
                $sale->load('items');
                $items = $sale->items->map(fn ($it): array => [
                    'product_id' => $it->product_id,
                    'variant_id' => $it->variant_id,
                    'warehouse_id' => $sale->warehouse_id,
                    'qty' => (float) $it->qty,
                ])->all();

                // Wrapped, not swallowed (report item 26): a failure here must reach the operator as
                // a named reason and roll the transaction back. Silencing it is what used to leave a
                // sale marked void with its stock still off the shelf.
                try {
                    app(InventoryStockProviderInterface::class)->restock($items, $sale->pos_no, 'pos_void');
                } catch (\Throwable $e) {
                    throw new \DomainException(
                        "Cannot void POS {$sale->pos_no}: the stock could not be returned to the shelf ({$e->getMessage()}). "
                        .'The sale was left completed and nothing was changed.'
                    );
                }
            }

            $sale->update(['status' => 'void']);

            // The live counters come back down, and the void is counted rather than disappearing:
            // the Z-report shows what was rung up and what was cancelled, which is the pair a
            // supervisor actually audits.
            if ($sale->session_id) {
                PosSession::where('id', $sale->session_id)->decrement(
                    'total_sales',
                    $sale->total_amount,
                    [
                        'transactions_count' => DB::raw('transactions_count - 1'),
                        'void_count' => DB::raw('void_count + 1'),
                    ],
                );
            }

            return $sale->fresh();
        });
    }

    /**
     * Refuse a credit sale that would take the customer past their credit limit.
     *
     * `credit_limit <= 0` means no limit is set, which is not the same as a limit of zero: a partner
     * with no limit can be sold to on credit indefinitely, and every seeded walk-in customer is in
     * that state. Exposure is what the receivables ledger already says is owed — open, part-paid and
     * overdue — plus the dues rung up in drawers that have not posted yet, plus this sale. Counting
     * only the ledger would let one open session sell past the limit as many times as it liked,
     * because a till sale does not become a receivable until its day-end posts.
     */
    private function assertWithinCreditLimit(?int $customerId, int $dueCents): void
    {
        if ($dueCents <= 0 || $customerId === null) {
            return;
        }

        $partner = Partner::find($customerId);

        if ($partner === null) {
            return;
        }

        $limitCents = (int) round(((float) $partner->credit_limit) * 100);

        if ($limitCents <= 0) {
            return;
        }

        $owedCents = (int) round(((float) Receivable::open()->where('partner_id', $customerId)->sum('balance')) * 100);

        $openSessions = PosSession::where('status', 'open')->pluck('id');
        $unpostedCents = (int) round(((float) PosSale::query()
            ->where('customer_id', $customerId)
            ->where('status', '<>', 'void')
            ->whereRaw('due_amount > collected_amount')
            ->where(fn ($q) => $q->whereNull('session_id')->orWhereIn('session_id', $openSessions))
            ->sum(DB::raw('due_amount - collected_amount'))) * 100);

        $exposureCents = $owedCents + $unpostedCents + $dueCents;

        if ($exposureCents > $limitCents) {
            throw new \DomainException(sprintf(
                'This sale would take %s past their credit limit of %s BDT. They already owe %s BDT%s, '
                .'and this sale adds %s BDT, which is %s BDT over. Take a payment at the counter first, '
                .'raise the limit on the customer record, or ring the sale up as paid.',
                $partner->name,
                number_format($limitCents / 100, 2),
                number_format(($owedCents + $unpostedCents) / 100, 2),
                $unpostedCents > 0 ? ' (including '.number_format($unpostedCents / 100, 2).' BDT not yet posted from open drawers)' : '',
                number_format($dueCents / 100, 2),
                number_format(($exposureCents - $limitCents) / 100, 2),
            ));
        }
    }

    /**
     * What one unit leaving the shelf costs, following the same order `InvoiceService::post()` uses:
     * the product's own cost when it carries one, otherwise the warehouse valuation, with FIFO layers
     * preferred for a FIFO product.
     */
    private function unitCostFor(Product $product, ?int $variantId, int $warehouseId, float $qty): float
    {
        $hasCost = $product->cost_price !== null && (float) $product->cost_price > 0.001;

        if ($hasCost) {
            return (float) $product->cost_price;
        }

        if (! app()->bound(InventoryValuationProviderInterface::class)) {
            return 0.0;
        }

        $valuator = app(InventoryValuationProviderInterface::class);

        if ($product->valuation_method === 'fifo' && app()->bound(ValuationService::class)) {
            $fifo = app(ValuationService::class)->getFifoCost($product->id, $variantId, $warehouseId, $qty);
            $cost = (float) ($fifo['unit_cost'] ?? 0);

            if ($cost > 0.001) {
                return $cost;
            }
        }

        return $valuator->getUnitCost($product->id, $variantId, $warehouseId);
    }
}
