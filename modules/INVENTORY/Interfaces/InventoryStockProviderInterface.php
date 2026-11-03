<?php

namespace Modules\INVENTORY\Interfaces;

use Illuminate\Support\Collection;
use Modules\INVENTORY\DTO\StockAvailabilityData;
use Modules\INVENTORY\DTO\StockMoveData;

interface InventoryStockProviderInterface
{
    public function checkAvailability(int $productId, float $qty, ?int $warehouseId = null, ?int $variantId = null): bool;
    public function getAvailability(int $productId, ?int $warehouseId = null): Collection;
    public function getAvailabilityBySku(string $sku, ?int $warehouseId = null): ?StockAvailabilityData;
    public function reserveStock(array $items, string $referenceNo, string $referenceType = 'sale'): void;
    public function releaseReservation(array $items, string $referenceNo): void;
    public function deductStock(array $items, string $referenceNo, string $referenceType = 'sale'): void;
    public function restock(array $items, string $referenceNo, string $referenceType = 'return'): void;
    public function moveStock(StockMoveData $data): \Modules\INVENTORY\Models\StockMove;
}
