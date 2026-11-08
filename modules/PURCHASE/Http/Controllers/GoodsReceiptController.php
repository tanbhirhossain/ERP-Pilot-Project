<?php

namespace Modules\PURCHASE\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\CORE\Support\DataTableQuery;
use Modules\CORE\Support\PaginatedResource;
use Modules\PURCHASE\Models\GoodsReceipt;
use Modules\PURCHASE\Models\PurchaseOrder;
use Modules\PURCHASE\Requests\GoodsReceiptRequest;
use Modules\PURCHASE\Services\GoodsReceiptService;

class GoodsReceiptController extends Controller
{
    public function __construct(private readonly GoodsReceiptService $svc) {}

    public function index(Request $r): Response
    {
        $dt = DataTableQuery::fromRequest($r, ['receipt_no', 'receipt_date', 'created_at'], ['status']);

        return Inertia::render('PURCHASE::Receipts/Index', [
            'receipts' => PaginatedResource::from($this->svc->paginate($dt)->through(fn (GoodsReceipt $gr) => [
                'id' => $gr->id, 'receipt_no' => $gr->receipt_no, 'vendor' => $gr->vendor ? ['name' => $gr->vendor->name] : null, 'receipt_date' => $gr->receipt_date->format('Y-m-d'), 'total_amount' => $gr->total_amount, 'status' => $gr->status, 'created_at' => $gr->created_at?->toIso8601String(),
            ])),
        ]);
    }

    public function create(Request $r): Response
    {
        $order = $r->query('order_id') ? PurchaseOrder::with(['vendor', 'warehouse', 'items.product'])->find($r->query('order_id')) : null;

        return Inertia::render('PURCHASE::Receipts/Create', ['order' => $order]);
    }

    public function store(GoodsReceiptRequest $r): RedirectResponse
    {
        $gr = $this->svc->create($r->validated());

        return redirect()->route('purchase.receipts.show', $gr->id)->with('success', "GRN {$gr->receipt_no} created.");
    }

    public function show(GoodsReceipt $receipt): Response
    {
        $receipt->load(['vendor', 'warehouse', 'order', 'items.product']);

        return Inertia::render('PURCHASE::Receipts/Show', ['receipt' => $receipt]);
    }

    public function edit(GoodsReceipt $receipt): Response
    {
        $receipt->load('items');

        return Inertia::render('PURCHASE::Receipts/Edit', ['receipt' => $receipt]);
    }

    public function update(GoodsReceiptRequest $r, GoodsReceipt $receipt): RedirectResponse
    {
        try {
            $this->svc->update($receipt, $r->validated());

            return redirect()->route('purchase.receipts.show', $receipt->id)->with('success', 'GRN updated.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function destroy(GoodsReceipt $receipt): RedirectResponse
    {
        try {
            $this->svc->delete($receipt);

            return redirect()->route('purchase.receipts.index')->with('success', 'GRN deleted.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function post(GoodsReceipt $receipt): RedirectResponse
    {
        try {
            $this->svc->post($receipt);

            return back()->with('success', "GRN {$receipt->receipt_no} posted — stock IN & PO updated.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function void(GoodsReceipt $receipt): RedirectResponse
    {
        try {
            $this->svc->void($receipt);

            return back()->with('success', "GRN {$receipt->receipt_no} voided — stock reversed.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
