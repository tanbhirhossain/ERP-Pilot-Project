<?php

namespace Modules\INVENTORY\Services;

use Illuminate\Support\Facades\DB;
use Modules\INVENTORY\Models\Kit;

class KitService
{
    public function nextNo(): string
    {
        $last = Kit::withTrashed()->orderByDesc('id')->value('kit_no');
        $n = $last ? (int) preg_replace('/\D/', '', $last) : 0;
        return 'KIT-'.str_pad((string)($n+1), 5, '0', STR_PAD_LEFT);
    }

    public function create(array $data): Kit
    {
        return DB::transaction(function () use ($data): Kit {
            $data['kit_no'] = $this->nextNo();
            $data['status'] = 'draft';
            $data['created_by'] = auth()->id();
            $items = $data['items'] ?? [];
            unset($data['items']);
            // compute total component cost from current valuation
            $valuation = app(ValuationService::class);
            $total = 0;
            foreach ($items as &$it) {
                $prod = \Modules\INVENTORY\Models\Product::find($it['component_product_id']);
                $cost = $prod ? (float) $valuation->getUnitCost($prod->id, null, $data['warehouse_id']) : 0;
                $it['unit_cost'] = $cost;
                $it['line_cost'] = $cost * (float) $it['required_qty'];
                $total += $it['line_cost'];
            }
            unset($it);
            $data['total_component_cost'] = $total;
            $data['unit_cost'] = $data['qty_to_produce'] > 0 ? $total / (float) $data['qty_to_produce'] : 0;
            $kit = Kit::create($data);
            foreach ($items as $it) $kit->items()->create($it);
            return $kit->fresh(['items.component','product','warehouse']);
        });
    }

    public function complete(Kit $kit): Kit
    {
        if (!$kit->isDraft() && $kit->status !== 'in_progress') throw new \DomainException('Only draft/in_progress kitting can be completed');
        return DB::transaction(function () use ($kit): Kit {
            $kit->load(['items.component','product']);
            // 1) consume components (OUT)
            $stockProvider = app()->bound(\Modules\INVENTORY\Interfaces\InventoryStockProviderInterface::class) ? app(\Modules\INVENTORY\Interfaces\InventoryStockProviderInterface::class) : null;
            if (!$stockProvider) throw new \DomainException('Stock provider missing');
            $valuation = app(ValuationService::class);
            $totalCost = 0;
            foreach ($kit->items as $ki) {
                if ($ki->is_optional && (float)$ki->required_qty <= 0) continue;
                $unitCost = (float) $valuation->getUnitCost($ki->component_product_id, null, $kit->warehouse_id);
                // fallback to snapshot if valuation zero
                $unitCost = $unitCost > 0 ? $unitCost : (float) $ki->unit_cost;
                $ki->update(['unit_cost'=>$unitCost, 'line_cost'=>$unitCost * (float)$ki->required_qty, 'consumed_qty'=>$ki->required_qty]);
                $totalCost += $ki->line_cost;
                $stockProvider->deductStock([[
                    'product_id'=>$ki->component_product_id,'warehouse_id'=>$kit->warehouse_id,'qty'=>(float)$ki->required_qty,'unit_cost'=>$unitCost
                ]], $kit->kit_no, 'kit_component');
            }
            $unitCost = $kit->qty_to_produce > 0 ? $totalCost / (float) $kit->qty_to_produce : 0;
            // 2) produce finished kit (IN)
            $stockProvider->restock([[
                'product_id'=>$kit->product_id,'warehouse_id'=>$kit->warehouse_id,'qty'=>(float)$kit->qty_to_produce,'unit_cost'=>$unitCost
            ]], $kit->kit_no, 'kit_production');
            $kit->update(['status'=>'completed','total_component_cost'=>$totalCost,'unit_cost'=>$unitCost,'qty_produced'=>$kit->qty_to_produce,'completed_by'=>auth()->id(),'completed_at'=>now()]);
            // 3) Journal: Dr Inventory Kit, Cr Inventory Components
            if (app()->bound(\Modules\ACCOUNTS\Interfaces\AccountsPostingProviderInterface::class)) {
                $posting = app(\Modules\ACCOUNTS\Interfaces\AccountsPostingProviderInterface::class);
                $invId = $posting->accountIdByCode(config('accounts.defaults.inventory_code','1200'));
                if ($invId && $totalCost > 0) {
                    $lines = [
                        new \Modules\ACCOUNTS\DTO\JournalLineData(accountId:$invId, debit:$totalCost, description:"Kit production {$kit->kit_no} → {$kit->product->sku}"),
                        new \Modules\ACCOUNTS\DTO\JournalLineData(accountId:$invId, credit:$totalCost, description:"Kit components consumed {$kit->kit_no}"),
                    ];
                    $dto = new \Modules\ACCOUNTS\DTO\JournalData(journalDate:now()->toDateString(), type:'JV', referenceNo:$kit->kit_no, narration:"Kitting {$kit->kit_no} {$kit->product->name} x{$kit->qty_to_produce}", lines:$lines, sourceType:'kit', sourceId:$kit->id);
                    $posting->postJournal($dto);
                }
            }
            return $kit->fresh(['items.component','product']);
        });
    }

    public function cancel(Kit $kit): Kit
    {
        if ($kit->status === 'completed') throw new \DomainException('Completed kit cannot be cancelled');
        $kit->update(['status'=>'cancelled']);
        return $kit;
    }
}
