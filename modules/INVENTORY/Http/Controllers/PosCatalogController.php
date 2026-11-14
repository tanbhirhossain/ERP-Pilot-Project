<?php

namespace Modules\INVENTORY\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\CORE\Support\OptionAccess;
use Modules\INVENTORY\Models\Product;
use Modules\INVENTORY\Models\Stock;

/**
 * F22 — the whole sellable catalogue in one call, so a till can keep working with the network down.
 *
 * `api/pos-product/{key}` answers one barcode at a time and `api/options/products` answers a 60-row
 * typeahead. Both are fine online and useless offline: a cashier with no network cannot search a
 * server, and a till that cannot look up a price cannot ring up a sale. This is the bulk sibling —
 * one request that a device caches in IndexedDB and searches locally.
 *
 * The row shape is intentionally redundant. `selling_price` **and** `price`, `name` **and** `label`:
 * the terminal's two entry points (scan/add-from-search, and click-a-tile) already read those keys,
 * and the offline path must feed the *same* functions rather than a parallel set of mappers. A
 * second translation layer is where a cached price and a live price would first disagree.
 *
 * Authorisation is the lookup's, not something new. This returns the same prices and stock levels the
 * single-key endpoint does, only for everything at once, so it is gated identically — and pinned by
 * `OptionEndpointAuthorisationTest` alongside it. `cost_price` is not in the select list: a till has
 * no business reading margin.
 */
class PosCatalogController extends Controller
{
    /** @var array<string, list<string>> type => permissions, any of which is sufficient */
    private const PERMISSIONS = [
        'pos-catalog' => [
            'pos.terminal.sell', 'pos.sessions.manage',
            'sales.invoices.view', 'sales.invoices.manage',
            'inventory.products.view', 'inventory.stocks.view', 'inventory.movements.view',
        ],
    ];

    /**
     * A cap, not a page. A supermarket with 40,000 SKUs should not be handed one 40,000-row JSON
     * body on every keystroke-free refresh, and a till must be able to tell the difference between
     * "this shop has 900 products" and "you only got the first 900".
     */
    private const MAX_ITEMS = 2000;

    public function __invoke(Request $request): JsonResponse
    {
        if ($denied = OptionAccess::gate($request, 'pos-catalog', self::PERMISSIONS)) {
            return $denied;
        }

        $limit = min((int) $request->query('limit', self::MAX_ITEMS), self::MAX_ITEMS);
        $limit = $limit > 0 ? $limit : self::MAX_ITEMS;
        $warehouseId = $request->query('warehouse_id');

        $total = Product::query()->active()->count();

        $products = Product::query()
            ->active()
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'name', 'sku', 'barcode', 'selling_price', 'vat_rate']);

        // One query for every stock row, keyed by product, rather than one per product: the whole
        // point of this endpoint is that a till can refresh 2,000 prices in a single round trip.
        $stock = Stock::query()
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', (int) $warehouseId))
            ->get(['product_id', 'qty_available', 'qty_on_hand'])
            ->keyBy('product_id');

        $items = $products->map(function (Product $p) use ($stock): array {
            $row = $stock->get($p->id);

            return [
                'id' => $p->id,
                'value' => $p->id,
                'name' => $p->name,
                'label' => $p->name,
                'sku' => $p->sku,
                'barcode' => $p->barcode,
                // Decimal strings, as everywhere: `120.00`, never `120.0` and never a float a JS
                // client can round differently from the ledger.
                'selling_price' => $p->selling_price,
                'price' => $p->selling_price,
                'vat_rate' => $p->vat_rate ?? '0.00',
                'available' => $row?->qty_available ?? $row?->qty_on_hand ?? 0,
            ];
        })->values()->all();

        return response()->json([
            'items' => $items,
            // The device shows this on the till, because "cached 4 minutes ago" is the information a
            // cashier needs before trusting a price, and nothing else in the payload says it.
            'synced_at' => now()->toIso8601String(),
            'warehouse_id' => $warehouseId === null ? null : (int) $warehouseId,
            'total' => $total,
            'truncated' => $total > count($items),
        ]);
    }
}
