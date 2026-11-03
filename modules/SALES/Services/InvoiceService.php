<?php

namespace Modules\SALES\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\CORE\Support\DataTableQuery;
use Modules\SALES\Interfaces\InvoiceRepositoryInterface;
use Modules\SALES\Models\Invoice;

class InvoiceService
{
    public function __construct(private readonly InvoiceRepositoryInterface $repo) {}

    public function paginate(DataTableQuery $dt): LengthAwarePaginator { return $this->repo->paginate($dt); }
    public function exportBuilder(DataTableQuery $dt): Builder { return $this->repo->exportBuilder($dt); }
    public function findOrFail(int $id): Invoice { return $this->repo->findOrFail($id); }
    public function create(array $data): Invoice { return $this->repo->create($this->enrichTotals($data)); }
    public function update(Invoice $inv, array $data): Invoice { return $this->repo->update($inv, $this->enrichTotals($data)); }
    public function delete(Invoice $inv): void { $this->repo->delete($inv); }

    public function post(Invoice $invoice): Invoice
    {
        if (! $invoice->isDraft()) throw new \DomainException('Only draft can be posted.');
        return DB::transaction(function () use ($invoice): Invoice {
            $invoice->load(['items.product', 'customer']);

            // 1) Snapshot cost_price BEFORE deduct — FIFO-aware (cost based on oldest layers)
            if (app()->bound(\Modules\ACCOUNTS\Interfaces\AccountsPostingProviderInterface::class)) {
                // we will create journal later, but need cost first
                $prePosting = null;
            }
            // FIFO cost must be captured before stock is deducted
            foreach ($invoice->items as $preItem) {
                $hasCost = $preItem->cost_price !== null && (float) $preItem->cost_price > 0.001;
                if (! $hasCost && app()->bound(\Modules\INVENTORY\Interfaces\InventoryValuationProviderInterface::class)) {
                    $valuator = app(\Modules\INVENTORY\Interfaces\InventoryValuationProviderInterface::class);
                    $product = $preItem->product ?? \Modules\INVENTORY\Models\Product::find($preItem->product_id);
                    $cost = 0;
                    if ($product && $product->valuation_method === 'fifo' && app()->bound(\Modules\INVENTORY\Services\ValuationService::class)) {
                        $vs = app(\Modules\INVENTORY\Services\ValuationService::class);
                        $fifo = $vs->getFifoCost($preItem->product_id, $preItem->variant_id, $invoice->warehouse_id, (float)$preItem->qty);
                        $cost = (float)($fifo['unit_cost'] ?? 0);
                        if ($cost <= 0.001) $cost = $valuator->getUnitCost($preItem->product_id, $preItem->variant_id, $invoice->warehouse_id);
                    } else {
                        $cost = $valuator->getUnitCost($preItem->product_id, $preItem->variant_id, $invoice->warehouse_id);
                    }
                    if ($cost > 0.001) { $preItem->update(['cost_price' => $cost]); $preItem->cost_price = $cost; }
                }
            }
            $invoice->load('items');

            // 2) Deduct stock via INVENTORY provider (skip if already delivered via DN)
            $shouldDeduct = config('sales.stock.deduct_on_invoice', true);
            if ($invoice->order_id) {
                $shippedQty = \Modules\SALES\Models\Delivery::where('order_id',$invoice->order_id)->whereIn('status',['shipped','delivered'])->with('items')->get()->flatMap->items->sum(fn($it)=>(float)$it->qty);
                if ($shippedQty >= 0.001) $shouldDeduct = false; // already shipped via delivery challan
            }
            if ($shouldDeduct && app()->bound(\Modules\INVENTORY\Interfaces\InventoryStockProviderInterface::class)) {
                $stockProvider = app(\Modules\INVENTORY\Interfaces\InventoryStockProviderInterface::class);
                $items = $invoice->items->map(fn($it) => ['product_id' => $it->product_id, 'variant_id' => $it->variant_id, 'warehouse_id' => $invoice->warehouse_id, 'qty' => (float) $it->qty])->toArray();
                $stockProvider->deductStock($items, $invoice->invoice_no, 'sales_invoice');
            }

            // 3) Create COGS + Sales + AR + VAT journal via ACCOUNTS provider
            $journalId = null;
            if (app()->bound(\Modules\ACCOUNTS\Interfaces\AccountsPostingProviderInterface::class)) {
                $posting = app(\Modules\ACCOUNTS\Interfaces\AccountsPostingProviderInterface::class);
                // snapshot cost_price for COGS — FIFO-aware (already done above, keep fallback)
                foreach ($invoice->items as $item) {
                    $hasCost = $item->cost_price !== null && (float) $item->cost_price > 0.001;
                    if (! $hasCost) {
                        if (app()->bound(\Modules\INVENTORY\Interfaces\InventoryValuationProviderInterface::class)) {
                            $valuator = app(\Modules\INVENTORY\Interfaces\InventoryValuationProviderInterface::class);
                            $product = $item->product ?? \Modules\INVENTORY\Models\Product::find($item->product_id);
                            $cost = 0;
                            if ($product && $product->valuation_method === 'fifo' && app()->bound(\Modules\INVENTORY\Services\ValuationService::class)) {
                                $vs = app(\Modules\INVENTORY\Services\ValuationService::class);
                                $fifo = $vs->getFifoCost($item->product_id, $item->variant_id, $invoice->warehouse_id, (float)$item->qty);
                                $cost = (float)($fifo['unit_cost'] ?? 0);
                                if ($cost <= 0.001) $cost = $valuator->getUnitCost($item->product_id, $item->variant_id, $invoice->warehouse_id);
                            } else {
                                $cost = $valuator->getUnitCost($item->product_id, $item->variant_id, $invoice->warehouse_id);
                            }
                            if ($cost > 0) $item->update(['cost_price' => $cost]);
                            $item->cost_price = $cost;
                        }
                    }
                }
                // refresh to ensure totalCost uses updated values
                $invoice->load('items');

                $salesCode = config('accounts.defaults.sales_code', '4001');
                $arCode = config('accounts.defaults.receivable_code', '1100');
                $cogsCode = '5001'; // COGS
                $invCode = config('accounts.defaults.inventory_code', '1200');
                $vatCode = config('accounts.defaults.vat_payable_code', '2110');

                $salesId = $posting->accountIdByCode($salesCode);
                $arId = $posting->accountIdByCode($arCode);
                $cogsId = $posting->accountIdByCode($cogsCode);
                $invId = $posting->accountIdByCode($invCode);
                $vatId = $posting->accountIdByCode($vatCode);

                if ($arId && $salesId) {
                    $lines = [];
                    $total = (float) $invoice->total_amount;
                    $subtotal = (float) $invoice->subtotal - (float) $invoice->discount_amount;
                    $vat = (float) $invoice->vat_amount;
                    // AR Dr
                    $lines[] = new \Modules\ACCOUNTS\DTO\JournalLineData(accountId: $arId, debit: $total, description: "Sales {$invoice->invoice_no} - {$invoice->customer?->name}");
                    // Sales Cr
                    $lines[] = new \Modules\ACCOUNTS\DTO\JournalLineData(accountId: $salesId, credit: $subtotal, description: "Sales revenue {$invoice->invoice_no}");
                    // VAT Cr if any
                    if ($vat > 0 && $vatId) $lines[] = new \Modules\ACCOUNTS\DTO\JournalLineData(accountId: $vatId, credit: $vat, description: "VAT {$invoice->invoice_no}");

                    // COGS Dr / Inventory Cr
                    $totalCost = $invoice->items->sum(fn($it) => (float) ($it->cost_price ?? 0) * (float) $it->qty);
                    if ($totalCost > 0 && $cogsId && $invId) {
                        $lines[] = new \Modules\ACCOUNTS\DTO\JournalLineData(accountId: $cogsId, debit: $totalCost, description: "COGS {$invoice->invoice_no}");
                        $lines[] = new \Modules\ACCOUNTS\DTO\JournalLineData(accountId: $invId, credit: $totalCost, description: "Inventory out {$invoice->invoice_no}");
                    }

                    $dto = new \Modules\ACCOUNTS\DTO\JournalData(journalDate: $invoice->invoice_date->format('Y-m-d'), type: 'SV', referenceNo: $invoice->invoice_no, narration: "Sales Invoice {$invoice->invoice_no}", lines: $lines, partnerId: $invoice->customer_id, partnerType: 'customer', sourceType: 'sales_invoice', sourceId: $invoice->id);
                    $journal = $posting->postJournal($dto);
                    $journalId = $journal->id;
                }
            }

            $invoice->update(['status' => 'posted', 'journal_id' => $journalId, 'posted_by' => auth()->id(), 'posted_at' => now()]);

            // update order invoiced amount
            if ($invoice->order_id) {
                $order = \Modules\SALES\Models\Order::find($invoice->order_id);
                if ($order) {
                    $order->increment('invoiced_amount', (float) $invoice->total_amount);
                    $order->load('items');
                    $allInvoiced = $order->items->every(fn($it) => (float) $it->qty_invoiced >= (float) $it->qty);
                    $order->update(['status' => $allInvoiced ? 'invoiced' : 'partially_invoiced']);
                }
            }

            return $invoice->fresh(['items', 'journal']);
        });
    }

