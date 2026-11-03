<?php

namespace Modules\INVENTORY\Actions;

use Illuminate\Support\Facades\DB;
use Modules\INVENTORY\DTO\StockMoveData;
use Modules\INVENTORY\Services\StockService;

class IssueStockAction
{
    public function __construct(private readonly StockService $stockService) {}

    public function execute(array $items, string $referenceType = 'sale', ?string $referenceNo = null): array
    {
        return DB::transaction(function () use ($items, $referenceType, $referenceNo): array {
            $moves = [];
            foreach ($items as $item) {
                $moves[] = $this->stockService->move(new StockMoveData(
                    productId: (int) $item['product_id'],
                    variantId: $item['variant_id'] ?? null,
                    warehouseId: (int) $item['warehouse_id'],
                    locationId: $item['location_id'] ?? null,
                    qty: (float) $item['qty'],
                    direction: 'out',
                    unitCost: (float) ($item['unit_cost'] ?? 0),
                    referenceType: $referenceType,
                    referenceNo: $referenceNo,
                ));
            }
            return $moves;
        });
    }
}
