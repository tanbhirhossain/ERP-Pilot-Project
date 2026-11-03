<?php

namespace Modules\INVENTORY\Interfaces;

use Modules\INVENTORY\DTO\ValuationData;

interface InventoryValuationProviderInterface
{
    public function getUnitCost(int $productId, ?int $variantId = null, ?int $warehouseId = null): float;
    public function getValuation(int $productId, ?int $warehouseId = null): ?ValuationData;
    public function getTotalValuation(?int $warehouseId = null): float;
    public function getStockValueByCategory(int $categoryId): float;
}
