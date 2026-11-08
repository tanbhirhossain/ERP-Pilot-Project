<?php

namespace Modules\INVENTORY\Actions;

use Illuminate\Support\Facades\DB;
use Modules\INVENTORY\DTO\StockMoveData;
use Modules\INVENTORY\Services\StockService;

class ReceiveStockAction
{
    public function __construct(private readonly StockService $stockService) {}

    /**
     * Receive stock (purchase, opening, return).
     *
     * @param  array  $items  each: product_id, variant_id, warehouse_id, location_id, qty, unit_cost, lot_no
     */
    public function execute(array $items, string $referenceType = 'purchase', ?string $referenceNo = null, ?string $reason = null): array
    {
        return DB::transaction(function () use ($items, $referenceType, $referenceNo, $reason): array {
            $moves = [];
            foreach ($items as $item) {
                $moves[] = $this->stockService->move(new StockMoveData(
                    productId: (int) $item['product_id'],
                    variantId: $item['variant_id'] ?? null,
                    warehouseId: (int) $item['warehouse_id'],
                    locationId: $item['location_id'] ?? null,
                    qty: (float) $item['qty'],
                    direction: 'in',
                    unitCost: (float) ($item['unit_cost'] ?? 0),
                    referenceType: $referenceType,
                    referenceNo: $referenceNo,
                    lotNo: $item['lot_no'] ?? null,
                    reason: $reason,
                ));
            }

            return $moves;
        });
    }
}