    public function recordPayment(Invoice $invoice, array $data): \Modules\SALES\Models\Payment
    {
        if (! $invoice->isPosted()) throw new \DomainException('Only posted invoices can receive payments.');
        return DB::transaction(function () use ($invoice, $data): \Modules\SALES\Models\Payment {
            $paymentNo = 'PAY-' . str_pad((string) (\Modules\SALES\Models\Payment::max('id') + 1), 5, '0', STR_PAD_LEFT);
            $payment = \Modules\SALES\Models\Payment::create($data + ['payment_no' => $paymentNo, 'invoice_id' => $invoice->id, 'customer_id' => $invoice->customer_id, 'created_by' => auth()->id()]);

            // post to ACCOUNTS: Cash/Bank Dr, AR Cr
            if (app()->bound(\Modules\ACCOUNTS\Interfaces\AccountsPostingProviderInterface::class)) {
                $posting = app(\Modules\ACCOUNTS\Interfaces\AccountsPostingProviderInterface::class);
                $cashCode = config('accounts.defaults.cash_code', '1001');
                $arCode = config('accounts.defaults.receivable_code', '1100');
                $cashId = $posting->accountIdByCode($cashCode);
                $arId = $posting->accountIdByCode($arCode);
                if ($data['bank_id'] ?? null) {
                    $bank = \Modules\ACCOUNTS\Models\Bank::find($data['bank_id']);
                    $cashId = $bank?->account_id ?? $cashId;
                }
                if ($cashId && $arId) {
                    $dto = new \Modules\ACCOUNTS\DTO\JournalData(journalDate: $data['payment_date'], type: 'RV', referenceNo: $payment->payment_no, narration: "Payment {$payment->payment_no} for {$invoice->invoice_no}", lines: [
                        new \Modules\ACCOUNTS\DTO\JournalLineData(accountId: $cashId, debit: (float) $data['amount']),
                        new \Modules\ACCOUNTS\DTO\JournalLineData(accountId: $arId, credit: (float) $data['amount']),
                    ], partnerId: $invoice->customer_id, partnerType: 'customer', sourceType: 'sales_payment', sourceId: $payment->id);
                    $j = $posting->postJournal($dto);
                    $payment->update(['journal_id' => $j->id]);
                }
            }

            $invoice->increment('paid_amount', (float) $data['amount']);
            $fresh = $invoice->fresh();
            $newStatus = (float) $fresh->paid_amount >= (float) $fresh->total_amount ? 'paid' : 'partially_paid';
            $fresh->update(['payment_status' => $newStatus === 'paid' ? 'paid' : 'partial', 'status' => $newStatus]);

            return $payment;
        });
    }

