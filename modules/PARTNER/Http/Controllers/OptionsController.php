<?php

namespace Modules\PARTNER\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\CORE\Support\OptionAccess;
use Modules\PARTNER\Services\PartnerGroupService;
use Modules\PARTNER\Services\PartnerService;
use Modules\PARTNER\Services\PriceListService;

/**
 * Typeahead source for the PARTNER pickers.
 *
 * This endpoint had no authorisation beyond `['web', 'auth']`, so any authenticated user could
 * page through the entire customer and vendor list — `PartnerRepository::options()` selects
 * `legal_name` and `phone` and emits the phone as the option description — along with every group
 * and price list. See `OptionAccess` for why the gate lives here rather than on the route.
 *
 * The lists are any-of on purpose: the vendor picker is consumed by 7 PURCHASE pages and by
 * `INVENTORY/ProductForm.vue`, and the customer picker by 4 SALES pages, so gating either on the
 * PARTNER permission alone would break those forms for any role not also granted PARTNER.
 */
class OptionsController extends Controller
{
    /** @var array<string, list<string>> type => permissions, any of which is sufficient */
    private const PERMISSIONS = [
        'vendors' => [
            // F18: an RFQ is sent to named vendors and a quote is entered against one.
            'purchase.rfq.view', 'purchase.rfq.manage', 'purchase.rfq.award',
            'partner.vendors.view', 'partner.vendors.create', 'partner.vendors.update', 'partner.exports',
            'purchase.orders.view', 'purchase.orders.manage', 'purchase.orders.confirm', 'purchase.orders.approve',
            'purchase.receipts.view', 'purchase.receipts.manage', 'purchase.receipts.post',
            'purchase.bills.view', 'purchase.bills.manage', 'purchase.bills.post',
            'purchase.returns.view', 'purchase.returns.manage',
            'purchase.landed.view', 'purchase.landed.manage', 'purchase.payments.manage',
            'inventory.products.create', 'inventory.products.update',
        ],
        'customers' => [
            'partner.customers.view', 'partner.customers.create', 'partner.customers.update', 'partner.exports',
            'sales.invoices.view', 'sales.invoices.manage', 'sales.invoices.post',
            'sales.orders.view', 'sales.orders.manage', 'sales.orders.confirm',
            'sales.quotations.view', 'sales.quotations.manage',
            'sales.deliveries.view', 'sales.deliveries.manage',
            'sales.returns.view', 'sales.returns.manage', 'sales.returns.approve',
            'pos.terminal.sell', 'pos.sessions.manage',
            'accounts.receivables.view',
        ],
        // Both spellings were served before the gate existed; kept so no caller 404s. Anyone who
        // may see either side of the partner book may see the unfiltered list.
        'all-partners' => [
            'partner.vendors.view', 'partner.customers.view', 'partner.exports',
            'purchase.orders.view', 'purchase.orders.manage', 'purchase.bills.view', 'purchase.bills.manage',
            'purchase.receipts.view', 'purchase.receipts.manage', 'purchase.returns.view', 'purchase.returns.manage',
            'sales.invoices.view', 'sales.invoices.manage', 'sales.orders.view', 'sales.orders.manage',
            'sales.quotations.view', 'sales.quotations.manage', 'sales.deliveries.manage',
            'pos.terminal.sell', 'accounts.receivables.view', 'accounts.payables.view',
        ],
        'partners' => [
            'partner.vendors.view', 'partner.customers.view', 'partner.exports',
            'purchase.orders.view', 'purchase.orders.manage', 'purchase.bills.view', 'purchase.bills.manage',
            'purchase.receipts.view', 'purchase.receipts.manage', 'purchase.returns.view', 'purchase.returns.manage',
            'sales.invoices.view', 'sales.invoices.manage', 'sales.orders.view', 'sales.orders.manage',
            'sales.quotations.view', 'sales.quotations.manage', 'sales.deliveries.manage',
            'pos.terminal.sell', 'accounts.receivables.view', 'accounts.payables.view',
        ],
        'partner-groups' => ['partner.groups.manage', 'partner.vendors.view', 'partner.customers.view'],
        'groups' => ['partner.groups.manage', 'partner.vendors.view', 'partner.customers.view'],
        'vendor-groups' => ['partner.groups.manage', 'partner.vendors.view'],
        'customer-groups' => ['partner.groups.manage', 'partner.customers.view'],
        'price-lists' => [
            'partner.pricelists.manage',
            'sales.quotations.view', 'sales.quotations.manage',
            'sales.orders.view', 'sales.orders.manage',
            'sales.invoices.view', 'sales.invoices.manage',
        ],
        'pricelists' => [
            'partner.pricelists.manage',
            'sales.quotations.view', 'sales.quotations.manage',
            'sales.orders.view', 'sales.orders.manage',
            'sales.invoices.view', 'sales.invoices.manage',
        ],
    ];

    public function __invoke(Request $request, string $type): JsonResponse
    {
        if ($denied = OptionAccess::gate($request, $type, self::PERMISSIONS)) {
            return $denied;
        }

        $search = $request->query('search');
        $limit = min(100, (int) $request->query('limit', 25));

        // No `default` arm: a type present in PERMISSIONS but absent here is a mapping bug and must
        // fail the test suite as an UnhandledMatchError rather than quietly returning an empty list.
        $data = match ($type) {
            'vendors' => app(PartnerService::class)->options($search, $limit, 'vendor'),
            'customers' => app(PartnerService::class)->options($search, $limit, 'customer'),
            'all-partners', 'partners' => app(PartnerService::class)->options($search, $limit, null),
            'partner-groups' => app(PartnerGroupService::class)->options($search, $limit),
            'groups' => app(PartnerGroupService::class)->options($search, $limit),
            'vendor-groups' => app(PartnerGroupService::class)->options($search, $limit, 'vendor'),
            'customer-groups' => app(PartnerGroupService::class)->options($search, $limit, 'customer'),
            'price-lists', 'pricelists' => app(PriceListService::class)->options($search, $limit),
        };

        return response()->json(['data' => $data]);
    }
}
