<?php

namespace Modules\INVENTORY\Services;

use Modules\INVENTORY\DTO\ValuationData;
use Modules\INVENTORY\Interfaces\InventoryValuationProviderInterface;

class InventoryValuationProvider implements InventoryValuationProviderInterface
{
    public function __construct(private readonly ValuationService $valuationService) {}

    public function getUnitCost(int $productId, ?int $variantId = null, ?int $warehouseId = null): float
    {
        return $this->valuationService->getUnitCost($productId, $variantId, $warehouseId);
    }

    public function getValuation(int $productId, ?int $warehouseId = null): ?ValuationData
    {
        return $this->valuationService->getValuation($productId, $warehouseId);
    }

    public function getTotalValuation(?int $warehouseId = null): float
    {
        return $this->valuationService->getTotalValuation($warehouseId);
    }

    public function getStockValueByCategory(int $categoryId): float
    {
        return $this->valuationService->getStockValueByCategory($categoryId);
    }
}
