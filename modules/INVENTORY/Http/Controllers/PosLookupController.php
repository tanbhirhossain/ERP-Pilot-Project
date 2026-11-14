<?php

namespace Modules\INVENTORY\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\CORE\Support\OptionAccess;
use Modules\INVENTORY\Models\Product;
use Modules\INVENTORY\Models\Stock;
use Modules\PARTNER\Interfaces\PricingProviderInterface;

/**
 * Exact-match product lookup for the POS terminal (id, sku or barcode).
 *
 * Separate from the `options` typeahead because the terminal scans a barcode and needs one product
 * back, not a page of suggestions — and because this payload is the most sensitive of any selector
 * in the application: it returns `selling_price` **and live stock levels** (`available`, `on_hand`)
 * for a single lookup, with no rate limit. It had no authorisation beyond `['web', 'auth']`, so any
 * authenticated user could walk the barcode space and build a priced inventory count.
 */
class PosLookupController extends Controller
{
    /** @var array<string, list<string>> type => permissions, any of which is sufficient */
    private const PERMISSIONS = [
        'pos-product' => [
            'pos.terminal.sell', 'pos.sessions.manage',
            'sales.invoices.view', 'sales.invoices.manage',
            'inventory.products.view', 'inventory.stocks.view', 'inventory.movements.view',
        ],
    ];

    public function __invoke(Request $request, string $key): JsonResponse
    {
        if ($denied = OptionAccess::gate($request, 'pos-product', self::PERMISSIONS)) {
            return $denied;
        }

        // key can be id, sku, or barcode
        $product = Product::query()->active()
            ->where(function ($q) use ($key) {
                $q->where('id', $key)->orWhere('sku', $key)->orWhere('barcode', $key);
            })->first();

        if (! $product) {
            return response()->json(['found' => false, 'message' => 'Product not found for: '.$key]);
        }

        $warehouseId = $request->query('warehouse_id');
        $stock = Stock::where('product_id', $product->id)->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))->first();

        // Contract/tier pricing (Phase 1): when the till has a named customer,
        // resolve their price list so the scan rings up the contract price, not
        // the shelf price. Falls back to the product's selling price. The price
        // stays a decimal string (the money contract every consumer relies on).
        $sellingPrice = $product->selling_price;
        $priceListName = null;
        $customerId = $request->query('customer_id');
        if ($customerId !== null && app()->bound(PricingProviderInterface::class)) {
            $resolved = app(PricingProviderInterface::class)
                ->resolve($product->id, (int) $customerId, (float) ($request->query('qty', 1)));
            if ($resolved !== null) {
                $sellingPrice = number_format($resolved['unit_price'], 2, '.', '');
                $priceListName = $resolved['price_list_name'];
            }
        }

        return response()->json([
            'found' => true,
            'data' => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'barcode' => $product->barcode,
                'selling_price' => $sellingPrice,
                'price_list_name' => $priceListName,
                'vat_rate' => $product->vat_rate ?? 0,
                'uom' => $product->uom?->symbol,
                'available' => $stock?->qty_available ?? $stock?->qty_on_hand ?? 0,
                'on_hand' => $stock?->qty_on_hand ?? 0,
            ],
        ]);
    }
}
