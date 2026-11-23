<?php

namespace Modules\SALES\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\ACCOUNTS\DTO\JournalData;
use Modules\ACCOUNTS\DTO\JournalLineData;
use Modules\ACCOUNTS\Interfaces\AccountsPostingProviderInterface;
use Modules\ACCOUNTS\Models\Bank;
use Modules\ACCOUNTS\Support\ChartOfAccounts;
use Modules\CORE\Services\ApprovalService;
use Modules\CORE\Support\DataTableQuery;
use Modules\INVENTORY\Interfaces\InventoryStockProviderInterface;
use Modules\INVENTORY\Interfaces\InventoryValuationProviderInterface;
use Modules\INVENTORY\Models\Product;
use Modules\INVENTORY\Services\ValuationService;
use Modules\SALES\Interfaces\InvoiceRepositoryInterface;
use Modules\SALES\Models\Delivery;
use Modules\SALES\Models\Invoice;
use Modules\SALES\Models\Order;
use Modules\SALES\Models\OrderItem;
use Modules\SALES\Models\Payment;
use Modules\SALES\Support\LinePricer;

class InvoiceService
{
    public function __construct(private readonly InvoiceRepositoryInterface $repo) {}

    public function paginate(DataTableQuery $dt): LengthAwarePaginator
    {
        return $this->repo->paginate($dt);
    }

    public function exportBuilder(DataTableQuery $dt): Builder
    {
        return $this->repo->exportBuilder($dt);
    }

    public function findOrFail(int $id): Invoice
    {
        return $this->repo->findOrFail($id);
    }

    public function create(array $data): Invoice
    {
        return $this->repo->create($this->enrichTotals($data));
    }

    public function update(Invoice $inv, array $data): Invoice
    {
        return $this->repo->update($inv, $this->enrichTotals($data));
    }

    public function delete(Invoice $inv): void
    {
        $this->repo->delete($inv);
    }

