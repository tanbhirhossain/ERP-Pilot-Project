<?php

namespace Modules\PURCHASE\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\ACCOUNTS\DTO\JournalData;
use Modules\ACCOUNTS\DTO\JournalLineData;
use Modules\ACCOUNTS\Interfaces\AccountsPostingProviderInterface;
use Modules\ACCOUNTS\Support\ChartOfAccounts;
use Modules\CORE\Support\DataTableQuery;
use Modules\INVENTORY\Interfaces\InventoryStockProviderInterface;
use Modules\INVENTORY\Models\Product;
use Modules\PURCHASE\Interfaces\GoodsReceiptRepositoryInterface;
use Modules\PURCHASE\Models\GoodsReceipt;
use Modules\PURCHASE\Models\PurchaseOrder;
use Modules\PURCHASE\Models\PurchaseOrderItem;

class GoodsReceiptService
{
    public function __construct(private readonly GoodsReceiptRepositoryInterface $repo) {}

    public function paginate(DataTableQuery $dt): LengthAwarePaginator
    {
        return $this->repo->paginate($dt);
    }

    public function exportBuilder(DataTableQuery $dt): Builder
    {
        return $this->repo->exportBuilder($dt);
    }

    public function findOrFail(int $id): GoodsReceipt
    {
        return $this->repo->findOrFail($id);
    }

    public function create(array $data): GoodsReceipt
    {
        return $this->repo->create($this->enrichTotals($data));
    }

    public function update(GoodsReceipt $r, array $data): GoodsReceipt
    {
        return $this->repo->update($r, $this->enrichTotals($data));
    }

    public function delete(GoodsReceipt $r): void
    {
        $this->repo->delete($r);
    }

    public function post(GoodsReceipt $receipt): GoodsReceipt
    {
        return DB::transaction(function () use ($receipt): GoodsReceipt {
            // Re-read under a row lock: a stale model or a second concurrent click must not
            // post the same receipt twice and move stock or the ledger twice.
            $receipt = GoodsReceipt::whereKey($receipt->getKey())->lockForUpdate()->firstOrFail();
            if (! $receipt->isDraft()) {
                throw new \DomainException('Only draft can be posted.');
            }
            $receipt->load(['items.product', 'order']);

            // Refuse to receive more than the order still owes, before a single unit moves.
            //
            // The create form already offers only the outstanding quantity, but the form is not the
            // rule: two people with the form open see the same remaining 50, and whoever posts second
            // would put another 50 on the shelf and another 50 in GRNI, against goods that were never
            // delivered. The same goes for a replayed request. So the check lives here, inside the
            // transaction, where nothing can slip between reading the remaining quantity and taking it.
            if ($receipt->order_id) {
                $this->assertNotOverReceiving($receipt);
            }

            if (app()->bound(InventoryStockProviderInterface::class)) {
                $provider = app(InventoryStockProviderInterface::class);
                $items = $receipt->items->map(fn ($it) => ['product_id' => $it->product_id, 'variant_id' => $it->variant_id, 'warehouse_id' => $receipt->warehouse_id, 'qty' => (float) $it->qty, 'unit_cost' => (float) $it->unit_price])->toArray();
                $provider->restock($items, $receipt->receipt_no, 'purchase_receipt');
            }
            if ($receipt->order_id) {
                $order = PurchaseOrder::find($receipt->order_id);
                if ($order) {
                    foreach ($receipt->items as $ri) {
                        if ($ri->order_item_id) {
                            PurchaseOrderItem::where('id', $ri->order_item_id)->increment('qty_received', (float) $ri->qty);
                        }
                    }
                    $order->load('items');
                    $allReceived = $order->items->every(fn ($it) => (float) $it->qty_received >= (float) $it->qty - 0.001);
                    $anyReceived = $order->items->some(fn ($it) => (float) $it->qty_received > 0);
                    $order->update(['status' => $allReceived ? 'received' : ($anyReceived ? 'partially_received' : $order->status)]);
                    $order->increment('received_amount', (float) $receipt->total_amount);
                }
            }
            // ── GRNI accrual: Dr Inventory 1200 / Cr GRNI 2115 (subtotal only, VAT on Bill) ──
            if (app()->bound(AccountsPostingProviderInterface::class)) {
                $posting = app(AccountsPostingProviderInterface::class);
                $subtotal = (float) $receipt->subtotal - (float) ($receipt->discount_amount ?? 0);
                if ($subtotal > 0.01) {
                    $purpose = "the GRNI accrual for {$receipt->receipt_no}";
                    // Resolved before the try, on purpose. The whole accrual used to sit inside a
                    // catch that logged a warning and carried on, so a missing account — or a closed
                    // fiscal year — left the receipt marked posted with no accrual behind it, and
                    // the bill raised against that receipt then debited Inventory a second time
                    // instead of reversing GRNI. The stock has already been received above; this
                    // posting is not best-effort.
                    $invId = ChartOfAccounts::requireId($posting, config('accounts.defaults.inventory_code', '1200'), $purpose);
                    $grniId = ChartOfAccounts::requireId($posting, config('accounts.defaults.grni_code', '2115'), $purpose);
                    $dto = new JournalData(
                        journalDate: $receipt->receipt_date->format('Y-m-d'),
                        type: 'JV', referenceNo: $receipt->receipt_no,
                        narration: "GRN {$receipt->receipt_no}".($receipt->order ? " for PO {$receipt->order->order_no}" : '')." — {$receipt->vendor?->name}",
                        lines: [
                            new JournalLineData(accountId: $invId, debit: $subtotal, description: "Inventory — GRN {$receipt->receipt_no}"),
                            new JournalLineData(accountId: $grniId, credit: $subtotal, description: "GRNI — {$receipt->receipt_no}"),
                        ],
                        partnerId: $receipt->vendor_id, partnerType: 'vendor',
                        sourceType: 'purchase_receipt', sourceId: $receipt->id
                    );
                    try {
                        $journal = $posting->postJournal($dto);
                    } catch (\Throwable $e) {
                        // Same reasoning as void() below, opposite direction.
                        throw new \DomainException(
                            "Could not post {$purpose}, so the receipt was left unposted: {$e->getMessage()}",
                            0,
                            $e
                        );
                    }
                    $receipt->forceFill(['grni_journal_id' => $journal->id])->save();
                }
            }
            $receipt->update(['status' => 'posted', 'posted_by' => auth()->id(), 'posted_at' => now()]);

            return $receipt->fresh(['items', 'order']);
        });
    }

