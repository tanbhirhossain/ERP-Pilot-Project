<?php

namespace Modules\SALES\Support;

use Modules\INVENTORY\Models\Product;
use Modules\PARTNER\Interfaces\PricingProviderInterface;

/**
 * Fills a sales-document line's price from the contract/tier pricing engine when
 * the operator did not type one (Phase 1 — F10).
 *
 * Rule: an explicit unit_price always wins. Only a blank/zero price is resolved,
 * so a negotiated override is never silently replaced, and every existing test
 * that passes unit_price keeps its exact arithmetic.
 *
 * Resolution: partner price list → default price list → product.selling_price.
 * The list's discount_pct is applied only when the line has no explicit discount.
 */
final class LinePricer
{
    public static function apply(array &$item, ?int $customerId, ?string $asOf = null): void
    {
        $productId = (int) ($item['product_id'] ?? 0);

        if ($productId <= 0) {
            return;
        }

        $explicit = isset($item['unit_price']) && $item['unit_price'] !== null && $item['unit_price'] !== '' && (float) $item['unit_price'] > 0;

        if ($explicit) {
            return;
        }

        $qty = (float) ($item['qty'] ?? 1);

        if (app()->bound(PricingProviderInterface::class)) {
            $resolved = app(PricingProviderInterface::class)->resolve($productId, $customerId, $qty, $asOf);

            if ($resolved !== null) {
                $item['unit_price'] = $resolved['unit_price'];

                if (! isset($item['discount_pct']) || $item['discount_pct'] === null || $item['discount_pct'] === '') {
                    $item['discount_pct'] = $resolved['discount_pct'];
                }

                return;
            }
        }

        // Fall back to the product's own selling price.
        $product = Product::find($productId);

        if ($product !== null && (float) $product->selling_price > 0) {
            $item['unit_price'] = (float) $product->selling_price;
        }
    }
}
