<?php

namespace Modules\INVENTORY\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\CORE\Support\DataTableQuery;
use Modules\INVENTORY\DTO\StockMoveData;
use Modules\INVENTORY\Interfaces\StockRepositoryInterface;
use Modules\INVENTORY\Models\Stock;
use Modules\INVENTORY\Models\StockMove;

class StockService
{
    public function __construct(private readonly StockRepositoryInterface $repo) {}

    public function paginate(DataTableQuery $dt): LengthAwarePaginator { return $this->repo->paginate($dt); }
    public function exportBuilder(DataTableQuery $dt): Builder { return $this->repo->exportBuilder($dt); }
    public function findOrFail(int $id): Stock { return $this->repo->findOrFail($id); }
    public function getForProduct(int $productId, ?int $warehouseId = null): Collection { return $this->repo->getForProduct($productId, $warehouseId); }
    public function getLowStock(): Collection { return $this->repo->getLowStock(); }
    public function getOutOfStock(): Collection { return $this->repo->getOutOfStock(); }

    /**
     * Core stock ledger — append-only StockMove + upsert Stock cache in one TX.
     * This is the ONLY place that writes inventory_stocks (Single Responsibility).
     */
    public function move(StockMoveData $dto): StockMove
    {
        return DB::transaction(function () use ($dto): StockMove {
            $qty = abs((float) $dto->qty);
            $direction = $dto->direction;
            $sign = $direction === 'in' ? 1 : -1;

            $product = \Modules\INVENTORY\Models\Product::find($dto->productId);
            $warehouse = \Modules\INVENTORY\Models\Warehouse::find($dto->warehouseId);
            $allowNegative = $warehouse?->allow_negative_stock || (bool) config('inventory.valuation.allow_negative_stock', false);
            $isFifo = $product && $product->valuation_method === 'fifo';

            // FIFO OUT: consume layers first to determine COGS before creating move
            $effectiveUnitCost = (float) $dto->unitCost;
            $consumedCost = $qty * $effectiveUnitCost;
            $fifoConsumption = [];
            if ($sign < 0 && $isFifo) {
                $needed = $qty;
                $consumedCost = 0;
                $layers = \Modules\INVENTORY\Models\FifoLayer::where('product_id', $dto->productId)
                    ->where('warehouse_id', $dto->warehouseId)
                    ->when($dto->variantId, fn($q)=>$q->where('variant_id', $dto->variantId), fn($q)=>$q->whereNull('variant_id'))
                    ->where('qty_remaining', '>', 0)
                    ->orderBy('received_at')->orderBy('id')
                    ->lockForUpdate()->get();
                $available = (float) $layers->sum('qty_remaining');
                if ($available < $needed && ! $allowNegative) {
                    throw new \DomainException("FIFO insufficient stock for {$product?->name} ({$product?->sku}) in {$warehouse?->name}. FIFO available: {$available}, requested: {$qty}");
                }
                foreach ($layers as $layer) {
                    if ($needed <= 0) break;
                    $take = min((float) $layer->qty_remaining, $needed);
                    $consumedCost += $take * (float) $layer->unit_cost;
                    $fifoConsumption[] = ['layer'=>$layer, 'take'=>$take];
                    $needed -= $take;
                }
                // allowNegative: if still needed, consume at current avg
                if ($needed > 0) {
                    $fallbackCost = (float) ($product->cost_price ?: $dto->unitCost);
                    $consumedCost += $needed * $fallbackCost;
                    $fifoConsumption[] = ['layer'=>null, 'take'=>$needed, 'unit_cost'=>$fallbackCost];
                }
                $effectiveUnitCost = $qty > 0 ? $consumedCost / $qty : 0;
            }

            $moveNo = app(InventoryCodeGeneratorService::class)->generate('SM');
            $totalCost = $isFifo && $sign < 0 ? $consumedCost : $qty * (float) $dto->unitCost;

            $move = StockMove::create([
                'move_no' => $moveNo,
                'product_id' => $dto->productId,
                'variant_id' => $dto->variantId,
                'warehouse_id' => $dto->warehouseId,
                'location_id' => $dto->locationId,
                'qty' => $qty,
                'direction' => $direction,
                'uom_id' => $dto->uomId,
                'unit_cost' => $isFifo && $sign < 0 ? $effectiveUnitCost : (float) $dto->unitCost,
                'total_cost' => $totalCost,
                'reference_type' => $dto->referenceType,
                'reference_no' => $dto->referenceNo,
                'lot_no' => $dto->lotNo,
                'serial_no' => $dto->serialNo,
                'reason' => $dto->reason,
                'moved_by' => auth()->id(),
                'moved_at' => now(),
            ]);

            // persist FIFO lot/layer for IN, consume for OUT
            if ($sign > 0 && ($isFifo || $product?->is_batch_tracked)) {
                $lotNo = $dto->lotNo;
                if ($product?->is_batch_tracked && ! $lotNo) {
                    $lotNo = app(InventoryCodeGeneratorService::class)->generate('LOT');
                    $move->update(['lot_no'=>$lotNo]);
                }
                $lotId = null;
                if ($lotNo) {
                    $lot = \Modules\INVENTORY\Models\Lot::firstOrCreate(['lot_no'=>$lotNo], [
                        'product_id'=>$dto->productId,
                        'supplier_partner_id'=>null,
                        'qty_received'=>0,
                        'qty_remaining'=>0,
                        'unit_cost'=>(float) $dto->unitCost,
                        'warehouse_id'=>$dto->warehouseId,
                        'initial_move_id'=>$move->id,
                    ]);
                    $lot->increment('qty_received', $qty);
                    $lot->increment('qty_remaining', $qty);
                    $lotId = $lot->id;
                }
                \Modules\INVENTORY\Models\FifoLayer::create([
                    'product_id'=>$dto->productId,'variant_id'=>$dto->variantId,'warehouse_id'=>$dto->warehouseId,
                    'lot_id'=>$lotId,'lot_no'=>$lotNo,'stock_move_id'=>$move->id,
                    'qty_in'=>$qty,'qty_remaining'=>$qty,'unit_cost'=>(float) $dto->unitCost,'total_cost'=>$totalCost,'received_at'=>now(),
                ]);
            }
            if ($sign < 0 && $isFifo) {
                foreach ($fifoConsumption as $c) {
                    if ($c['layer']) {
                        $c['layer']->decrement('qty_remaining', $c['take']);
                        // sync lot qty_remaining
                        if ($c['layer']->lot_id) {
                            \Modules\INVENTORY\Models\Lot::where('id', $c['layer']->lot_id)->decrement('qty_remaining', $c['take']);
                        }
                    }
                }
            }

            // Upsert stock cache — lock row for concurrent safety
            $stock = Stock::where([
                'product_id' => $dto->productId,
                'variant_id' => $dto->variantId,
                'warehouse_id' => $dto->warehouseId,
                'location_id' => $dto->locationId,
                'lot_no' => $dto->lotNo,
            ])->lockForUpdate()->first();

            if (! $stock) {
                if ($sign < 0 && ! $allowNegative) {
                    throw new \DomainException("Insufficient stock for product #{$dto->productId} in warehouse #{$dto->warehouseId}. On-hand: 0, requested: {$qty}");
                }
                $qtyOnHand = $sign * $qty;
                $avgCost = $isFifo ? ($sign > 0 ? (float) $dto->unitCost : $effectiveUnitCost) : (float) ($sign < 0 ? $effectiveUnitCost : $dto->unitCost);
                Stock::create([
                    'product_id' => $dto->productId,
                    'variant_id' => $dto->variantId,
                    'warehouse_id' => $dto->warehouseId,
                    'location_id' => $dto->locationId,
                    'lot_no' => $dto->lotNo,
                    'qty_on_hand' => $qtyOnHand,
                    'qty_reserved' => 0,
                    'qty_available' => $qtyOnHand,
                    'avg_cost' => $avgCost,
                    'total_value' => $isFifo && $sign < 0 ? -$consumedCost : $qtyOnHand * $avgCost,
                    'last_movement_at' => now(),
                ]);
            } else {
                $newOnHand = (float) $stock->qty_on_hand + ($sign * $qty);
                if ($newOnHand < 0 && ! $allowNegative) {
                    throw new \DomainException("Insufficient stock for {$product?->name} ({$product?->sku}) in {$warehouse?->name}. On-hand: {$stock->qty_on_hand}, requested: {$qty}");
                }
                if ($isFifo) {
                    // FIFO total_value = sum remaining layers (or stock total_value delta)
                    $newTotal = (float) $stock->total_value + ($sign > 0 ? $totalCost : -$consumedCost);
                    $stock->total_value = $newTotal;
                    $stock->avg_cost = $newOnHand > 0 ? $newTotal / $newOnHand : (float) $stock->avg_cost;
                } else {
                    if ($sign > 0) {
                        $oldValue = (float) $stock->qty_on_hand * (float) $stock->avg_cost;
                        $newValue = $totalCost;
                        $combinedQty = (float) $stock->qty_on_hand + $qty;
                        $newAvg = $combinedQty > 0 ? ($oldValue + $newValue) / $combinedQty : (float) $dto->unitCost;
                        $stock->avg_cost = $newAvg;
                    }
                    $stock->total_value = $newOnHand * (float) $stock->avg_cost;
                }
                $stock->qty_on_hand = $newOnHand;
                $stock->qty_available = $newOnHand - (float) $stock->qty_reserved;
                $stock->last_movement_at = now();
                $stock->save();
            }

            return $move;
        });
    }

