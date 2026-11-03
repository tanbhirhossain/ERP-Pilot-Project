<?php

namespace Modules\SALES\DTO;

readonly class PosSaleData
{
    /**
     * @param InvoiceLineData[] $lines
     */
    public function __construct(
        public int $warehouseId,
        public array $lines,
        public string $paymentMethod = 'cash', // cash|bank|bkash|split
        public ?int $customerId = null,
        public string $customerName = 'Walk-in Customer',
        public ?int $bankId = null,
        public float $cashAmount = 0,
        public float $bankAmount = 0,
        public ?int $sessionId = null,
    ) {
        if (empty($this->lines)) throw new \InvalidArgumentException('POS sale requires at least 1 line');
    }

    public function total(): float { return array_sum(array_map(fn($l) => $l->lineTotal(), $this->lines)); }
}
