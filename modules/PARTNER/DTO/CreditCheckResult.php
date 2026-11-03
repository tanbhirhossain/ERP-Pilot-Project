<?php

namespace Modules\PARTNER\DTO;

final readonly class CreditCheckResult
{
    public function __construct(
        public bool $isApproved,
        public float $currentBalance,
        public float $creditLimit,
        public float $availableCredit,
        public float $requestedAmount,
        public float $projectedBalance,
        public float $utilizationPct,
        public string $message,
        public string $level, // ok, warning, exceeded
    ) {}

    public function toArray(): array
    {
        return [
            'is_approved' => $this->isApproved,
            'current_balance' => $this->currentBalance,
            'credit_limit' => $this->creditLimit,
            'available_credit' => $this->availableCredit,
            'requested_amount' => $this->requestedAmount,
            'projected_balance' => $this->projectedBalance,
            'utilization_pct' => $this->utilizationPct,
            'message' => $this->message,
            'level' => $this->level,
        ];
    }
}