    public function post(Invoice $invoice): Invoice
    {
        if (! $invoice->isDraft()) {
            throw new \DomainException('Only draft can be posted.');
        }

        // An invoice charges for goods: every line carries a positive quantity. A zero or
        // negative line would post a journal that still balances while deducting stock the wrong
        // way. Credits travel as a sales return, not as a negative invoice line.
        $invoice->loadMissing('items');
        if ($invoice->items->isEmpty()) {
            throw new \DomainException("Invoice {$invoice->invoice_no} has no lines — add what was sold before posting.");
        }
        foreach ($invoice->items as $line) {
            if ((float) $line->qty <= 0) {
                throw new \DomainException("Invoice {$invoice->invoice_no} line \"{$line->product_name}\" has quantity {$line->qty} — invoiced quantities must be positive.");
            }
        }

        // Generic approval matrix (Phase 1) — before the transaction so the
        // pending request survives a refusal. No-op without an active policy.
        app(ApprovalService::class)
            ->assertAllowed('sales_invoice', $invoice->id, (float) $invoice->total_amount, $invoice->invoice_no);

        // Credit control (Phase 1): only the unpaid portion is new credit. A
        // cash-paid invoice adds nothing to exposure. Before the transaction so
        // a refusal never deducts stock.
        if (config('sales.credit.enforce_on_invoice', true)) {
            $due = (float) $invoice->total_amount - (float) $invoice->paid_amount;
            app(CreditControlService::class)->assertWithinLimit($invoice->customer_id, $due, 'invoice');
        }

        // An invoice against a dead order is either a mistake or a back-door around the
        // cancellation. Draft orders are not promises yet; cancelled and closed ones are over.
        if ($invoice->order_id) {
            $orderState = Order::whereKey($invoice->order_id)->value('status');
            if (in_array($orderState, ['draft', 'cancelled', 'closed'], true)) {
                throw new \DomainException("Order {$invoice->order?->order_no} is {$orderState} — only confirmed orders can be invoiced.");
            }
        }

        return DB::transaction(function () use ($invoice): Invoice {
            $invoice->load(['items.product', 'customer']);

            // Refuse to invoice more than the order still owes, and work out per line what this
            // invoice newly takes off the shelf versus what a delivery already carried out.
            $plan = $this->planInvoiceQuantities($invoice);

            // 1) Snapshot cost_price BEFORE deduct — FIFO-aware (cost based on oldest layers)
            if (app()->bound(AccountsPostingProviderInterface::class)) {
                // we will create journal later, but need cost first
                $prePosting = null;
            }
            // FIFO cost must be captured before stock is deducted
            foreach ($invoice->items as $preItem) {
                $hasCost = $preItem->cost_price !== null && (float) $preItem->cost_price > 0.001;
                if (! $hasCost && app()->bound(InventoryValuationProviderInterface::class)) {
                    $valuator = app(InventoryValuationProviderInterface::class);
                    $product = $preItem->product ?? Product::find($preItem->product_id);
                    $cost = 0;
                    if ($product && $product->valuation_method === 'fifo' && app()->bound(ValuationService::class)) {
                        $vs = app(ValuationService::class);
                        $fifo = $vs->getFifoCost($preItem->product_id, $preItem->variant_id, $invoice->warehouse_id, (float) $preItem->qty);
                        $cost = (float) ($fifo['unit_cost'] ?? 0);
                        if ($cost <= 0.001) {
                            $cost = $valuator->getUnitCost($preItem->product_id, $preItem->variant_id, $invoice->warehouse_id);
                        }
                    } else {
                        $cost = $valuator->getUnitCost($preItem->product_id, $preItem->variant_id, $invoice->warehouse_id);
                    }
                    if ($cost > 0.001) {
                        $preItem->update(['cost_price' => $cost]);
                        $preItem->cost_price = $cost;
                    }
                }
            }
            $invoice->load('items');

            // 2) Deduct what this invoice newly takes off the shelf, per line. A line whose
            // goods already left on a delivery challan deducts nothing — deducting again would
            // take the same units off twice — while a line with no delivery deducts in full. The
            // old rule skipped the WHOLE invoice when ANY delivery existed for the order, so one
            // partially delivered order left every other line's stock standing while COGS still
            // posted: phantom stock and a ledger that disagreed with the shelf.
            //
            // The move carries the line's snapshotted cost, so the movement ledger shows what the
            // shelf actually gave up instead of a zero-cost row no audit can follow.
            if (config('sales.stock.deduct_on_invoice', true) && app()->bound(InventoryStockProviderInterface::class)) {
                $stockProvider = app(InventoryStockProviderInterface::class);
                $deductItems = [];
                foreach ($invoice->items as $it) {
                    $dq = (float) ($plan['deduct'][$it->id] ?? 0);
                    if ($dq > 0.0001) {
                        $deductItems[] = [
                            'product_id' => $it->product_id, 'variant_id' => $it->variant_id,
                            'warehouse_id' => $invoice->warehouse_id, 'qty' => $dq,
                            'unit_cost' => (float) ($it->cost_price ?? 0),
                        ];
                    }
                }
                if ($deductItems !== []) {
                    $stockProvider->deductStock($deductItems, $invoice->invoice_no, 'sales_invoice');
                }
                // Release exactly what left: the order's reservation covered these units until now.
                // Releasing the whole order line instead would free stock that is still sitting on
                // the shelf waiting for its delivery.
                if ($plan['release'] !== []) {
                    $stockProvider->releaseReservation($plan['release'], $invoice->invoice_no);
                }
            }

            // 3) Create COGS + Sales + AR + VAT journal via ACCOUNTS provider
            $journalId = null;
            if (app()->bound(AccountsPostingProviderInterface::class)) {
                $posting = app(AccountsPostingProviderInterface::class);
                // snapshot cost_price for COGS — FIFO-aware (already done above, keep fallback)
                foreach ($invoice->items as $item) {
                    $hasCost = $item->cost_price !== null && (float) $item->cost_price > 0.001;
                    if (! $hasCost) {
                        if (app()->bound(InventoryValuationProviderInterface::class)) {
                            $valuator = app(InventoryValuationProviderInterface::class);
                            $product = $item->product ?? Product::find($item->product_id);
                            $cost = 0;
                            if ($product && $product->valuation_method === 'fifo' && app()->bound(ValuationService::class)) {
                                $vs = app(ValuationService::class);
                                $fifo = $vs->getFifoCost($item->product_id, $item->variant_id, $invoice->warehouse_id, (float) $item->qty);
                                $cost = (float) ($fifo['unit_cost'] ?? 0);
                                if ($cost <= 0.001) {
                                    $cost = $valuator->getUnitCost($item->product_id, $item->variant_id, $invoice->warehouse_id);
                                }
                            } else {
                                $cost = $valuator->getUnitCost($item->product_id, $item->variant_id, $invoice->warehouse_id);
                            }
                            if ($cost > 0) {
                                $item->update(['cost_price' => $cost]);
                            }
                            $item->cost_price = $cost;
                        }
                    }
                }
                // refresh to ensure totalCost uses updated values
                $invoice->load('items');

                $purpose = "sales invoice {$invoice->invoice_no}";
                $salesCode = config('accounts.defaults.sales_code', '4001');
                $arCode = config('accounts.defaults.receivable_code', '1100');
                $cogsCode = config('accounts.defaults.cogs_code', '5001');
                $invCode = config('accounts.defaults.inventory_code', '1200');
                $vatCode = config('accounts.defaults.vat_payable_code', '2110');

                // Both are mandatory. The old `if ($arId && $salesId)` guard wrapped the whole
                // block while the status update sat outside it, so a chart missing either account
                // produced an invoice marked posted with `journal_id = null` — revenue recognised
                // in the subledger and nowhere in the books.
                $arId = ChartOfAccounts::requireId($posting, $arCode, $purpose);
                $salesId = ChartOfAccounts::requireId($posting, $salesCode, $purpose);

                $lines = [];
                $total = (float) $invoice->total_amount;
                $subtotal = (float) $invoice->subtotal - (float) $invoice->discount_amount;
                $vat = (float) $invoice->vat_amount;
                // AR Dr
                $lines[] = new JournalLineData(accountId: $arId, debit: $total, description: "Sales {$invoice->invoice_no} - {$invoice->customer?->name}");
                // Sales Cr
                $lines[] = new JournalLineData(accountId: $salesId, credit: $subtotal, description: "Sales revenue {$invoice->invoice_no}");
                // VAT Cr if any
                if ($vat > 0) {
                    $vatId = ChartOfAccounts::requireId($posting, $vatCode, $purpose);
                    $lines[] = new JournalLineData(accountId: $vatId, credit: $vat, description: "VAT {$invoice->invoice_no}");
                }

                // COGS Dr / Inventory Cr. This is the dangerous shape: dropping these two legs
                // leaves AR/Sales/VAT perfectly balanced, so nothing downstream can object — the
                // cost of the sale simply never reaches the ledger and profit is overstated. A
                // zero total is legitimate (service lines, or stock carried at no cost) and still
                // posts; a non-zero total with no COGS account does not.
                $totalCost = $invoice->items->sum(fn ($it) => (float) ($it->cost_price ?? 0) * (float) $it->qty);
                if ($totalCost > 0) {
                    $cogsId = ChartOfAccounts::requireId($posting, $cogsCode, $purpose);
                    $invId = ChartOfAccounts::requireId($posting, $invCode, $purpose);
                    $lines[] = new JournalLineData(accountId: $cogsId, debit: $totalCost, description: "COGS {$invoice->invoice_no}");
                    $lines[] = new JournalLineData(accountId: $invId, credit: $totalCost, description: "Inventory out {$invoice->invoice_no}");
                }

                $dto = new JournalData(journalDate: $invoice->invoice_date->format('Y-m-d'), type: 'SV', referenceNo: $invoice->invoice_no, narration: "Sales Invoice {$invoice->invoice_no}", lines: $lines, partnerId: $invoice->customer_id, partnerType: 'customer', sourceType: 'sales_invoice', sourceId: $invoice->id);
                $journalId = $posting->postJournal($dto)->id;
            }

            $invoice->update(['status' => 'posted', 'journal_id' => $journalId, 'posted_by' => auth()->id(), 'posted_at' => now()]);

            // update order invoiced amount
            if ($invoice->order_id) {
                $order = Order::find($invoice->order_id);
                if ($order) {
                    $order->increment('invoiced_amount', (float) $invoice->total_amount);
                    if ($plan['invoiced'] !== []) {
                        $ols = OrderItem::whereIn('id', array_keys($plan['invoiced']))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                        foreach ($plan['invoiced'] as $id => $qty) {
                            if ($qty > 0.0001 && $ols->has($id)) {
                                $ols->get($id)->increment('qty_invoiced', $qty);
                            }
                        }
                    }
                    $order->load('items');
                    $allInvoiced = $order->items->every(fn ($it) => (float) $it->qty_invoiced >= (float) $it->qty - 0.001);
                    $order->update(['status' => $allInvoiced ? 'invoiced' : 'partially_invoiced']);
                }
            }

            return $invoice->fresh(['items', 'journal']);
        });
    }

