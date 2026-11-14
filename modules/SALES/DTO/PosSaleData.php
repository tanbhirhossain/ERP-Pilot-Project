<?php

namespace Modules\SALES\DTO;

readonly class PosSaleData
{
    /**
     * @param  InvoiceLineData[]  $lines
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
        /**
         * Money taken now on a `due` (credit) sale. Zero means the whole sale is owed, which was
         * the only thing a credit sale could be; anything above it makes the sale part-paid and the
         * remainder is the due. Clamped to the sale total in the service, never trusted here.
         */
        public float $paidAmount = 0,
        /** What the customer actually handed over on a settled sale, so the till can give change. */
        public float $tenderedAmount = 0,
        /**
         * F22 — set only when the ticket was queued on a device while the network was down.
         *
         * `clientRef` is the device-minted UUID and the idempotency key of the replay: two syncs of
         * one ticket are one sale, not two. `rangUpAt` is when the cashier actually took the money,
         * which is not the moment the row is written — the gap is what makes a stale ticket visible
         * enough to refuse rather than quietly date it today.
         */
        public ?string $clientRef = null,
        public ?string $rangUpAt = null,
    ) {
        if (empty($this->lines)) {
            throw new \InvalidArgumentException('POS sale requires at least 1 line');
        }
    }

    public function total(): float
    {
        return array_sum(array_map(fn ($l) => $l->lineTotal(), $this->lines));
    }
}
