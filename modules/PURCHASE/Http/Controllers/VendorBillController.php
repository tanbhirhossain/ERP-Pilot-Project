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
use Modules\PURCHASE\Models\VendorBill;
use Modules\PURCHASE\Requests\VendorBillRequest;
use Modules\PURCHASE\Services\VendorBillService;

class VendorBillController extends Controller
{
    public function __construct(private readonly VendorBillService $svc) {}

    public function index(Request $r): Response
    {
        $dt = DataTableQuery::fromRequest($r, ['bill_no', 'bill_date', 'created_at'], ['status', 'payment_status']);

        return Inertia::render('PURCHASE::Bills/Index', [
            'bills' => PaginatedResource::from($this->svc->paginate($dt)->through(fn (VendorBill $b) => [
                'id' => $b->id, 'bill_no' => $b->bill_no, 'vendor' => $b->vendor ? ['name' => $b->vendor->name] : null, 'bill_date' => $b->bill_date->format('Y-m-d'), 'total_amount' => $b->total_amount, 'paid_amount' => $b->paid_amount, 'status' => $b->status, 'payment_status' => $b->payment_status, 'created_at' => $b->created_at?->toIso8601String(),
            ])),
        ]);
    }

    public function create(Request $r): Response
    {
        $receipt = $r->query('receipt_id') ? GoodsReceipt::with(['vendor', 'warehouse', 'items.product'])->find($r->query('receipt_id')) : null;
        $order = $r->query('order_id') ? PurchaseOrder::with(['vendor', 'warehouse', 'items.product'])->find($r->query('order_id')) : null;

        return Inertia::render('PURCHASE::Bills/Create', ['receipt' => $receipt, 'order' => $order]);
    }

    public function store(VendorBillRequest $r): RedirectResponse
    {
        $b = $this->svc->create($r->validated());

        return redirect()->route('purchase.bills.show', $b->id)->with('success', "Bill {$b->bill_no} created.");
    }

    public function show(VendorBill $bill): Response
    {
        $bill->load(['vendor', 'warehouse', 'order', 'receipt', 'items.product', 'payments', 'journal']);

        return Inertia::render('PURCHASE::Bills/Show', ['bill' => $bill]);
    }

    public function edit(VendorBill $bill): Response
    {
        $bill->load('items');

        return Inertia::render('PURCHASE::Bills/Edit', ['bill' => $bill]);
    }

    public function update(VendorBillRequest $r, VendorBill $bill): RedirectResponse
    {
        try {
            $this->svc->update($bill, $r->validated());

            return redirect()->route('purchase.bills.show', $bill->id)->with('success', 'Bill updated.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function destroy(VendorBill $bill): RedirectResponse
    {
        try {
            $this->svc->delete($bill);

            return redirect()->route('purchase.bills.index')->with('success', 'Bill deleted.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function post(VendorBill $bill): RedirectResponse
    {
        try {
            $this->svc->post($bill);

            return back()->with('success', "Bill {$bill->bill_no} posted — AP ledger updated.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function void(VendorBill $bill): RedirectResponse
    {
        try {
            $this->svc->void($bill);

            return back()->with('success', "Bill {$bill->bill_no} voided — journal reversed.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function pay(Request $r, VendorBill $bill): RedirectResponse
    {
        $r->validate(['amount' => 'required|numeric|min:0.01', 'payment_date' => 'required|date', 'method' => 'required|in:cash,bank,bkash,card', 'bank_id' => 'nullable|exists:acc_banks,id', 'reference_no' => 'nullable|string|max:100']);
        try {
            $this->svc->recordPayment($bill, $r->only(['amount', 'payment_date', 'method', 'bank_id', 'reference_no']));

            return back()->with('success', 'Payment recorded.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
