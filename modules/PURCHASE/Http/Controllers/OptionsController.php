<?php

namespace Modules\PURCHASE\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\CORE\Support\OptionAccess;
use Modules\PURCHASE\Models\GoodsReceipt;
use Modules\PURCHASE\Models\PurchaseOrder;
use Modules\PURCHASE\Models\VendorBill;

/**
 * Typeahead source for the PURCHASE pickers.
 *
 * This route did not exist, so `LandedCosts/Create.vue` pointed its "GRN
 * Receipt" selector at a 404 and the field could never be filled.
 *
 * Unlike the other modules' `api/options` endpoints, this one was authorised
 * per type from the start: each type exposes a different document, so each is
 * gated by the permission that already governs reading it. It now delegates to
 * the shared `OptionAccess` gate so there is one implementation of the rule,
 * and its lists are any-of rather than a single permission — see below.
 */
class OptionsController extends Controller
{
    /**
     * @var array<string, list<string>> type => permissions, any of which is sufficient
     *
     * `receipts`/`grn` list the landed-cost grants as well, because `LandedCosts/Create.vue` is the
     * only page that consumes them: an operator granted landed cost but not receipt viewing could
     * reach the page and then get a 403 from its own GRN picker.
     */
    private const PERMISSIONS = [
        'receipts' => [
            'purchase.receipts.view', 'purchase.receipts.manage', 'purchase.receipts.post',
            'purchase.landed.view', 'purchase.landed.manage',
            'purchase.bills.view', 'purchase.bills.manage',
        ],
        'grn' => [
            'purchase.receipts.view', 'purchase.receipts.manage', 'purchase.receipts.post',
            'purchase.landed.view', 'purchase.landed.manage',
            'purchase.bills.view', 'purchase.bills.manage',
        ],
        'orders' => [
            'purchase.orders.view', 'purchase.orders.manage', 'purchase.orders.confirm', 'purchase.orders.approve',
            'purchase.receipts.view', 'purchase.receipts.manage',
        ],
        'bills' => [
            'purchase.bills.view', 'purchase.bills.manage', 'purchase.bills.post', 'purchase.bills.void',
            'purchase.payments.manage', 'purchase.landed.view', 'purchase.landed.manage',
        ],
    ];

    public function __invoke(Request $request, string $type): JsonResponse
    {
        if ($denied = OptionAccess::gate($request, $type, self::PERMISSIONS)) {
            return $denied;
        }

        $search = $request->query('search');
        $limit = max(1, min(100, (int) $request->query('limit', 25)));

        $items = match ($type) {
            'receipts', 'grn' => $this->receipts($search, $limit),
            'orders' => $this->orders($search, $limit),
            'bills' => $this->bills($search, $limit),
        };

        // `items` — the envelope SearchableSelect reads first. The component
        // also accepts `data` and a bare array, but new code should not add
        // another variant to the pile.
        return response()->json(['items' => $items->values()]);
    }

    /**
     * Posted GRNs, newest first — landed cost is allocated against a receipt.
     */
    private function receipts(?string $search, int $limit)
    {
        return GoodsReceipt::query()
            ->with('vendor:id,name')
            ->when($search, fn (Builder $q, string $term) => $this->like($q, $term, ['receipt_no']))
            ->orderByDesc('receipt_date')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'receipt_no', 'receipt_date', 'vendor_id', 'subtotal'])
            ->map(fn (GoodsReceipt $g) => [
                'value' => $g->id,
                'label' => $g->receipt_no,
                'description' => trim(
                    ($g->vendor?->name ?? '').' · '.optional($g->receipt_date)->format('d M Y')
                ) ?: null,
                'meta' => number_format((float) $g->subtotal, 2),
            ]);
    }

    private function orders(?string $search, int $limit)
    {
        return PurchaseOrder::query()
            ->with('vendor:id,name')
            ->when($search, fn (Builder $q, string $term) => $this->like($q, $term, ['order_no']))
            ->orderByDesc('order_date')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'order_no', 'order_date', 'status', 'vendor_id'])
            ->map(fn (PurchaseOrder $o) => [
                'value' => $o->id,
                'label' => $o->order_no,
                'description' => trim(
                    ($o->vendor?->name ?? '').' · '.optional($o->order_date)->format('d M Y')
                ) ?: null,
                'meta' => $o->status,
            ]);
    }

    private function bills(?string $search, int $limit)
    {
        return VendorBill::query()
            ->with('vendor:id,name')
            ->when($search, fn (Builder $q, string $term) => $this->like($q, $term, ['bill_no']))
            ->orderByDesc('bill_date')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'bill_no', 'bill_date', 'status', 'vendor_id'])
            ->map(fn (VendorBill $b) => [
                'value' => $b->id,
                'label' => $b->bill_no,
                'description' => trim(
                    ($b->vendor?->name ?? '').' · '.optional($b->bill_date)->format('d M Y')
                ) ?: null,
                'meta' => $b->status,
            ]);
    }

    /**
     * Escape LIKE wildcards so a search for "100%" is a literal, not a match-all.
     *
     * @param  list<string>  $columns
     */
    private function like(Builder $q, string $term, array $columns): void
    {
        $needle = '%'.addcslashes($term, '%_\\').'%';

        $q->where(function (Builder $group) use ($needle, $columns): void {
            foreach ($columns as $column) {
                $group->orWhere($column, 'like', $needle);
            }
        });
    }
}
