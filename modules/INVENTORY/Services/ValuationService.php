<?php

namespace Modules\INVENTORY\Services;

use Illuminate\Support\Facades\DB;
use Modules\INVENTORY\DTO\ValuationData;
use Modules\INVENTORY\Models\Stock;
use Modules\INVENTORY\Models\StockMove;

class ValuationService
{
    public function getUnitCost(int $productId, ?int $variantId = null, ?int $warehouseId = null): float
    {
        $stock = Stock::where('product_id', $productId)->when($variantId, fn ($q) => $q->where('variant_id', $variantId))->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))->first();
        return $stock ? (float) $stock->avg_cost : 0;
    }

    public function getFifoCost(int $productId, ?int $variantId, int $warehouseId, float $qty): array
    {
        $needed = $qty; $cost = 0; $layers = [];
        $fifoLayers = \Modules\INVENTORY\Models\FifoLayer::where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->when($variantId, fn($q)=>$q->where('variant_id',$variantId), fn($q)=>$q->whereNull('variant_id'))
            ->where('qty_remaining','>',0)->orderBy('received_at')->orderBy('id')->get();
        foreach ($fifoLayers as $l) {
            if ($needed <= 0) break;
            $take = min((float)$l->qty_remaining, $needed);
            $layers[]=['lot_no'=>$l->lot_no,'unit_cost'=>(float)$l->unit_cost,'take'=>$take,'cost'=>$take*(float)$l->unit_cost];
            $cost += $take*(float)$l->unit_cost;
            $needed -= $take;
        }
        return ['unit_cost'=>$qty>0?$cost/$qty:0,'total_cost'=>$cost,'layers'=>$layers,'shortage'=>$needed];
    }

    public function getValuation(int $productId, ?int $warehouseId = null): ?ValuationData
    {
        $product = \Modules\INVENTORY\Models\Product::find($productId);
        if (! $product) return null;
        $stocks = Stock::where('product_id', $productId)->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))->get();
        $qty = (float) $stocks->sum('qty_on_hand');
        $value = (float) $stocks->sum('total_value');
        $avg = $qty > 0 ? $value / $qty : 0;
        $fifoValue = 0; $fifoLayers=[];
        if ($product->valuation_method === 'fifo') {
            $fifoLayers = \Modules\INVENTORY\Models\FifoLayer::where('product_id',$productId)->when($warehouseId,fn($q)=>$q->where('warehouse_id',$warehouseId))->where('qty_remaining','>',0)->get();
            $fifoValue = (float)$fifoLayers->sum(fn($l)=> (float)$l->qty_remaining * (float)$l->unit_cost);
            $value = $fifoValue; $avg = $qty>0 ? $fifoValue/$qty : 0;
        }
        $valuation = new ValuationData($productId, $product->sku, $qty, $avg, $value, $product->valuation_method, $fifoValue);
        $valuation->fifoLayers = $fifoLayers instanceof \Illuminate\Support\Collection ? $fifoLayers->toArray() : (array)$fifoLayers;
        return $valuation;
    }

    public function getTotalValuation(?int $warehouseId = null): float
    {
        return (float) Stock::when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))->sum('total_value');
    }

    public function getStockValueByCategory(int $categoryId): float
    {
        return (float) Stock::whereHas('product', fn ($q) => $q->where('category_id', $categoryId))->sum('total_value');
    }
}
