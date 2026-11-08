<?php

namespace Modules\INVENTORY\DTO;

final class ValuationData
{
    public array $fifoLayers = [];

    public function __construct(
        public int $productId,
        public string $sku,
        public float $qtyOnHand,
        public float $avgCost,
        public float $totalValue,
        public string $valuationMethod,
        public float $fifoCost,
    ) {}

    public function toArray(): array
    {
        return [
            'product_id' => $this->productId, 'sku' => $this->sku,
            'qty_on_hand' => $this->qtyOnHand, 'avg_cost' => $this->avgCost, 'total_value' => $this->totalValue,
            'valuation_method' => $this->valuationMethod, 'fifo_cost' => $this->fifoCost,
        ];
    }
}