    public function reserve(int $productId, ?int $variantId, int $warehouseId, float $qty): void
    {
        DB::transaction(function () use ($productId, $variantId, $warehouseId, $qty): void {
            $stock = Stock::where(['product_id' => $productId, 'variant_id' => $variantId, 'warehouse_id' => $warehouseId])->lockForUpdate()->first();
            if (! $stock) throw new \DomainException('No stock found to reserve.');
            if ((float) $stock->qty_available < $qty) throw new \DomainException("Insufficient available stock. Available: {$stock->qty_available}, requested: {$qty}");
            $stock->increment('qty_reserved', $qty);
            $stock->update(['qty_available' => (float) $stock->qty_on_hand - (float) $stock->fresh()->qty_reserved]);
        });
    }

    public function release(int $productId, ?int $variantId, int $warehouseId, float $qty): void
    {
        DB::transaction(function () use ($productId, $variantId, $warehouseId, $qty): void {
            $stock = Stock::where(['product_id' => $productId, 'variant_id' => $variantId, 'warehouse_id' => $warehouseId])->lockForUpdate()->first();
            if (! $stock) return;
            $stock->decrement('qty_reserved', min($qty, (float) $stock->qty_reserved));
            $stock->update(['qty_available' => (float) $stock->qty_on_hand - (float) $stock->fresh()->qty_reserved]);
        });
    }
}