    private function enrichTotals(array $data): array
    {
        if (! isset($data['items']) || ! is_array($data['items'])) return $data;
        $subtotal = 0; $vat = 0; $discount = 0;
        foreach ($data['items'] as &$it) {
            // snapshot product_name/sku if missing
            if (empty($it['product_name']) && ! empty($it['product_id'])) {
                $p = \Modules\INVENTORY\Models\Product::find($it['product_id']);
                if ($p) { $it['product_name'] = $p->name; $it['sku'] = $p->sku; $it['uom_id'] = $it['uom_id'] ?? $p->uom_id; }
            }
            $qty = (float) ($it['qty'] ?? 0); $price = (float) ($it['unit_price'] ?? 0);
            $discPct = (float) ($it['discount_pct'] ?? 0); $vatRate = (float) ($it['vat_rate'] ?? 0);
            $gross = $qty * $price; $discAmt = $gross * ($discPct / 100); $net = $gross - $discAmt; $vatAmt = $net * ($vatRate / 100);
            $it['discount_amount'] = $discAmt; $it['vat_amount'] = $vatAmt; $it['line_total'] = $net + $vatAmt;
            $subtotal += $gross; $discount += $discAmt; $vat += $vatAmt;
        }
        $data['subtotal'] = $subtotal; $data['discount_amount'] = $discount; $data['vat_amount'] = $vat; $data['total_amount'] = $subtotal - $discount + $vat;
        return $data;
    }
}
