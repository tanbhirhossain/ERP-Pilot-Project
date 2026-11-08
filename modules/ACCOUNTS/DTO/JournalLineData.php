<?php

namespace Modules\ACCOUNTS\DTO;

readonly class JournalLineData
{
    public function __construct(
        public int $accountId,
        public float $debit = 0,
        public float $credit = 0,
        public ?string $description = null,
        public ?int $partnerId = null,
        public ?string $partnerType = null,
        public ?string $costCenter = null,
    ) {
        if ($debit < 0 || $credit < 0) {
            throw new \InvalidArgumentException('Debit/credit cannot be negative.');
        }
        if ($debit > 0 && $credit > 0) {
            throw new \InvalidArgumentException('A line cannot have both debit and credit.');
        }
        if ($debit === 0.0 && $credit === 0.0) {
            throw new \InvalidArgumentException('A line must have debit or credit.');
        }
    }

    public static function fromArray(array $a): self
    {
        return new self(
            accountId: (int) $a['account_id'], debit: (float) ($a['debit'] ?? 0), credit: (float) ($a['credit'] ?? 0),
            description: $a['description'] ?? null, partnerId: $a['partner_id'] ?? null,
            partnerType: $a['partner_type'] ?? null, costCenter: $a['cost_center'] ?? null,
        );
    }
}
