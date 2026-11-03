<?php

namespace Modules\INVENTORY\DTO;

final readonly class StockMoveData
{
    public function __construct(
        public int $productId,
        public ?int $variantId,
        public int $warehouseId,
        public ?int $locationId,
        public float $qty,
        public string $direction, // in|out
        public ?int $uomId = null,
        public float $unitCost = 0,
        public string $referenceType = 'manual',
        public ?string $referenceNo = null,
        public ?string $lotNo = null,
        public ?string $serialNo = null,
        public ?string $reason = null,
    ) {}

    public function toArray(): array
    {
        return [
            'product_id' => $this->productId, 'variant_id' => $this->variantId,
            'warehouse_id' => $this->warehouseId, 'location_id' => $this->locationId,
            'qty' => $this->qty, 'direction' => $this->direction, 'uom_id' => $this->uomId,
            'unit_cost' => $this->unitCost, 'reference_type' => $this->referenceType,
            'reference_no' => $this->referenceNo, 'lot_no' => $this->lotNo, 'serial_no' => $this->serialNo,
            'reason' => $this->reason,
        ];
    }
}
