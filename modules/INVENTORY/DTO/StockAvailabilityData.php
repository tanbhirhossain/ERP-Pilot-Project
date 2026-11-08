<?php

namespace Modules\INVENTORY\DTO;

use Modules\INVENTORY\Models\Stock;

final readonly class StockAvailabilityData
{
    public function __construct(
        public int $productId,
        public string $sku,
        public string $productName,
        public ?int $variantId,
        public int $warehouseId,
        public string $warehouseName,
        public ?int $locationId,
        public float $qtyOnHand,
        public float $qtyReserved,
        public float $qtyAvailable,
        public float $avgCost,
        public float $totalValue,
        public bool $isLowStock,
        public bool $isOutOfStock,
    ) {}

    public static function fromStock(Stock $s): self
    {
        return new self(
            productId: $s->product_id,
            sku: $s->product->sku,
            productName: $s->product->name,
            variantId: $s->variant_id,
            warehouseId: $s->warehouse_id,
            warehouseName: $s->warehouse->name,
            locationId: $s->location_id,
            qtyOnHand: (float) $s->qty_on_hand,
            qtyReserved: (float) $s->qty_reserved,
            qtyAvailable: (float) $s->qty_available,
            avgCost: (float) $s->avg_cost,
            totalValue: (float) $s->total_value,
            isLowStock: $s->isLowStock(),
            isOutOfStock: $s->isOutOfStock(),
        );
    }

    public function canFulfill(float $qty): bool
    {
        return $this->qtyAvailable >= $qty;
    }

    public function toArray(): array
    {
        return [
            'product_id' => $this->productId, 'sku' => $this->sku, 'product_name' => $this->productName,
            'variant_id' => $this->variantId, 'warehouse_id' => $this->warehouseId, 'warehouse_name' => $this->warehouseName,
            'qty_on_hand' => $this->qtyOnHand, 'qty_reserved' => $this->qtyReserved, 'qty_available' => $this->qtyAvailable,
            'avg_cost' => $this->avgCost, 'total_value' => $this->totalValue,
            'is_low_stock' => $this->isLowStock, 'is_out_of_stock' => $this->isOutOfStock,
        ];
    }
}
