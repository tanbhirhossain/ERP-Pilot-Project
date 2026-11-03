<?php

namespace Modules\SALES\DTO;

readonly class InvoiceLineData
{
    public function __construct(
        public int $productId,
        public float $qty,
        public float $unitPrice,
        public ?int $variantId = null,
        public float $discountPct = 0,
        public float $vatRate = 0,
        public ?string $description = null,
    ) {
        if ($qty <= 0) throw new \InvalidArgumentException('Qty must be >0');
        if ($unitPrice < 0) throw new \InvalidArgumentException('Price cannot be negative');
    }

    public function lineTotal(): float
    {
        $gross = $this->qty * $this->unitPrice;
        $discount = $gross * ($this->discountPct / 100);
        $net = $gross - $discount;
        $vat = $net * ($this->vatRate / 100);
        return $net + $vat;
    }
}
