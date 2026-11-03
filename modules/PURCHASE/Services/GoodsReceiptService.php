<?php

namespace Modules\PURCHASE\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\CORE\Support\DataTableQuery;
use Modules\PURCHASE\Interfaces\GoodsReceiptRepositoryInterface;
use Modules\PURCHASE\Models\GoodsReceipt;

class GoodsReceiptService
{
    public function __construct(private readonly GoodsReceiptRepositoryInterface $repo) {}
    public function paginate(DataTableQuery $dt): LengthAwarePaginator { return $this->repo->paginate($dt); }
    public function exportBuilder(DataTableQuery $dt): Builder { return $this->repo->exportBuilder($dt); }
    public function findOrFail(int $id): GoodsReceipt { return $this->repo->findOrFail($id); }
    public function create(array $data): GoodsReceipt { return $this->repo->create($this->enrichTotals($data)); }
    public function update(GoodsReceipt $r, array $data): GoodsReceipt { return $this->repo->update($r, $this->enrichTotals($data)); }
    public function delete(GoodsReceipt $r): void { $this->repo->delete($r); }

    public function post(GoodsReceipt $receipt): GoodsReceipt
    {
        if (! $receipt->isDraft()) throw new \DomainException('Only draft can be posted.');
        return DB::transaction(function () use ($receipt): GoodsReceipt {
            $receipt->load(['items.product','order']);
            if (app()->bound(\Modules\INVENTORY\Interfaces\InventoryStockProviderInterface::class)) {
                $provider = app(\Modules\INVENTORY\Interfaces\InventoryStockProviderInterface::class);
                $items = $receipt->items->map(fn($it)=>['product_id'=>$it->product_id,'variant_id'=>$it->variant_id,'warehouse_id'=>$receipt->warehouse_id,'qty'=>(float)$it->qty,'unit_cost'=>(float)$it->unit_price])->toArray();
                $provider->restock($items, $receipt->receipt_no, 'purchase_receipt');
            }
            if ($receipt->order_id) {
                $order = \Modules\PURCHASE\Models\PurchaseOrder::find($receipt->order_id);
                if ($order) {
                    foreach ($receipt->items as $ri) {
                        if ($ri->order_item_id) {
                            \Modules\PURCHASE\Models\PurchaseOrderItem::where('id',$ri->order_item_id)->increment('qty_received', (float)$ri->qty);
                        }
                    }
                    $order->load('items');
                    $allReceived = $order->items->every(fn($it)=>(float)$it->qty_received >= (float)$it->qty - 0.001);
                    $anyReceived = $order->items->some(fn($it)=>(float)$it->qty_received > 0);
                    $order->update(['status'=> $allReceived ? 'received' : ($anyReceived ? 'partially_received' : $order->status)]);
                    $order->increment('received_amount', (float)$receipt->total_amount);
                }
            }
            // ── GRNI accrual: Dr Inventory 1200 / Cr GRNI 2115 (subtotal only, VAT on Bill) ──
            if (app()->bound(\Modules\ACCOUNTS\Interfaces\AccountsPostingProviderInterface::class)) {
                try {
                    $posting = app(\Modules\ACCOUNTS\Interfaces\AccountsPostingProviderInterface::class);
                    $invId = $posting->accountIdByCode('1200'); $grniId = $posting->accountIdByCode('2115');
                    $subtotal = (float)$receipt->subtotal - (float)($receipt->discount_amount ?? 0);
                    if ($invId && $grniId && $subtotal > 0.01) {
                        $dto = new \Modules\ACCOUNTS\DTO\JournalData(
                            journalDate: $receipt->receipt_date->format('Y-m-d'),
                            type: 'JV', referenceNo: $receipt->receipt_no,
                            narration: "GRN {$receipt->receipt_no}" . ($receipt->order ? " for PO {$receipt->order->order_no}" : "") . " — {$receipt->vendor?->name}",
                            lines: [
                                new \Modules\ACCOUNTS\DTO\JournalLineData(accountId: $invId, debit: $subtotal, description: "Inventory — GRN {$receipt->receipt_no}"),
                                new \Modules\ACCOUNTS\DTO\JournalLineData(accountId: $grniId, credit: $subtotal, description: "GRNI — {$receipt->receipt_no}"),
                            ],
                            partnerId: $receipt->vendor_id, partnerType: 'vendor',
                            sourceType: 'purchase_receipt', sourceId: $receipt->id
                        );
                        $j = $posting->postJournal($dto);
                        $receipt->forceFill(['grni_journal_id'=>$j->id])->save();
                    }
                } catch (\Throwable $e) { \Illuminate\Support\Facades\Log::warning('GRN GRNI posting skipped: '.$e->getMessage(), ['receipt_id'=>$receipt->id]); }
            }
            $receipt->update(['status'=>'posted','posted_by'=>auth()->id(),'posted_at'=>now()]);
            return $receipt->fresh(['items','order']);
        });
    }

