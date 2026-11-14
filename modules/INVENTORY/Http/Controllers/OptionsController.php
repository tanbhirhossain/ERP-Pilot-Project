<?php

namespace Modules\INVENTORY\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\CORE\Support\OptionAccess;
use Modules\INVENTORY\Services\BrandService;
use Modules\INVENTORY\Services\CategoryService;
use Modules\INVENTORY\Services\ProductService;
use Modules\INVENTORY\Services\UomService;
use Modules\INVENTORY\Services\WarehouseService;

/**
 * Typeahead source for the INVENTORY pickers — and, in practice, for half the application.
 *
 * This endpoint had no authorisation beyond `['web', 'auth']`, and `products` is the most exposed
 * picker in the system: `ProductRepository::options()` emits `sku`, `barcode`, `vat_rate` and
 * `selling_price` as `meta`/`price`, and selects `cost_price` too. Any authenticated user could
 * page through the entire catalogue with its pricing.
 *
 * `products` and `warehouses` are consumed far outside INVENTORY — 7 PURCHASE pages, 5 SALES pages
 * including the POS terminal, and `SALES/Deliveries/Create.vue` — so their permission lists are
 * deliberately broad: the rule is "may use a document that has product lines", not "may view
 * products". Gating them on `inventory.products.view` alone would break the purchase and sales
 * forms for every role not also granted inventory, and would present as a broken dropdown.
 *
 * `categories`, `brands` and `uoms` are reference data for the product form, so anyone who can
 * read or edit a product may read them.
 */
class OptionsController extends Controller
{
    /** @var array<string, list<string>> type => permissions, any of which is sufficient */
    private const PERMISSIONS = [
        'products' => [
            'inventory.products.view', 'inventory.products.create', 'inventory.products.update',
            'inventory.stocks.view', 'inventory.movements.view', 'inventory.reports.view', 'inventory.exports',
            'inventory.transfers.manage', 'inventory.transfers.approve',
            'inventory.kits.view', 'inventory.kits.manage',
            'inventory.adjustments.manage', 'inventory.adjustments.approve',
            'inventory.counts.view', 'inventory.counts.manage',
            'purchase.orders.view', 'purchase.orders.manage', 'purchase.orders.confirm', 'purchase.orders.approve',
            'purchase.receipts.view', 'purchase.receipts.manage', 'purchase.receipts.post',
            'purchase.bills.view', 'purchase.bills.manage', 'purchase.bills.post',
            'purchase.returns.view', 'purchase.returns.manage', 'purchase.returns.post',
            'purchase.landed.view', 'purchase.landed.manage',
            // F18: a requisition line names a product, so whoever can raise one can look products up.
            'purchase.requisitions.view', 'purchase.requisitions.manage',
            'purchase.rfq.view', 'purchase.rfq.manage',
            'sales.invoices.view', 'sales.invoices.manage', 'sales.invoices.post',
            'sales.orders.view', 'sales.orders.manage', 'sales.orders.confirm',
            'sales.quotations.view', 'sales.quotations.manage',
            'sales.deliveries.view', 'sales.deliveries.manage',
            'sales.returns.view', 'sales.returns.manage', 'sales.returns.approve',
            'pos.terminal.sell', 'pos.sessions.manage',
        ],
        'warehouses' => [
            'inventory.warehouses.manage', 'inventory.locations.manage',
            'inventory.products.view', 'inventory.stocks.view', 'inventory.movements.view',
            'inventory.transfers.manage', 'inventory.kits.manage', 'inventory.adjustments.manage',
            'inventory.counts.view', 'inventory.counts.manage', 'inventory.reports.view',
            'purchase.orders.view', 'purchase.orders.manage',
            'purchase.receipts.view', 'purchase.receipts.manage',
            'purchase.bills.view', 'purchase.bills.manage',
            'purchase.returns.view', 'purchase.returns.manage',
            // F18: the requisition's receiving warehouse, picked by someone who may never see a GRN.
            'purchase.requisitions.view', 'purchase.requisitions.manage',
            'sales.orders.view', 'sales.orders.manage',
            'sales.deliveries.view', 'sales.deliveries.manage',
            'pos.terminal.sell', 'pos.sessions.manage',
        ],
        'categories' => [
            'inventory.categories.manage', 'inventory.products.view', 'inventory.products.create',
            'inventory.products.update', 'inventory.reports.view',
        ],
        'brands' => [
            'inventory.brands.manage', 'inventory.products.view', 'inventory.products.create',
            'inventory.products.update', 'inventory.reports.view',
        ],
        'uoms' => [
            'inventory.uoms.manage', 'inventory.products.view', 'inventory.products.create',
            'inventory.products.update',
            // F18: a requisition line states its unit; without this the form can ask for a product but
            // cannot say "40 bags" in a unit it can name.
            'purchase.requisitions.view', 'purchase.requisitions.manage',
        ],
        'units' => [
            'inventory.uoms.manage', 'inventory.products.view', 'inventory.products.create',
            'inventory.products.update',
        ],
    ];

    public function __invoke(Request $request, string $type): JsonResponse
    {
        if ($denied = OptionAccess::gate($request, $type, self::PERMISSIONS)) {
            return $denied;
        }

        $search = $request->query('search');
        $limit = min(100, (int) $request->query('limit', 25));

        // No `default` arm — see OptionAccess: a mapped type with no handler must fail loudly.
        $data = match ($type) {
            'products' => app(ProductService::class)->options($search, $limit),
            'categories' => app(CategoryService::class)->options($search, $limit),
            'brands' => app(BrandService::class)->options($search, $limit),
            'uoms', 'units' => app(UomService::class)->options($search, $limit),
            'warehouses' => app(WarehouseService::class)->options($search, $limit),
        };

        return response()->json(['data' => $data]);
    }
}
