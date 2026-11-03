<?php

namespace Modules\SALES\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\CORE\Support\DataTableQuery;
use Modules\CORE\Support\PaginatedResource;
use Modules\SALES\Models\Invoice;
use Modules\SALES\Requests\StoreInvoiceRequest;
use Modules\SALES\Services\InvoiceService;

class InvoiceController extends Controller
{
    public function __construct(private readonly InvoiceService $svc) {}

    public function index(Request $r): Response
    {
        $dt = DataTableQuery::fromRequest($r, ['invoice_no', 'invoice_date', 'created_at'], ['status', 'payment_status']);
        return Inertia::render('SALES::Invoices/Index', [
            'invoices' => PaginatedResource::from($this->svc->paginate($dt)->through(fn (Invoice $inv) => [
                'id' => $inv->id, 'invoice_no' => $inv->invoice_no, 'customer' => $inv->customer ? ['name' => $inv->customer->name] : null,
                'invoice_date' => $inv->invoice_date->format('Y-m-d'), 'due_date' => $inv->due_date?->format('Y-m-d'),
                'total_amount' => $inv->total_amount, 'paid_amount' => $inv->paid_amount, 'status' => $inv->status, 'payment_status' => $inv->payment_status, 'items_count' => $inv->items_count, 'created_at' => $inv->created_at?->toIso8601String(),
            ])),
        ]);
    }

    public function create(Request $r): Response
    {
        $order = $r->query('order_id') ? \Modules\SALES\Models\Order::with(['customer', 'warehouse', 'items.product'])->find($r->query('order_id')) : null;
        return Inertia::render('SALES::Invoices/Create', ['order' => $order]);
    }

    public function store(StoreInvoiceRequest $r): RedirectResponse { $inv = $this->svc->create($r->validated()); return redirect()->route('sales.invoices.show', $inv->id)->with('success', "Invoice {$inv->invoice_no} created."); }
    public function show(Invoice $invoice): Response { $invoice->load(['customer', 'warehouse', 'order', 'items.product', 'payments', 'journal']); return Inertia::render('SALES::Invoices/Show', ['invoice' => $invoice]); }
    public function edit(Invoice $invoice): Response { $invoice->load('items'); return Inertia::render('SALES::Invoices/Edit', ['invoice' => $invoice]); }
    public function update(StoreInvoiceRequest $r, Invoice $invoice): RedirectResponse { try { $this->svc->update($invoice, $r->validated()); return redirect()->route('sales.invoices.show', $invoice->id)->with('success', 'Invoice updated.'); } catch (\DomainException $e) { return back()->with('error', $e->getMessage()); } }
    public function destroy(Invoice $invoice): RedirectResponse { try { $this->svc->delete($invoice); return redirect()->route('sales.invoices.index')->with('success', 'Invoice deleted.'); } catch (\DomainException $e) { return back()->with('error', $e->getMessage()); } }
    public function post(Invoice $invoice): RedirectResponse { try { $this->svc->post($invoice); return back()->with('success', "Invoice {$invoice->invoice_no} posted — stock deducted & ledger updated."); } catch (\DomainException $e) { return back()->with('error', $e->getMessage()); } }

    public function pay(Request $r, Invoice $invoice): RedirectResponse
    {
        $r->validate(['amount' => 'required|numeric|min:0.01', 'payment_date' => 'required|date', 'method' => 'required|in:cash,bank,bkash,card', 'bank_id' => 'nullable|exists:acc_banks,id', 'reference_no' => 'nullable|string|max:100']);
        try { $this->svc->recordPayment($invoice, $r->only(['amount', 'payment_date', 'method', 'bank_id', 'reference_no'])); return back()->with('success', 'Payment recorded.'); }
        catch (\DomainException $e) { return back()->with('error', $e->getMessage()); }
    }

    public function mushak(Invoice $invoice)
    {
        $invoice->load(['customer', 'warehouse', 'items.product']);
        if ($invoice->status === 'draft') abort(403, 'Only posted invoices can generate Mushak 6.3');
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('sales::mushak63', ['invoice' => $invoice]);
        $pdf->setPaper('A4', 'portrait');
        return $pdf->download('Mushak-6.3-'.$invoice->invoice_no.'.pdf');
    }
}