    public function void(GoodsReceipt $receipt): GoodsReceipt
    {
        if (! $receipt->isPosted()) {
            throw new \DomainException('Only posted GRN can be voided.');
        }

        // Prevent void if already billed? Soft warning — allow but reverse stock regardless
        return DB::transaction(function () use ($receipt): GoodsReceipt {
            // 1) Reverse stock OUT (deduct what was added)
            if (app()->bound(InventoryStockProviderInterface::class)) {
                $provider = app(InventoryStockProviderInterface::class);
                $items = $receipt->items->map(fn ($it) => ['product_id' => $it->product_id, 'variant_id' => $it->variant_id, 'warehouse_id' => $receipt->warehouse_id, 'qty' => (float) $it->qty])->toArray();
                $provider->deductStock($items, $receipt->receipt_no, 'purchase_receipt_void');
            }
            if (! empty($receipt->grni_journal_id) && app()->bound(AccountsPostingProviderInterface::class)) {
                try {
                    app(AccountsPostingProviderInterface::class)
                        ->voidJournal($receipt->grni_journal_id, "Void GRN {$receipt->receipt_no}");
                } catch (\Throwable $e) {
                    // See VendorBillService::void() — swallowing here left the GRNI journal posted
                    // against a cancelled receipt, inside a transaction that could no longer roll back.
                    throw new \DomainException(
                        "Could not reverse the GRNI journal for {$receipt->receipt_no}, so it was left as it was: {$e->getMessage()}",
                        0,
                        $e
                    );
                }
            }
            // 2) Reverse PO qty_received
            if ($receipt->order_id) {
                $order = PurchaseOrder::find($receipt->order_id);
                if ($order) {
                    // Only the lines that were incremented on post(). A receipt line with no
                    // `order_item_id` — an ad-hoc line typed onto the GRN — never incremented
                    // anything, so voiding it must not subtract from another line's received
                    // quantity. It used to: cancelling one such receipt knocked a fully received
                    // order back to zero while its goods stayed on the shelf.
                    foreach ($receipt->items as $ri) {
                        if ($ri->order_item_id) {
                            PurchaseOrderItem::where('id', $ri->order_item_id)->decrement('qty_received', (float) $ri->qty);
                        }
                    }
                    $order->decrement('received_amount', (float) $receipt->total_amount);
                    $order->load('items');
                    $anyReceived = $order->items->some(fn ($it) => (float) $it->qty_received > 0.001);
                    $order->update(['status' => $anyReceived ? 'partially_received' : 'confirmed']);
                }
            }
            $receipt->update(['status' => 'cancelled']);

            return $receipt->fresh();
        });
    }

    /**
     * Refuse a receipt that would put more on the shelf than the order asked for.
     *
     * Over-receipt is not a rounding error to be tolerated: the surplus stock is real but nobody
     * ordered it, the GRNI accrual credits a liability for goods that were never delivered, and the
     * extra units sit in inventory at a cost the vendor never billed. If a delivery genuinely is
     * larger than the order, the order is what is wrong — raise the PO, then receive against it.
     */
    private function assertNotOverReceiving(GoodsReceipt $receipt): void
    {
        // Lock all linked PO lines in a stable order so two different draft receipts
        // cannot both consume the same outstanding quantity concurrently.
        $groups = $receipt->items->filter(fn ($line) => $line->order_item_id)
            ->groupBy('order_item_id');
        $orderedLines = PurchaseOrderItem::whereIn('id', $groups->keys()->all())
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        foreach ($groups as $id => $lines) {
            $ordered = $orderedLines->get($id);
            $first = $lines->first();
            if ($ordered === null || (int) $ordered->order_id !== (int) $receipt->order_id) {
                throw new \DomainException(sprintf(
                    'Line "%s" does not belong to this purchase order — reload the order before receiving.',
                    $first->product_name,
                ));
            }
            foreach ($lines as $line) {
                if ((int) $line->product_id !== (int) $ordered->product_id) {
                    throw new \DomainException('Receipt product does not match the linked purchase order line.');
                }
            }
            $outstanding = round((float) $ordered->qty - (float) $ordered->qty_received, 4);
            $arriving = round((float) $lines->sum('qty'), 4);
            if ($arriving > $outstanding + 0.0001) {
                throw new \DomainException(sprintf(
                    'Cannot receive %.2f of "%s": the order still owes %.2f. Receive the outstanding quantity, or raise the order first if a bigger delivery was agreed.',
                    $arriving, $first->product_name, max(0.0, $outstanding),
                ));
            }
        }
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
            if (empty($it['product_name']) && ! empty($it['product_id'])) {
                $p = Product::find($it['product_id']);
                if ($p) {
                    $it['product_name'] = $p->name;
                    $it['sku'] = $p->sku;
                }
            }
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