    public function recordPayment(Invoice $invoice, array $data): Payment
    {
        return DB::transaction(function () use ($invoice, $data): Payment {
            // Check the latest balance under a row lock, not a stale form/model. This also
            // serializes concurrent collections before either can move cash or the ledger.
            $invoice = Invoice::whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();
            if (! $invoice->isPosted()) {
                throw new \DomainException('Only posted invoices can receive payments.');
            }
            $amount = $data['amount'] ?? null;
            if (! is_numeric($amount) || (float) $amount <= 0 || round((float) $amount, 2) != (float) $amount) {
                throw new \DomainException('Payment must be a positive amount with at most two decimal places.');
            }
            $dueCents = (int) round((float) $invoice->total_amount * 100) - (int) round((float) $invoice->paid_amount * 100);
            // A credit note reduces what the customer owes without touching paid_amount — that
            // column is cash truth, what actually arrived — so the subledger is the final word on
            // what is still due. Without this a payment the invoice header allows would settle
            // against a smaller receivable and the surplus would vanish into FIFO.
            if ($invoice->journal_id) {
                $receivable = DB::table('acc_receivables')->where('journal_id', $invoice->journal_id)->lockForUpdate()->first();
                if ($receivable) {
                    $subledgerCents = (int) round(((float) $receivable->amount - (float) $receivable->received_amount) * 100);
                    $dueCents = min($dueCents, $subledgerCents);
                }
            }
            if ((int) round((float) $amount * 100) > $dueCents) {
                throw new \DomainException('Payment cannot exceed the outstanding invoice balance.');
            }
            $method = $data['method'] ?? 'cash';
            if (! in_array($method, ['cash', 'bank', 'bkash', 'card'], true)) {
                throw new \DomainException('Invalid payment method.');
            }
            $bank = null;
            if ($method !== 'cash') {
                $bank = Bank::active()->find($data['bank_id'] ?? null);
                if (! $bank || ! $bank->account_id) {
                    throw new \DomainException('Choose an active bank or wallet linked to a ledger account for non-cash payment.');
                }
            } else {
                $data['bank_id'] = null;
            }

            // The number comes from the row's own id, inside the same transaction: two tellers
            // collecting at the same instant cannot mint the same `PAY-` number the way
            // `max(id) + 1` let them, and the unique index stays a backstop rather than the plan.
            $payment = Payment::create($data + ['payment_no' => 'PAY-PENDING', 'invoice_id' => $invoice->id, 'customer_id' => $invoice->customer_id, 'created_by' => auth()->id()]);
            $payment->update(['payment_no' => 'PAY-'.str_pad((string) $payment->id, 5, '0', STR_PAD_LEFT)]);

            // post to ACCOUNTS: Cash/Bank Dr, AR Cr
            if (app()->bound(AccountsPostingProviderInterface::class)) {
                $posting = app(AccountsPostingProviderInterface::class);
                $purpose = "payment {$payment->payment_no} against {$invoice->invoice_no}";
                $cashCode = config('accounts.defaults.cash_code', '1001');
                $arCode = config('accounts.defaults.receivable_code', '1100');

                // Both legs are required: the paid_amount increment and the 'paid' status below sit
                // outside this block, so a miss used to mark the invoice settled for cash the
                // ledger never received. Throwing rolls the payment row back with it.
                $cashId = $bank?->account_id ?? ChartOfAccounts::requireId($posting, $cashCode, $purpose);
                $arId = ChartOfAccounts::requireId($posting, $arCode, $purpose);

                $dto = new JournalData(journalDate: $data['payment_date'], type: 'RV', referenceNo: $payment->payment_no, narration: "Payment {$payment->payment_no} for {$invoice->invoice_no}", lines: [
                    new JournalLineData(accountId: $cashId, debit: (float) $data['amount']),
                    new JournalLineData(accountId: $arId, credit: (float) $data['amount']),
                ], partnerId: $invoice->customer_id, partnerType: 'customer', sourceType: 'sales_payment', sourceId: $payment->id);
                $payment->update(['journal_id' => $posting->postJournal($dto)->id]);
            }

            $invoice->increment('paid_amount', (float) $data['amount']);
            $fresh = $invoice->fresh();
            $paidCents = (int) round((float) $fresh->paid_amount * 100);
            $totalCents = (int) round((float) $fresh->total_amount * 100);
            $newStatus = $paidCents >= $totalCents ? 'paid' : 'partially_paid';
            $fresh->update(['payment_status' => $newStatus === 'paid' ? 'paid' : 'partial', 'status' => $newStatus]);

            return $payment->fresh();
        });
    }

