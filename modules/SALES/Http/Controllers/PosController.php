<?php

namespace Modules\SALES\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\CORE\Support\DataTableQuery;
use Modules\CORE\Support\PaginatedResource;
use Modules\SALES\DTO\InvoiceLineData;
use Modules\SALES\DTO\PosSaleData;
use Modules\SALES\Models\PosSale;
use Modules\SALES\Models\PosSession;
use Modules\SALES\Requests\PosSaleRequest;
use Modules\SALES\Services\PosService;

class PosController extends Controller
{
    public function __construct(private readonly PosService $svc) {}

    public function terminal(Request $r): Response
    {
        $warehouses = \Modules\INVENTORY\Models\Warehouse::active()->orderBy('name')->get(['id', 'name', 'code']);
        $customers = \Modules\PARTNER\Models\Partner::where('type', 'customer')->orWhere('is_customer', true)->orderBy('name')->limit(50)->get(['id', 'name', 'partner_code']);
        $banks = \Modules\ACCOUNTS\Models\Bank::active()->orderBy('name')->get(['id', 'name']);
        $openSession = PosSession::where('status', 'open')->where('opened_by', $r->user()->id)->latest()->first();
        return Inertia::render('SALES::Pos/Terminal', [
            'warehouses' => $warehouses->map(fn($w) => ['value' => $w->id, 'label' => "{$w->name} ({$w->code})"]),
            'customers' => $customers->map(fn($c) => ['value' => $c->id, 'label' => "{$c->name} ({$c->partner_code})"]),
            'banks' => $banks->map(fn($b) => ['value' => $b->id, 'label' => $b->name]),
            'openSession' => $openSession,
        ]);
    }

    public function sessions(Request $r): Response
    {
        $dt = DataTableQuery::fromRequest($r, ['session_no', 'created_at'], []);
        return Inertia::render('SALES::Pos/Sessions', [
            'sessions' => PaginatedResource::from($this->svc->sessionsPaginate($dt)->through(fn (PosSession $s) => [
                'id' => $s->id, 'session_no' => $s->session_no, 'warehouse' => $s->warehouse ? ['name' => $s->warehouse->name] : null,
                'opening_cash' => $s->opening_cash, 'total_sales' => $s->total_sales, 'transactions_count' => $s->transactions_count,
                'status' => $s->status, 'opened_at' => $s->opened_at?->toIso8601String(), 'closed_at' => $s->closed_at?->toIso8601String(),
            ])),
        ]);
    }

    public function openSession(Request $r): RedirectResponse
    {
        $r->validate(['warehouse_id' => 'required|exists:inventory_warehouses,id', 'opening_cash' => 'required|numeric|min:0', 'notes' => 'nullable|string|max:1000']);
        try { $s = $this->svc->openSession($r->only(['warehouse_id', 'opening_cash', 'notes'])); return back()->with('success', "Session {$s->session_no} opened."); }
        catch (\DomainException $e) { return back()->with('error', $e->getMessage()); }
    }

    public function closeSession(Request $r, PosSession $session): RedirectResponse
    {
        $r->validate(['closing_cash' => 'required|numeric|min:0', 'notes' => 'nullable|string|max:1000']);
        try { $this->svc->closeSession($session, $r->only(['closing_cash', 'notes'])); return back()->with('success', "Session {$session->session_no} closed."); }
        catch (\DomainException $e) { return back()->with('error', $e->getMessage()); }
    }

    public function history(Request $r): Response
    {
        $dt = DataTableQuery::fromRequest($r, ['pos_no', 'sale_date'], []);
        return Inertia::render('SALES::Pos/History', [
            'sales' => PaginatedResource::from($this->svc->salesPaginate($dt)->through(fn (PosSale $s) => [
                'id' => $s->id, 'pos_no' => $s->pos_no, 'customer_name' => $s->customer_name, 'sale_date' => $s->sale_date->format('Y-m-d'),
                'total_amount' => $s->total_amount, 'payment_method' => $s->payment_method, 'status' => $s->status, 'created_at' => $s->created_at?->toIso8601String(),
            ])),
        ]);
    }

    public function sell(PosSaleRequest $r): RedirectResponse
    {
        try {
            $lines = array_map(fn($it) => new InvoiceLineData(productId: (int) $it['product_id'], qty: (float) $it['qty'], unitPrice: (float) $it['unit_price'], variantId: $it['variant_id'] ?? null, discountPct: (float) ($it['discount_pct'] ?? 0), vatRate: (float) ($it['vat_rate'] ?? 0)), $r->input('items'));
            $dto = new PosSaleData(warehouseId: (int) $r->input('warehouse_id'), lines: $lines, paymentMethod: $r->input('payment_method'), customerId: $r->input('customer_id'), customerName: $r->input('customer_name', 'Walk-in Customer'), bankId: $r->input('bank_id'), cashAmount: (float) ($r->input('cash_amount', 0)), bankAmount: (float) ($r->input('bank_amount', 0)), sessionId: $r->input('session_id'));
            $sale = $this->svc->sell($dto);
            return redirect()->route('sales.pos.receipt', $sale->id)->with('success', "Sale {$sale->pos_no} completed — stock & ledger posted.");
        } catch (\DomainException | \InvalidArgumentException $e) { return back()->with('error', $e->getMessage())->withInput(); }
    }

    public function receipt(PosSale $sale): Response { $sale->load(['items.product', 'warehouse', 'customer', 'invoice']); return Inertia::render('SALES::Pos/Receipt', ['sale' => $sale]); }
    public function show(PosSale $sale): Response { $sale->load(['items.product', 'warehouse', 'customer', 'invoice']); return Inertia::render('SALES::Pos/Show', ['sale' => $sale]); }
    public function void(PosSale $sale): RedirectResponse { try { $this->svc->void($sale); return back()->with('success', "Sale {$sale->pos_no} voided — stock & ledger reversed."); } catch (\DomainException $e) { return back()->with('error', $e->getMessage()); } }
}
