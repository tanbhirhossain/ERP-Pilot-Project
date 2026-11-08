<?php

namespace Modules\SALES\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\CORE\Support\DataTableQuery;
use Modules\CORE\Support\PaginatedResource;
use Modules\SALES\Models\Delivery;
use Modules\SALES\Models\Order;
use Modules\SALES\Requests\StoreDeliveryRequest;
use Modules\SALES\Services\DeliveryService;

class DeliveryController extends Controller
{
    public function __construct(private readonly DeliveryService $svc) {}

    public function index(Request $r): Response
    {
        $dt = DataTableQuery::fromRequest($r, ['delivery_no', 'challan_no', 'delivery_date'], ['status']);

        return Inertia::render('SALES::Deliveries/Index', ['deliveries' => PaginatedResource::from($this->svc->paginate($dt)->through(fn (Delivery $d) => [
            'id' => $d->id, 'delivery_no' => $d->delivery_no, 'customer' => $d->customer ? ['name' => $d->customer->name] : null, 'warehouse' => $d->warehouse ? ['name' => $d->warehouse->name] : null, 'delivery_date' => $d->delivery_date->format('Y-m-d'), 'status' => $d->status, 'items_count' => $d->items_count, 'created_at' => $d->created_at?->toIso8601String(),
        ]))]);
    }

    public function create(Request $r): Response
    {
        $order = $r->query('order_id') ? Order::with(['customer', 'warehouse', 'items.product'])->find($r->query('order_id')) : null;

        return Inertia::render('SALES::Deliveries/Create', ['order' => $order]);
    }

    public function store(StoreDeliveryRequest $r): RedirectResponse
    {
        $d = $this->svc->create($r->validated());

        return redirect()->route('sales.deliveries.show', $d->id)->with('success', "Delivery {$d->delivery_no} created.");
    }

    public function show(Delivery $delivery): Response
    {
        $delivery->load(['customer', 'warehouse', 'order', 'items.product']);

        return Inertia::render('SALES::Deliveries/Show', ['delivery' => $delivery]);
    }

    public function edit(Delivery $delivery): Response
    {
        $delivery->load('items');

        return Inertia::render('SALES::Deliveries/Edit', ['delivery' => $delivery]);
    }

    public function update(StoreDeliveryRequest $r, Delivery $delivery): RedirectResponse
    {
        return redirect()->route('sales.deliveries.show', $delivery->id)->with('success', 'Delivery updated.');
    }

    public function destroy(Delivery $delivery): RedirectResponse
    {
        try {
            $this->svc->delete($delivery);

            return redirect()->route('sales.deliveries.index')->with('success', 'Deleted.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function ship(Delivery $delivery): RedirectResponse
    {
        try {
            $this->svc->ship($delivery);

            return back()->with('success', "Delivery {$delivery->delivery_no} shipped — stock deducted.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function cancel(Delivery $delivery): RedirectResponse
    {
        try {
            $this->svc->cancel($delivery);

            return back()->with('success', 'Cancelled.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
