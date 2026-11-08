<?php

namespace Modules\PARTNER\Services;

use Illuminate\Support\Collection;
use Modules\PARTNER\DTO\CreditCheckResult;
use Modules\PARTNER\DTO\PartnerData;
use Modules\PARTNER\Interfaces\PartnerDataProviderInterface;
use Modules\PARTNER\Interfaces\PartnerRepositoryInterface;
use Modules\PARTNER\Models\Partner;

/**
 * Cross-module provider — the ONLY allowed coupling point.
 * Implements PartnerDataProviderInterface for DIP.
 */
class PartnerDataProvider implements PartnerDataProviderInterface
{
    public function __construct(
        private readonly PartnerRepositoryInterface $repo,
        private readonly PartnerService $service,
    ) {}

    public function vendorOptions(?string $search = null, int $limit = 25): Collection
    {
        return $this->repo->options($search, $limit, 'vendor');
    }

    public function customerOptions(?string $search = null, int $limit = 25): Collection
    {
        return $this->repo->options($search, $limit, 'customer');
    }

    public function allPartnerOptions(?string $search = null, int $limit = 25): Collection
    {
        return $this->repo->options($search, $limit, null);
    }

    public function findPartnerData(string $partnerCode): ?PartnerData
    {
        $partner = Partner::with(['group', 'contacts', 'addresses'])->where('partner_code', $partnerCode)->first();

        return $partner ? PartnerData::fromModel($partner) : null;
    }

    public function findPartnerDataById(int $id): ?PartnerData
    {
        $partner = Partner::with(['group', 'contacts', 'addresses'])->find($id);

        return $partner ? PartnerData::fromModel($partner) : null;
    }

    public function creditCheck(string $partnerCode, float $additionalAmount = 0): ?CreditCheckResult
    {
        $partner = Partner::where('partner_code', $partnerCode)->first();
        if (! $partner) {
            return null;
        }

        return $this->service->creditCheck($partner, $additionalAmount);
    }

    public function isVendor(string $partnerCode): bool
    {
        $p = Partner::where('partner_code', $partnerCode)->first();

        return $p ? in_array($p->type, ['vendor', 'both'], true) : false;
    }

    public function isCustomer(string $partnerCode): bool
    {
        $p = Partner::where('partner_code', $partnerCode)->first();

        return $p ? in_array($p->type, ['customer', 'both'], true) : false;
    }
}
