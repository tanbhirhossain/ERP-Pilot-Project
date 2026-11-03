<?php

namespace Modules\PURCHASE\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\CORE\Support\DataTableQuery;
use Modules\CORE\Support\PaginatedResource;
use Modules\PURCHASE\Models\PurchaseReturn;
use Modules\PURCHASE\Requests\PurchaseReturnRequest;
use Modules\PURCHASE\Services\PurchaseReturnService;

class PurchaseReturnController extends Controller
{
    public function __construct(private readonly PurchaseReturnService $svc) {}
    public function index(Request $r): Response
    {
        $dt = DataTableQuery::fromRequest($r, ['return_no','return_date','created_at'], ['status']);
        return Inertia::render('PURCHASE::Returns/Index', [
            'returns' => PaginatedResource::from($this->svc->paginate($dt)->through(fn (PurchaseReturn $ret) => [
                'id'=>$ret->id,'return_no'=>$ret->return_no,'vendor'=>$ret->vendor?['name'=>$ret->vendor->name]:null,'return_date'=>$ret->return_date->format('Y-m-d'),'total_amount'=>$ret->total_amount,'status'=>$ret->status,'created_at'=>$ret->created_at?->toIso8601String(),
            ])),
        ]);
    }
    public function create(Request $r): Response
    {
        $receipt = $r->query('receipt_id') ? \Modules\PURCHASE\Models\GoodsReceipt::with(['vendor','warehouse','items.product'])->find($r->query('receipt_id')) : null;
        $bill = $r->query('bill_id') ? \Modules\PURCHASE\Models\VendorBill::with(['vendor','warehouse','items.product'])->find($r->query('bill_id')) : null;
        return Inertia::render('PURCHASE::Returns/Create', ['receipt'=>$receipt,'bill'=>$bill]);
    }
    public function store(PurchaseReturnRequest $r): RedirectResponse { $ret=$this->svc->create($r->validated()); return redirect()->route('purchase.returns.show',$ret->id)->with('success',"Return {$ret->return_no} created."); }
    public function show(PurchaseReturn $return): Response { $return->load(['vendor','warehouse','order','receipt','bill','items.product','journal']); return Inertia::render('PURCHASE::Returns/Show',['return'=>$return]); }
    public function edit(PurchaseReturn $return): Response { $return->load('items'); return Inertia::render('PURCHASE::Returns/Edit',['return'=>$return]); }
    public function update(PurchaseReturnRequest $r, PurchaseReturn $return): RedirectResponse { try{ $this->svc->update($return,$r->validated()); return redirect()->route('purchase.returns.show',$return->id)->with('success','Return updated.'); }catch(\DomainException $e){ return back()->with('error',$e->getMessage()); } }
    public function destroy(PurchaseReturn $return): RedirectResponse { try{ $this->svc->delete($return); return redirect()->route('purchase.returns.index')->with('success','Return deleted.'); }catch(\DomainException $e){ return back()->with('error',$e->getMessage()); } }
    public function post(PurchaseReturn $return): RedirectResponse { try{ $this->svc->post($return); return back()->with('success',"Return {$return->return_no} posted — stock OUT & AP debit note."); }catch(\DomainException $e){ return back()->with('error',$e->getMessage()); } }
}
