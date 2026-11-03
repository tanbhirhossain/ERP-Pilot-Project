<?php

namespace Modules\PURCHASE\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\CORE\Support\DataTableQuery;
use Modules\PURCHASE\Interfaces\PurchaseReturnRepositoryInterface;
use Modules\PURCHASE\Models\PurchaseReturn;

class PurchaseReturnService
{
    public function __construct(private readonly PurchaseReturnRepositoryInterface $repo) {}
    public function paginate(DataTableQuery $dt): LengthAwarePaginator { return $this->repo->paginate($dt); }
    public function exportBuilder(DataTableQuery $dt): Builder { return $this->repo->exportBuilder($dt); }
    public function findOrFail(int $id): PurchaseReturn { return $this->repo->findOrFail($id); }
    public function create(array $data): PurchaseReturn { return $this->repo->create($this->enrichTotals($data)); }
    public function update(PurchaseReturn $r, array $data): PurchaseReturn { return $this->repo->update($r, $this->enrichTotals($data)); }
    public function delete(PurchaseReturn $r): void { $this->repo->delete($r); }

    public function post(PurchaseReturn $ret): PurchaseReturn
    {
        if (! $ret->isDraft()) throw new \DomainException('Only draft can be posted.');
        return DB::transaction(function () use ($ret): PurchaseReturn {
            $ret->load(['items.product','vendor']);
            // 1) Stock OUT (return to vendor)
            if (app()->bound(\Modules\INVENTORY\Interfaces\InventoryStockProviderInterface::class)) {
                $provider = app(\Modules\INVENTORY\Interfaces\InventoryStockProviderInterface::class);
                $items = $ret->items->map(fn($it)=>['product_id'=>$it->product_id,'variant_id'=>$it->variant_id,'warehouse_id'=>$ret->warehouse_id,'qty'=>(float)$it->qty])->toArray();
                // deductStock = OUT
                $provider->deductStock($items, $ret->return_no, 'purchase_return');
            }
            // 2) Journal: AP Dr / Inventory Cr / VAT Input Cr (reverse of purchase bill)
            $journalId = null;
            if (app()->bound(\Modules\ACCOUNTS\Interfaces\AccountsPostingProviderInterface::class)) {
                $posting = app(\Modules\ACCOUNTS\Interfaces\AccountsPostingProviderInterface::class);
                $apCode = config('accounts.defaults.payable_code','2100');
                $invCode = config('accounts.defaults.inventory_code','1200');
                $vatCode = config('accounts.defaults.vat_input_code','1330');
                $grniCode = config('accounts.defaults.grni_code','2115');
                $apId = $posting->accountIdByCode($apCode);
                $invId = $posting->accountIdByCode($invCode);
                $vatId = $posting->accountIdByCode($vatCode);
                $grniId = $posting->accountIdByCode($grniCode);

                if ($apId && $invId) {
                    $lines = [];
                    $subtotal = (float) $ret->subtotal - (float) $ret->discount_amount;
                    $vat = (float) $ret->vat_amount;
                    $total = (float) $ret->total_amount;
                    // AP Dr (debit note reduces payable)
                    $lines[] = new \Modules\ACCOUNTS\DTO\JournalLineData(accountId: $apId, debit: $total, description: "Return {$ret->return_no} - {$ret->vendor?->name}");
                    // Inventory Cr
                    $lines[] = new \Modules\ACCOUNTS\DTO\JournalLineData(accountId: $invId, credit: $subtotal, description: "Return inventory {$ret->return_no}");
                    // VAT Cr
                    if ($vat > 0 && $vatId) $lines[] = new \Modules\ACCOUNTS\DTO\JournalLineData(accountId: $vatId, credit: $vat, description: "VAT on return {$ret->return_no}");

                    $dto = new \Modules\ACCOUNTS\DTO\JournalData(journalDate: $ret->return_date->format('Y-m-d'), type: 'PV', referenceNo: $ret->return_no, narration: "Purchase Return {$ret->return_no}", lines: $lines, partnerId: $ret->vendor_id, partnerType: 'vendor', sourceType: 'purchase_return', sourceId: $ret->id);
                    $journal = $posting->postJournal($dto);
                    $journalId = $journal->id;
                }
            }
            $ret->update(['status'=>'posted','journal_id'=>$journalId,'posted_by'=>auth()->id(),'posted_at'=>now()]);
            return $ret->fresh(['items','journal']);
        });
    }

    public function approve(PurchaseReturn $ret): PurchaseReturn
    {
        if (! $ret->isDraft()) throw new \DomainException('Only draft can be approved.');
        // Approval threshold logic not needed for returns
        return $ret;
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
