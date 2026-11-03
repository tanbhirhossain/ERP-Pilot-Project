<?php

namespace Modules\PARTNER\DTO;

/**
 * Immutable DTO for cross-module consumption.
 * Never expose Eloquent models across module boundaries.
 */
final readonly class PartnerData
{
    public function __construct(
        public int $id,
        public string $partnerCode,
        public string $type,
        public string $name,
        public ?string $legalName,
        public ?int $groupId,
        public ?string $groupName,
        public ?string $phone,
        public ?string $email,
        public float $creditLimit,
        public float $currentBalance,
        public string $currency,
        public bool $isActive,
        public bool $isVerified,
        public ?string $paymentTerms,
        public ?int $paymentTermsDays,
        public ?string $primaryAddress,
        public ?string $primaryContactName,
        public ?string $primaryContactPhone,
    ) {}

    public static function fromModel(\Modules\PARTNER\Models\Partner $partner): self
    {
        return new self(
            id: $partner->id,
            partnerCode: $partner->partner_code,
            type: $partner->type,
            name: $partner->name,
            legalName: $partner->legal_name,
            groupId: $partner->group_id,
            groupName: $partner->group?->name,
            phone: $partner->phone,
            email: $partner->email,
            creditLimit: (float) $partner->credit_limit,
            currentBalance: (float) $partner->current_balance,
            currency: $partner->currency ?? 'BDT',
            isActive: (bool) $partner->is_active,
            isVerified: (bool) $partner->is_verified,
            paymentTerms: $partner->payment_terms,
            paymentTermsDays: $partner->payment_terms_days,
            primaryAddress: $partner->addresses->firstWhere('is_default', true)?->full_address ?? $partner->addresses->first()?->full_address,
            primaryContactName: $partner->contacts->firstWhere('is_primary', true)?->name ?? $partner->contacts->first()?->name,
            primaryContactPhone: $partner->contacts->firstWhere('is_primary', true)?->phone ?? $partner->contacts->first()?->phone,
        );
    }

    public function availableCredit(): float
    {
        return max(0, $this->creditLimit - $this->currentBalance);
    }

    public function isCreditExceeded(): bool
    {
        return $this->creditLimit > 0 && $this->currentBalance > $this->creditLimit;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'partner_code' => $this->partnerCode,
            'type' => $this->type,
            'name' => $this->name,
            'legal_name' => $this->legalName,
            'group_id' => $this->groupId,
            'group_name' => $this->groupName,
            'phone' => $this->phone,
            'email' => $this->email,
            'credit_limit' => $this->creditLimit,
            'current_balance' => $this->currentBalance,
            'available_credit' => $this->availableCredit(),
            'currency' => $this->currency,
            'is_active' => $this->isActive,
            'is_verified' => $this->isVerified,
            'payment_terms' => $this->paymentTerms,
            'payment_terms_days' => $this->paymentTermsDays,
            'primary_address' => $this->primaryAddress,
            'primary_contact_name' => $this->primaryContactName,
            'primary_contact_phone' => $this->primaryContactPhone,
        ];
    }
}
