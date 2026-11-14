<?php

namespace Modules\PARTNER\Interfaces;

/**
 * DIP: SALES/POS depend on this to resolve contract/tier pricing, not on the
 * concrete PriceList models. Returns null when no list governs the line, so the
 * caller falls back to the product's own selling price.
 */
interface PricingProviderInterface
{
    /**
     * Resolve the price for a product for a given partner at a quantity.
     *
     * Resolution order:
     *   1. the partner's assigned price list (if active + in effect)
     *   2. the default price list (is_default, active + in effect)
     *   3. null — caller uses product.selling_price
     *
     * Within a list, the item with the highest min_qty <= qty wins (tier break).
     *
     * @return array{unit_price: float, discount_pct: float, price_list_id: int, price_list_name: string}|null
     */
    public function resolve(int $productId, ?int $partnerId, float $qty = 1, ?string $asOf = null): ?array;
}
