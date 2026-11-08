<?php

namespace Modules\PURCHASE\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\CORE\Support\DataTableQuery;
use Modules\CORE\Support\PaginatedResource;
use Modules\PURCHASE\Models\PurchaseOrder;
use Modules\PURCHASE\Requests\PurchaseOrderRequest;
use Modules\PURCHASE\Services\PurchaseOrderService;

class PurchaseOrderController extends Controller
{
    public function __construct(private readonly PurchaseOrderService $svc) {}

    public function index(Request $r): Response
    {
        $dt = DataTableQuery::fromRequest($r, ['order_no', 'order_date', 'created_at'], ['status']);

        return Inertia::render('PURCHASE::Orders/Index', [
            'orders' => PaginatedResource::from($this->svc->paginate($dt)->through(fn (PurchaseOrder $o) => [
                'id' => $o->id, 'order_no' => $o->order_no, 'vendor' => $o->vendor ? ['name' => $o->vendor->name] : null,
                'warehouse' => $o->warehouse ? ['name' => $o->warehouse->name] : null,
                'order_date' => $o->order_date->format('Y-m-d'), 'total_amount' => $o->total_amount, 'status' => $o->status, 'created_at' => $o->created_at?->toIso8601String(),
            ])),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('PURCHASE::Orders/Create');
    }

    public function store(PurchaseOrderRequest $r): RedirectResponse
    {
        $o = $this->svc->create($r->validated());

        return redirect()->route('purchase.orders.show', $o->id)->with('success', "PO {$o->order_no} created.");
    }

    public function show(PurchaseOrder $order): Response
    {
        $order->load(['vendor', 'warehouse', 'items.product', 'receipts']);

        return Inertia::render('PURCHASE::Orders/Show', ['order' => $order]);
    }

    public function edit(PurchaseOrder $order): Response
    {
        $order->load('items');

        return Inertia::render('PURCHASE::Orders/Edit', ['order' => $order]);
    }

    public function update(PurchaseOrderRequest $r, PurchaseOrder $order): RedirectResponse
    {
        try {
            $this->svc->update($order, $r->validated());

            return redirect()->route('purchase.orders.show', $order->id)->with('success', 'PO updated.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function destroy(PurchaseOrder $order): RedirectResponse
    {
        try {
            $this->svc->delete($order);

            return redirect()->route('purchase.orders.index')->with('success', 'PO deleted.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function confirm(PurchaseOrder $order): RedirectResponse
    {
        try {
            $this->svc->confirm($order);

            return back()->with('success', "PO {$order->order_no} confirmed.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function approve(PurchaseOrder $order): RedirectResponse
    {
        try {
            $this->svc->approve($order);

            return back()->with('success', "PO {$order->order_no} approved (manager).");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function send(PurchaseOrder $order): RedirectResponse
    {
        try {
            $this->svc->send($order);

            return back()->with('success', "PO {$order->order_no} sent to vendor.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function cancel(PurchaseOrder $order): RedirectResponse
    {
        try {
            $this->svc->cancel($order);

            return back()->with('success', 'PO cancelled.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