    /**
     * Void a collection: reverse its journal (which un-settles the linked receivable), take the
     * cash back off the invoice, and mark the payment void with an audit trail.
     *
     * A wrong collection previously had no way back. The payment row is kept, not deleted: the
     * voided row is the evidence that money arrived and was then returned, and deleting it would
     * renumber nothing but explain nothing either.
     */
    public function voidPayment(Payment $payment, string $reason): Payment
    {
        return DB::transaction(function () use ($payment, $reason): Payment {
            $payment = Payment::whereKey($payment->getKey())->lockForUpdate()->firstOrFail();
            if ($payment->status === 'void') {
                throw new \DomainException("{$payment->payment_no} is already voided.");
            }
            $invoice = Invoice::whereKey($payment->invoice_id)->lockForUpdate()->firstOrFail();

            if ($payment->journal_id && app()->bound(AccountsPostingProviderInterface::class)) {
                try {
                    app(AccountsPostingProviderInterface::class)
                        ->voidJournal($payment->journal_id, "Void collection {$payment->payment_no}: {$reason}", true);
                } catch (\Throwable $e) {
                    // See VendorBillService::void(): swallowing here would mark the payment void
                    // while its journal — and its receivable settlement — stayed posted.
                    throw new \DomainException(
                        "Could not reverse the journal for {$payment->payment_no}, so it was left posted: {$e->getMessage()}",
                        0,
                        $e
                    );
                }
            }

            $invoice->decrement('paid_amount', (float) $payment->amount);
            $fresh = $invoice->fresh();
            $paidCents = (int) round((float) $fresh->paid_amount * 100);
            $totalCents = (int) round((float) $fresh->total_amount * 100);
            if ($paidCents >= $totalCents) {
                $fresh->update(['payment_status' => 'paid', 'status' => 'paid']);
            } elseif ($paidCents > 0) {
                $fresh->update(['payment_status' => 'partial', 'status' => 'partially_paid']);
            } else {
                $fresh->update(['payment_status' => 'unpaid', 'status' => 'posted']);
            }

            $payment->update(['status' => 'void', 'voided_by' => auth()->id(), 'voided_at' => now(), 'void_reason' => $reason]);

            return $payment->fresh();
        });
    }

