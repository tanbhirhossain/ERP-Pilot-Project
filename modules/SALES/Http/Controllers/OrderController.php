<?php

namespace Modules\SALES\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\CORE\Support\DataTableQuery;
use Modules\CORE\Support\PaginatedResource;
use Modules\SALES\Models\Order;
use Modules\SALES\Requests\StoreOrderRequest;
use Modules\SALES\Services\OrderService;

class OrderController extends Controller
{
    public function __construct(private readonly OrderService $svc) {}

    public function index(Request $r): Response
    {
        $dt = DataTableQuery::fromRequest($r, ['order_no', 'order_date', 'created_at'], ['status', 'payment_status']);
        return Inertia::render('SALES::Orders/Index', [
            'orders' => PaginatedResource::from($this->svc->paginate($dt)->through(fn (Order $o) => [
                'id' => $o->id, 'order_no' => $o->order_no, 'customer' => $o->customer ? ['name' => $o->customer->name] : null,
                'warehouse' => $o->warehouse ? ['name' => $o->warehouse->name] : null,
                'order_date' => $o->order_date->format('Y-m-d'), 'total_amount' => $o->total_amount,
                'status' => $o->status, 'payment_status' => $o->payment_status, 'items_count' => $o->items_count, 'created_at' => $o->created_at?->toIso8601String(),
            ])),
        ]);
    }

    public function create(): Response { return Inertia::render('SALES::Orders/Create'); }
    public function store(StoreOrderRequest $r): RedirectResponse { $o = $this->svc->create($r->validated()); return redirect()->route('sales.orders.show', $o->id)->with('success', "Order {$o->order_no} created."); }
    public function show(Order $order): Response { $order->load(['customer', 'warehouse', 'quotation', 'items.product', 'invoices']); return Inertia::render('SALES::Orders/Show', ['order' => $order]); }
    public function edit(Order $order): Response { $order->load('items'); return Inertia::render('SALES::Orders/Edit', ['order' => $order]); }
    public function update(StoreOrderRequest $r, Order $order): RedirectResponse { try { $this->svc->update($order, $r->validated()); return redirect()->route('sales.orders.show', $order->id)->with('success', 'Order updated.'); } catch (\DomainException $e) { return back()->with('error', $e->getMessage()); } }
    public function destroy(Order $order): RedirectResponse { try { $this->svc->delete($order); return redirect()->route('sales.orders.index')->with('success', 'Order deleted.'); } catch (\DomainException $e) { return back()->with('error', $e->getMessage()); } }
    public function confirm(Order $order): RedirectResponse { try { $this->svc->confirm($order); return back()->with('success', "Order {$order->order_no} confirmed — stock reserved."); } catch (\DomainException $e) { return back()->with('error', $e->getMessage()); } }
    public function cancel(Order $order): RedirectResponse { try { $this->svc->cancel($order); return back()->with('success', 'Order cancelled.'); } catch (\DomainException $e) { return back()->with('error', $e->getMessage()); } }
    public function createInvoice(Order $order): RedirectResponse
    {
        $order->load(['items', 'customer', 'warehouse']);
        if (! $order->isInvoicable()) return back()->with('error', 'Order must be confirmed to invoice.');
        return redirect()->route('sales.invoices.create', ['order_id' => $order->id]);
    }
}
