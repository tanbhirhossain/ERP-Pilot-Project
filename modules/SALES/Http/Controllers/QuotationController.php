<?php

namespace Modules\SALES\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\CORE\Support\DataTableQuery;
use Modules\CORE\Support\PaginatedResource;
use Modules\SALES\Interfaces\OrderRepositoryInterface;
use Modules\SALES\Models\Quotation;
use Modules\SALES\Requests\StoreQuotationRequest;
use Modules\SALES\Services\QuotationService;

class QuotationController extends Controller
{
    public function __construct(private readonly QuotationService $svc) {}

    public function index(Request $r): Response
    {
        $dt = DataTableQuery::fromRequest($r, ['quotation_no', 'quotation_date', 'created_at'], ['status', 'customer_id']);

        return Inertia::render('SALES::Quotations/Index', [
            'quotations' => PaginatedResource::from($this->svc->paginate($dt)->through(fn (Quotation $q) => [
                'id' => $q->id, 'quotation_no' => $q->quotation_no, 'customer' => $q->customer ? ['name' => $q->customer->name] : null,
                'quotation_date' => $q->quotation_date->format('Y-m-d'), 'expiry_date' => $q->expiry_date?->format('Y-m-d'),
                'total_amount' => $q->total_amount, 'status' => $q->status, 'items_count' => $q->items_count, 'created_at' => $q->created_at?->toIso8601String(),
            ])),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('SALES::Quotations/Create');
    }

    public function store(StoreQuotationRequest $r): RedirectResponse
    {
        $q = $this->svc->create($r->validated());

        return redirect()->route('sales.quotations.show', $q->id)->with('success', "Quotation {$q->quotation_no} created.");
    }

    public function show(Quotation $quotation): Response
    {
        $quotation->load(['customer', 'items.product']);

        return Inertia::render('SALES::Quotations/Show', ['quotation' => $quotation]);
    }

    public function edit(Quotation $quotation): Response
    {
        $quotation->load('items');

        return Inertia::render('SALES::Quotations/Edit', ['quotation' => $quotation]);
    }

    public function update(StoreQuotationRequest $r, Quotation $quotation): RedirectResponse
    {
        // QuotationRepository::update() throws DomainException('Only draft quotations can be
        // edited.'); without this catch that reached the user as a 500 with a stack trace, while
        // destroy() in this same class already caught it.
        try {
            $this->svc->update($quotation, $r->validated());

            return redirect()->route('sales.quotations.show', $quotation->id)->with('success', 'Quotation updated.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function destroy(Quotation $quotation): RedirectResponse
    {
        try {
            $this->svc->delete($quotation);

            return redirect()->route('sales.quotations.index')->with('success', 'Quotation deleted.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function send(Quotation $quotation): RedirectResponse
    {
        try {
            $this->svc->send($quotation);

            return back()->with('success', 'Quotation sent.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function accept(Quotation $quotation): RedirectResponse
    {
        try {
            $this->svc->accept($quotation);

            return back()->with('success', 'Quotation accepted.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function convert(Request $r, Quotation $quotation): RedirectResponse
    {
        $r->validate(['warehouse_id' => 'required|exists:inventory_warehouses,id']);
        try {
            $order = $this->svc->convertToOrder($quotation, (int) $r->input('warehouse_id'), app(OrderRepositoryInterface::class));

            return redirect()->route('sales.orders.show', $order->id)->with('success', "Converted to Order {$order->order_no}.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