    /**
     * Resolve every invoice line against its order line and decide what moves.
     *
     * Lines link explicitly through `order_item_id`, or — for invoices typed by hand against an
     * order — by product, oldest order line first. Each order line only has its uninvoiced
     * remainder to give: invoicing past it is refused, because the extra units were never promised.
     *
     * What the invoice deducts is what its lines add beyond what deliveries already carried out.
     * Delivery is the mirror image (`DeliveryService::ship()` deducts beyond what is invoiced), so
     * the two together take each unit off the shelf exactly once no matter which posts first.
     *
     * Service lines carry no goods: they are neither capped nor deducted.
     *
     * @return array{invoiced: array<int, float>, deduct: array<int, float>, release: list<array<string, mixed>>}
     */
    private function planInvoiceQuantities(Invoice $invoice): array
    {
        $invoice->loadMissing(['items.product', 'order']);
        $invoiced = [];
        $deduct = [];
        $release = [];

        $order = $invoice->order_id ? $invoice->order : null;
        $orderLines = collect();
        $shipped = collect();
        if ($order !== null) {
            $freshStatus = Order::whereKey($order->id)->lockForUpdate()->value('status');
            if (in_array($freshStatus, ['cancelled', 'closed'], true)) {
                throw new \DomainException("Order {$order->order_no} is {$freshStatus} — it cannot take a new invoice.");
            }
            $orderLines = OrderItem::where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();
            $shipped = DB::table('sales_delivery_items as di')
                ->join('sales_deliveries as d', 'd.id', '=', 'di.delivery_id')
                ->where('d.order_id', $order->id)->whereIn('d.status', ['shipped', 'delivered'])
                ->whereNotNull('di.order_item_id')
                // Aliased, not qualified: `pluck('qty', 'di.order_item_id')` would resolve every key
                // through `data_get($row, 'di.order_item_id')`, find no `di` attribute on the row,
                // and collapse the whole shipped pool into one null-keyed entry.
                ->groupBy('di.order_item_id')->selectRaw('di.order_item_id AS order_item_id, SUM(di.qty) AS qty')
                ->pluck('qty', 'order_item_id')->map(fn ($q) => (float) $q);
        }

        $room = [];
        foreach ($orderLines as $ol) {
            $room[$ol->id] = (float) $ol->qty - (float) $ol->qty_invoiced;
        }

        foreach ($invoice->items as $line) {
            $need = (float) $line->qty;
            $isService = ($line->product?->type ?? 'stockable') === 'service';
            if ($isService || $order === null) {
                $deduct[$line->id] = $isService ? 0.0 : $need;
                continue;
            }
            if ($line->order_item_id) {
                $ol = $orderLines->firstWhere('id', $line->order_item_id);
                if ($ol === null) {
                    throw new \DomainException("Invoice {$invoice->invoice_no} line \"{$line->product_name}\" references an order line that is not on order {$order->order_no}.");
                }
                if ((int) $ol->product_id !== (int) $line->product_id || ($ol->variant_id ?? null) != ($line->variant_id ?? null)) {
                    throw new \DomainException("Invoice {$invoice->invoice_no} line \"{$line->product_name}\" is linked to a different product on order {$order->order_no}.");
                }
                $candidates = collect([$ol]);
            } else {
                $candidates = $orderLines
                    ->filter(fn ($ol) => (int) $ol->product_id === (int) $line->product_id && ($ol->variant_id ?? null) == ($line->variant_id ?? null))
                    ->values();
            }
            $remaining = $need;
            $lineLinks = [];
            foreach ($candidates as $ol) {
                if ($remaining <= 0.0001) {
                    break;
                }
                $take = min($room[$ol->id] ?? 0, $remaining);
                if ($take <= 0) {
                    continue;
                }
                $room[$ol->id] -= $take;
                $remaining -= $take;
                $invoiced[$ol->id] = ($invoiced[$ol->id] ?? 0) + $take;
                $lineLinks[$ol->id] = ($lineLinks[$ol->id] ?? 0) + $take;
            }
            if ($remaining > 0.0001) {
                $uninvoiced = 0.0;
                foreach ($candidates as $ol) {
                    $uninvoiced += (float) $ol->qty - (float) $ol->qty_invoiced;
                }
                throw new \DomainException(sprintf(
                    'Invoice %s charges %.2f of "%s" but order %s only has %.2f uninvoiced. The rest is already on another invoice.',
                    $invoice->invoice_no, $need, $line->product_name, $order->order_no, max(0.0, $uninvoiced)
                ));
            }
            // Deduct what this line adds beyond its order lines' shipped pools; consume the pools
            // so a second line for the same goods does not deduct them again.
            $deductQty = 0.0;
            foreach ($lineLinks as $olId => $linkedQty) {
                $covered = min((float) ($shipped[$olId] ?? 0), $linkedQty);
                $shipped[$olId] = (float) ($shipped[$olId] ?? 0) - $covered;
                $deductQty += $linkedQty - $covered;
                if ($linkedQty - $covered > 0.0001) {
                    $ol = $orderLines->firstWhere('id', $olId);
                    $release[] = [
                        'product_id' => $ol->product_id, 'variant_id' => $ol->variant_id,
                        'warehouse_id' => $invoice->warehouse_id, 'qty' => $linkedQty - $covered,
                    ];
                }
            }
            $deduct[$line->id] = $deductQty;
        }

        return ['invoiced' => $invoiced, 'deduct' => $deduct, 'release' => $release];
    }

