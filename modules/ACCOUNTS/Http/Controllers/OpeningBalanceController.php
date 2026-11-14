<?php

namespace Modules\ACCOUNTS\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\ACCOUNTS\Models\Account;
use Modules\ACCOUNTS\Services\OpeningBalanceService;
use Modules\CORE\Models\OpeningBalanceBatch;
use Modules\INVENTORY\Models\Product;
use Modules\INVENTORY\Models\Warehouse;
use Modules\PARTNER\Models\Partner;

/**
 * Go-live take-on wizard (Phase 1 — F4).
 *
 * One page, four tabs (COA / AR / AP / Stock). Each tab posts its own batch
 * through OpeningBalanceService, which is idempotent by reference and
 * fiscal-gated. The wizard only offers posting accounts and active partners /
 * products / warehouses, so an operator cannot take on to a group account.
 */
class OpeningBalanceController extends Controller
{
    public function __construct(private readonly OpeningBalanceService $service) {}

    public function index(): Response
    {
        return Inertia::render('ACCOUNTS::OpeningBalances/Index', [
            'accounts' => Account::where('is_posting', true)->where('is_active', true)
                ->orderBy('code')->get(['id', 'code', 'name', 'account_type', 'nature']),
            'customers' => Partner::where('type', 'customer')->orWhere('type', 'both')
                ->where('is_active', true)->orderBy('name')->get(['id', 'partner_code', 'name']),
            'vendors' => Partner::where('type', 'vendor')->orWhere('type', 'both')
                ->where('is_active', true)->orderBy('name')->get(['id', 'partner_code', 'name']),
            'products' => Product::where('is_active', true)->orderBy('name')->get(['id', 'sku', 'name']),
            'warehouses' => Warehouse::orderBy('name')->get(['id', 'code', 'name']),
            'batches' => OpeningBalanceBatch::orderByDesc('id')->limit(20)->get(),
        ]);
    }

    public function postCoa(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'reference' => 'required|string|max:64',
            'as_of_date' => 'required|date',
            'lines' => 'required|array|min:1',
            'lines.*.account_id' => 'required|integer|exists:acc_accounts,id',
            'lines.*.debit' => 'nullable|numeric|min:0',
            'lines.*.credit' => 'nullable|numeric|min:0',
        ]);

        try {
            $this->service->postCoa($data['reference'], $data['as_of_date'], $data['lines']);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'COA opening balances posted.');
    }

    public function postAr(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'reference' => 'required|string|max:64',
            'as_of_date' => 'required|date',
            'rows' => 'required|array|min:1',
            'rows.*.partner_id' => 'required|integer|exists:partners,id',
            'rows.*.invoice_no' => 'required|string|max:64',
            'rows.*.invoice_date' => 'required|date',
            'rows.*.due_date' => 'nullable|date',
            'rows.*.amount' => 'required|numeric|min:0.01',
        ]);

        try {
            $this->service->postAr($data['reference'], $data['as_of_date'], $data['rows']);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Opening receivables posted.');
    }

    public function postAp(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'reference' => 'required|string|max:64',
            'as_of_date' => 'required|date',
            'rows' => 'required|array|min:1',
            'rows.*.partner_id' => 'required|integer|exists:partners,id',
            'rows.*.bill_no' => 'required|string|max:64',
            'rows.*.bill_date' => 'required|date',
            'rows.*.due_date' => 'nullable|date',
            'rows.*.amount' => 'required|numeric|min:0.01',
        ]);

        try {
            $this->service->postAp($data['reference'], $data['as_of_date'], $data['rows']);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Opening payables posted.');
    }

    public function postStock(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'reference' => 'required|string|max:64',
            'as_of_date' => 'required|date',
            'rows' => 'required|array|min:1',
            'rows.*.product_id' => 'required|integer|exists:inventory_products,id',
            'rows.*.warehouse_id' => 'required|integer|exists:inventory_warehouses,id',
            'rows.*.qty' => 'required|numeric|min:0.001',
            'rows.*.unit_cost' => 'nullable|numeric|min:0',
        ]);

        try {
            $this->service->postStock($data['reference'], $data['as_of_date'], $data['rows']);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Opening stock posted.');
    }
}
