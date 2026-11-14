<?php

namespace Modules\CORE\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Global record search for the command palette (Phase 2 — U9).
 *
 * Searches products, partners, sales invoices and purchase orders by number/name
 * and returns deep links. Every entity type is gated on its own view permission,
 * so a cashier never sees purchase orders and an accountant never sees products
 * they may not read. Read-only DB queries (the dashboard reporting pattern) keep
 * CORE free of module-model coupling. LIKE wildcards in the user's input are
 * escaped so `%` is a literal, not a match-all.
 */
class GlobalSearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        if (mb_strlen($q) < 2) {
            return response()->json(['results' => []]);
        }

        $user = $request->user();
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q).'%';
        $results = [];

        // Products.
        if ($user->can('inventory.products.view')) {
            $rows = DB::table('inventory_products')
                ->where('is_active', true)
                ->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('sku', 'like', $like)->orWhere('barcode', 'like', $like))
                ->orderBy('name')->limit(5)->get(['id', 'name', 'sku']);
            foreach ($rows as $r) {
                $results[] = ['type' => 'Product', 'label' => $r->name, 'sublabel' => $r->sku, 'url' => route('inventory.products.show', $r->id)];
            }
        }

        // Partners (customers and vendors).
        if ($user->can('partner.customers.view') || $user->can('partner.vendors.view')) {
            $rows = DB::table('partners')
                ->whereNull('deleted_at')->where('is_active', true)
                ->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('partner_code', 'like', $like)->orWhere('phone', 'like', $like))
                ->orderBy('name')->limit(6)->get(['id', 'name', 'partner_code', 'type']);
            foreach ($rows as $r) {
                $isCustomer = in_array($r->type, ['customer', 'both'], true);
                if ($isCustomer && $user->can('partner.customers.view')) {
                    $results[] = ['type' => 'Customer', 'label' => $r->name, 'sublabel' => $r->partner_code, 'url' => route('partner.customers.show', $r->id)];
                } elseif (! $isCustomer && $user->can('partner.vendors.view')) {
                    $results[] = ['type' => 'Vendor', 'label' => $r->name, 'sublabel' => $r->partner_code, 'url' => route('partner.vendors.show', $r->id)];
                }
            }
        }

        // Sales invoices.
        if ($user->can('sales.invoices.view')) {
            $rows = DB::table('sales_invoices')
                ->whereNull('deleted_at')
                ->where('invoice_no', 'like', $like)
                ->orderByDesc('id')->limit(5)->get(['id', 'invoice_no', 'status']);
            foreach ($rows as $r) {
                $results[] = ['type' => 'Invoice', 'label' => $r->invoice_no, 'sublabel' => ucfirst(str_replace('_', ' ', $r->status)), 'url' => route('sales.invoices.show', $r->id)];
            }
        }

        // Purchase orders.
        if ($user->can('purchase.orders.view')) {
            $rows = DB::table('purchase_orders')
                ->whereNull('deleted_at')
                ->where('order_no', 'like', $like)
                ->orderByDesc('id')->limit(5)->get(['id', 'order_no', 'status']);
            foreach ($rows as $r) {
                $results[] = ['type' => 'Purchase Order', 'label' => $r->order_no, 'sublabel' => ucfirst(str_replace('_', ' ', $r->status)), 'url' => route('purchase.orders.show', $r->id)];
            }
        }

        return response()->json(['results' => array_slice($results, 0, 20)]);
    }
}
