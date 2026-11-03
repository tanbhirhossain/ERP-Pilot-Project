<?php

namespace Modules\PURCHASE\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\PURCHASE\Models\LandedCost;
use Modules\PURCHASE\Services\LandedCostService;

class LandedCostController extends Controller
{
    public function __construct(private readonly LandedCostService $svc) {}

    public function index(Request $r): Response
    {
        $q = LandedCost::with(['receipt'])->latest()->paginate(15);
        return Inertia::render('PURCHASE::LandedCosts/Index', ['landedCosts'=>$q]);
    }

    public function create(Request $r): Response
    {
        $receipt = $r->query('receipt_id') ? \Modules\PURCHASE\Models\GoodsReceipt::with('items.product')->find($r->query('receipt_id')) : null;
        $accounts = \Modules\ACCOUNTS\Models\Account::where('account_type','expense')->orWhere('code','like','5%')->limit(100)->get(['id','code','name']);
        return Inertia::render('PURCHASE::LandedCosts/Create', ['receipt'=>$receipt, 'accounts'=>$accounts]);
    }

    public function store(Request $r): RedirectResponse
    {
        $r->validate([
            'receipt_id'=>'required|exists:purchase_receipts,id',
            'allocation_basis'=>'required|in:value,qty,weight',
            'notes'=>'nullable|string',
            'lines'=>'required|array|min:1',
            'lines.*.name'=>'required|string',
            'lines.*.amount'=>'required|numeric|min:0.01',
            'lines.*.account_id'=>'nullable|exists:acc_accounts,id',
        ]);
        $lc=$this->svc->create($r->only(['receipt_id','allocation_basis','notes','lines']));
        return redirect()->route('purchase.landed-costs.show',$lc->id)->with('success',"Landed cost {$lc->lc_no} created.");
    }

    public function show(LandedCost $landedCost): Response
    {
        $landedCost->load(['receipt.items.product','lines','allocations.product','receipt.warehouse']);
        return Inertia::render('PURCHASE::LandedCosts/Show', ['landedCost'=>$landedCost]);
    }

    public function post(LandedCost $landedCost): RedirectResponse
    {
        try { $this->svc->allocate($landedCost); return back()->with('success',"Allocated {$landedCost->lc_no} — stock & journal updated."); }
        catch (\DomainException $e) { return back()->with('error',$e->getMessage()); }
    }

    public function cancel(LandedCost $landedCost): RedirectResponse
    {
        try { $this->svc->cancel($landedCost); return back()->with('success','Cancelled.'); }
        catch (\DomainException $e) { return back()->with('error',$e->getMessage()); }
    }
}