    private function enrichTotals(array $data): array
    {
        if (! isset($data['items']) || ! is_array($data['items'])) {
            return $data;
        }
        $subtotal = 0;
        $vat = 0;
        $discount = 0;
        foreach ($data['items'] as &$it) {
            // snapshot product_name/sku if missing
            if (empty($it['product_name']) && ! empty($it['product_id'])) {
                $p = Product::find($it['product_id']);
                if ($p) {
                    $it['product_name'] = $p->name;
                    $it['sku'] = $p->sku;
                    $it['uom_id'] = $it['uom_id'] ?? $p->uom_id;
                }
            }
            // Contract/tier pricing when no explicit price was typed (Phase 1).
            LinePricer::apply($it, isset($data['customer_id']) ? (int) $data['customer_id'] : null, $data['invoice_date'] ?? null);
            $qty = (float) ($it['qty'] ?? 0);
            $price = (float) ($it['unit_price'] ?? 0);
            $discPct = (float) ($it['discount_pct'] ?? 0);
            $vatRate = (float) ($it['vat_rate'] ?? 0);
            $gross = $qty * $price;
            $discAmt = $gross * ($discPct / 100);
            $net = $gross - $discAmt;
            $vatAmt = $net * ($vatRate / 100);
            $it['discount_amount'] = $discAmt;
            $it['vat_amount'] = $vatAmt;
            $it['line_total'] = $net + $vatAmt;
            $subtotal += $gross;
            $discount += $discAmt;
            $vat += $vatAmt;
        }
        $data['subtotal'] = $subtotal;
        $data['discount_amount'] = $discount;
        $data['vat_amount'] = $vat;
        $data['total_amount'] = $subtotal - $discount + $vat;

        return $data;
    }
}
