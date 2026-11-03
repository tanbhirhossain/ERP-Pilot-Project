<?php

namespace Modules\PARTNER\Interfaces;

use Illuminate\Support\Collection;
use Modules\PARTNER\DTO\CreditCheckResult;
use Modules\PARTNER\DTO\PartnerData;

/**
 * Cross-module contract — the ONLY way other modules access partner data.
 * Other modules must depend on this interface, never on Partner model directly (DIP).
 */
interface PartnerDataProviderInterface
{
    public function vendorOptions(?string $search = null, int $limit = 25): Collection;
    public function customerOptions(?string $search = null, int $limit = 25): Collection;
    public function allPartnerOptions(?string $search = null, int $limit = 25): Collection;
    public function findPartnerData(string $partnerCode): ?PartnerData;
    public function findPartnerDataById(int $id): ?PartnerData;
    public function creditCheck(string $partnerCode, float $additionalAmount = 0): ?CreditCheckResult;
    public function isVendor(string $partnerCode): bool;
    public function isCustomer(string $partnerCode): bool;
}
