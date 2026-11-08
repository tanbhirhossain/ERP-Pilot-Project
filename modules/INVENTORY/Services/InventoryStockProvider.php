<?php

namespace Modules\INVENTORY\Services;

use Illuminate\Support\Collection;
use Modules\INVENTORY\DTO\StockAvailabilityData;
use Modules\INVENTORY\DTO\StockMoveData;
use Modules\INVENTORY\Interfaces\InventoryStockProviderInterface;
use Modules\INVENTORY\Models\Product;
use Modules\INVENTORY\Models\Stock;
use Modules\INVENTORY\Models\StockMove;

class InventoryStockProvider implements InventoryStockProviderInterface
{
    public function __construct(private readonly StockService $stockService) {}

    public function checkAvailability(int $productId, float $qty, ?int $warehouseId = null, ?int $variantId = null): bool
    {
        $stocks = Stock::where('product_id', $productId)->when($variantId, fn ($q) => $q->where('variant_id', $variantId))->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))->get();
        $available = (float) $stocks->sum('qty_available');

        return $available >= $qty;
    }

    public function getAvailability(int $productId, ?int $warehouseId = null): Collection
    {
        return Stock::with(['warehouse', 'product'])
            ->where('product_id', $productId)
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->get()
            ->map(fn (Stock $s) => StockAvailabilityData::fromStock($s));
    }

    public function getAvailabilityBySku(string $sku, ?int $warehouseId = null): ?StockAvailabilityData
    {
        $product = Product::where('sku', $sku)->orWhere('barcode', $sku)->first();
        if (! $product) {
            return null;
        }
        $stock = Stock::with(['warehouse', 'product'])->where('product_id', $product->id)->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))->first();

        return $stock ? StockAvailabilityData::fromStock($stock) : null;
    }

    public function reserveStock(array $items, string $referenceNo, string $referenceType = 'sale'): void
    {
        foreach ($items as $item) {
            $this->stockService->reserve((int) $item['product_id'], $item['variant_id'] ?? null, (int) $item['warehouse_id'], (float) $item['qty']);
        }
    }

    public function releaseReservation(array $items, string $referenceNo): void
    {
        foreach ($items as $item) {
            $this->stockService->release((int) $item['product_id'], $item['variant_id'] ?? null, (int) $item['warehouse_id'], (float) $item['qty']);
        }
    }

    public function deductStock(array $items, string $referenceNo, string $referenceType = 'sale'): void
    {
        foreach ($items as $item) {
            $this->stockService->move(new StockMoveData(
                productId: (int) $item['product_id'],
                variantId: $item['variant_id'] ?? null,
                warehouseId: (int) $item['warehouse_id'],
                locationId: $item['location_id'] ?? null,
                qty: (float) $item['qty'],
                direction: 'out',
                unitCost: $item['unit_cost'] ?? 0,
                referenceType: $referenceType,
                referenceNo: $referenceNo,
                lotNo: $item['lot_no'] ?? null,
                serialNo: $item['serial_no'] ?? null,
            ));
        }
    }

    public function restock(array $items, string $referenceNo, string $referenceType = 'return'): void
    {
        foreach ($items as $item) {
            $this->stockService->move(new StockMoveData(
                productId: (int) $item['product_id'],
                variantId: $item['variant_id'] ?? null,
                warehouseId: (int) $item['warehouse_id'],
                locationId: $item['location_id'] ?? null,
                qty: (float) $item['qty'],
                direction: 'in',
                unitCost: $item['unit_cost'] ?? 0,
                referenceType: $referenceType,
                referenceNo: $referenceNo,
                lotNo: $item['lot_no'] ?? null,
                serialNo: $item['serial_no'] ?? null,
            ));
        }
    }

    public function moveStock(StockMoveData $data): StockMove
    {
        return $this->stockService->move($data);
    }
}
