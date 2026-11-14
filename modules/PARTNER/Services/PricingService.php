<?php

namespace Modules\PARTNER\Services;

use Modules\PARTNER\Interfaces\PricingProviderInterface;
use Modules\PARTNER\Models\Partner;
use Modules\PARTNER\Models\PriceList;
use Modules\PARTNER\Models\PriceListItem;

/**
 * The pricing engine (Phase 1 — F10).
 *
 * Price lists existed as tables + UI but nothing read them: every sale used the
 * product's base selling price regardless of the customer's contract. This
 * service is the single reader. It is deliberately side-effect free — it returns
 * a price, it never writes — so it can be called from a typeahead, a document
 * form, or the POS scan path without risk.
 */
class PricingService implements PricingProviderInterface
{
    public function resolve(int $productId, ?int $partnerId, float $qty = 1, ?string $asOf = null): ?array
    {
        $asOf ??= now()->toDateString();
        $qty = max(1.0, $qty);

        $list = $this->listForPartner($partnerId, $asOf) ?? $this->defaultList($asOf);

        if ($list === null) {
            return null;
        }

        // Tier break: the highest min_qty the quantity qualifies for. A 100-unit
        // order against tiers at 1 / 50 / 100 takes the 100 price, not the 1.
        $item = PriceListItem::query()
            ->where('price_list_id', $list->id)
            ->where('product_id', $productId)
            ->where('min_qty', '<=', $qty)
            ->orderByDesc('min_qty')
            ->first();

        if ($item === null) {
            return null;
        }

        return [
            'unit_price' => (float) $item->unit_price,
            'discount_pct' => (float) $item->discount_pct,
            'price_list_id' => $list->id,
            'price_list_name' => $list->name,
        ];
    }

    private function listForPartner(?int $partnerId, string $asOf): ?PriceList
    {
        if ($partnerId === null) {
            return null;
        }

        $partner = Partner::find($partnerId);

        if ($partner === null || $partner->price_list_id === null) {
            return null;
        }

        return $this->effectiveList(PriceList::find($partner->price_list_id), $asOf);
    }

    private function defaultList(string $asOf): ?PriceList
    {
        $list = PriceList::query()->where('is_default', true)->where('is_active', true)->first();

        return $this->effectiveList($list, $asOf);
    }

    private function effectiveList(?PriceList $list, string $asOf): ?PriceList
    {
        if ($list === null || ! $list->is_active) {
            return null;
        }

        if ($list->effective_from !== null && $list->effective_from->toDateString() > $asOf) {
            return null;
        }

        if ($list->effective_to !== null && $list->effective_to->toDateString() < $asOf) {
            return null;
        }

        return $list;
    }
}
