<?php

namespace Modules\ACCOUNTS\DTO;

readonly class JournalData
{
    /**
     * @param JournalLineData[] $lines min 2, balanced
     */
    public function __construct(
        public string $journalDate, // Y-m-d
        public string $type = 'JV', // JV|PV|RV|CV|BV|SV
        public ?string $referenceNo = null,
        public ?string $narration = null,
        public array $lines = [],
        public ?int $partnerId = null,
        public ?string $partnerType = null,
        public ?string $sourceType = null,
        public ?int $sourceId = null,
    ) {
        if (count($this->lines) < 2) throw new \InvalidArgumentException('Journal requires at least 2 lines.');
        $debit = array_sum(array_map(fn($l) => $l->debit, $this->lines));
        $credit = array_sum(array_map(fn($l) => $l->credit, $this->lines));
        if (abs($debit - $credit) > 0.01) throw new \InvalidArgumentException("Journal not balanced: debit {$debit} != credit {$credit}");
    }

    public function totalDebit(): float { return array_sum(array_map(fn($l) => $l->debit, $this->lines)); }
    public function totalCredit(): float { return array_sum(array_map(fn($l) => $l->credit, $this->lines)); }
}