    public function void(GoodsReceipt $receipt): GoodsReceipt
    {
        if (! $receipt->isPosted()) throw new \DomainException('Only posted GRN can be voided.');
        // Prevent void if already billed? Soft warning — allow but reverse stock regardless
        return DB::transaction(function () use ($receipt): GoodsReceipt {
            // 1) Reverse stock OUT (deduct what was added)
            if (app()->bound(\Modules\INVENTORY\Interfaces\InventoryStockProviderInterface::class)) {
                $provider = app(\Modules\INVENTORY\Interfaces\InventoryStockProviderInterface::class);
                $items = $receipt->items->map(fn($it)=>['product_id'=>$it->product_id,'variant_id'=>$it->variant_id,'warehouse_id'=>$receipt->warehouse_id,'qty'=>(float)$it->qty])->toArray();
                $provider->deductStock($items, $receipt->receipt_no, 'purchase_receipt_void');
            }
            if (!empty($receipt->grni_journal_id) && app()->bound(\Modules\ACCOUNTS\Interfaces\AccountsPostingProviderInterface::class)) {
                try { app(\Modules\ACCOUNTS\Interfaces\AccountsPostingProviderInterface::class)->voidJournal($receipt->grni_journal_id, "Void GRN {$receipt->receipt_no}"); } catch (\Throwable $e) {}
            }
            // 2) Reverse PO qty_received
            if ($receipt->order_id) {
                $order = \Modules\PURCHASE\Models\PurchaseOrder::find($receipt->order_id);
                if ($order) {
                    foreach ($receipt->items as $ri) {
                        if ($ri->order_item_id) {
                            \Modules\PURCHASE\Models\PurchaseOrderItem::where('id',$ri->order_item_id)->decrement('qty_received', (float)$ri->qty);
                        }
                    }
                    $order->decrement('received_amount', (float)$receipt->total_amount);
                    $order->load('items');
                    $anyReceived = $order->items->some(fn($it)=>(float)$it->qty_received > 0.001);
                    $order->update(['status'=> $anyReceived ? 'partially_received' : 'confirmed']);
                }
            }
            $receipt->update(['status'=>'cancelled']);
            return $receipt->fresh();
        });
    }

    private function enrichTotals(array $data): array
    {
        if (! isset($data['items']) || ! is_array($data['items'])) return $data;
        $subtotal=0; $vat=0; $discount=0;
        foreach ($data['items'] as &$it) {
            if (empty($it['product_name']) && ! empty($it['product_id'])) {
                $p = \Modules\INVENTORY\Models\Product::find($it['product_id']);
                if ($p) { $it['product_name']=$p->name; $it['sku']=$p->sku; }
            }
            $qty=(float)($it['qty']??0); $price=(float)($it['unit_price']??0);
            $discPct=(float)($it['discount_pct']??0); $vatRate=(float)($it['vat_rate']??0);
            $gross=$qty*$price; $discAmt=$gross*($discPct/100); $net=$gross-$discAmt; $vatAmt=$net*($vatRate/100);
            $it['discount_amount']=$discAmt; $it['vat_amount']=$vatAmt; $it['line_total']=$net+$vatAmt;
            $subtotal+=$gross; $discount+=$discAmt; $vat+=$vatAmt;
        }
        $data['subtotal']=$subtotal; $data['discount_amount']=$discount; $data['vat_amount']=$vat; $data['total_amount']=$subtotal-$discount+$vat;
        return $data;
    }
}
