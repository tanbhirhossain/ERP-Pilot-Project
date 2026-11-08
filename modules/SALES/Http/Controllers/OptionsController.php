<?php

namespace Modules\SALES\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\CORE\Support\OptionAccess;
use Modules\SALES\Models\Invoice;
use Modules\SALES\Models\Order;
use Modules\SALES\Models\Quotation;

/**
 * Typeahead source for the SALES document pickers.
 *
 * This endpoint had no authorisation beyond `['web', 'auth']`, so any authenticated user could
 * enumerate every quotation, order and invoice number in the business — a running count of sales
 * activity, and the reference numbers needed to guess a document URL.
 *
 * Each type is gated by the permission that governs reading that document, plus the permissions
 * that imply needing to pick one: `orders` is consumed by `Deliveries/Create.vue` and `invoices` by
 * `Returns/Create.vue`, so a delivery or return operator needs the picker without necessarily
 * holding the sales-order grant.
 */
class OptionsController extends Controller
{
    /** @var array<string, list<string>> type => permissions, any of which is sufficient */
    private const PERMISSIONS = [
        'quotations' => [
            'sales.quotations.view', 'sales.quotations.manage',
            'sales.orders.view', 'sales.orders.manage',
        ],
        'orders' => [
            'sales.orders.view', 'sales.orders.manage', 'sales.orders.confirm',
            'sales.invoices.view', 'sales.invoices.manage',
            'sales.deliveries.view', 'sales.deliveries.manage',
        ],
        'invoices' => [
            'sales.invoices.view', 'sales.invoices.manage', 'sales.invoices.post',
            'sales.returns.view', 'sales.returns.manage', 'sales.returns.approve',
            'sales.deliveries.view', 'sales.deliveries.manage',
            'accounts.receivables.view',
        ],
    ];

    public function __invoke(Request $r, string $type): JsonResponse
    {
        if ($denied = OptionAccess::gate($r, $type, self::PERMISSIONS)) {
            return $denied;
        }

        $search = $r->query('search');
        $limit = min((int) $r->query('limit', 50), 100);
        // LIKE wildcards escaped: a search for "%" used to match every document in the table.
        $needle = '%'.addcslashes((string) $search, '%_\\').'%';

        // No `default` arm — see OptionAccess: a mapped type with no handler must fail loudly.
        $data = match ($type) {
            'quotations' => Quotation::when($search, fn ($q) => $q->where('quotation_no', 'like', $needle))->orderByDesc('id')->limit($limit)->get(['id', 'quotation_no as label'])->map(fn ($o) => ['value' => $o->id, 'label' => $o->label]),
            'orders' => Order::when($search, fn ($q) => $q->where('order_no', 'like', $needle))->orderByDesc('id')->limit($limit)->get(['id', 'order_no as label'])->map(fn ($o) => ['value' => $o->id, 'label' => $o->label]),
            'invoices' => Invoice::when($search, fn ($q) => $q->where('invoice_no', 'like', $needle))->orderByDesc('id')->limit($limit)->get(['id', 'invoice_no as label'])->map(fn ($o) => ['value' => $o->id, 'label' => $o->label]),
        };

        return response()->json(['data' => $data]);
    }
}
