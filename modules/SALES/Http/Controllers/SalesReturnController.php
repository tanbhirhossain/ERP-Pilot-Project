<?php

namespace Modules\SALES\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\CORE\Support\DataTableQuery;
use Modules\CORE\Support\PaginatedResource;
use Modules\SALES\Models\SalesReturn;
use Modules\SALES\Requests\StoreSalesReturnRequest;
use Modules\SALES\Services\SalesReturnService;

class SalesReturnController extends Controller
{
    public function __construct(private readonly SalesReturnService $svc) {}
    public function index(Request $r): Response
    {
        $dt = DataTableQuery::fromRequest($r, ['return_no','return_date'], ['status']);
        return Inertia::render('SALES::Returns/Index', ['returns'=>PaginatedResource::from($this->svc->paginate($dt)->through(fn(SalesReturn $ret)=>[
            'id'=>$ret->id,'return_no'=>$ret->return_no,'customer'=>$ret->customer?['name'=>$ret->customer->name]:null,'invoice'=>$ret->invoice?['invoice_no'=>$ret->invoice->invoice_no]:null,'return_date'=>$ret->return_date->format('Y-m-d'),'total_amount'=>$ret->total_amount,'status'=>$ret->status,'items_count'=>$ret->items_count,
        ]))]);
    }
    public function create(Request $r): Response
    {
        $invoice = $r->query('invoice_id') ? \Modules\SALES\Models\Invoice::with(['customer','warehouse','items.product'])->find($r->query('invoice_id')) : null;
        return Inertia::render('SALES::Returns/Create', ['invoice'=>$invoice]);
    }
    public function store(StoreSalesReturnRequest $r): RedirectResponse { $ret=$this->svc->create($r->validated()); return redirect()->route('sales.returns.show',$ret->id)->with('success',"Return {$ret->return_no} created."); }
    public function show(SalesReturn $salesReturn): Response { $salesReturn->load(['customer','warehouse','invoice','items.product','items.invoiceItem','journal']); return Inertia::render('SALES::Returns/Show',['ret'=>$salesReturn]); }
    public function edit(SalesReturn $salesReturn): Response { $salesReturn->load('items'); return Inertia::render('SALES::Returns/Edit',['ret'=>$salesReturn]); }
    public function update(StoreSalesReturnRequest $r, SalesReturn $salesReturn): RedirectResponse { return back()->with('error','Edit not supported — cancel and recreate.'); }
    public function destroy(SalesReturn $salesReturn): RedirectResponse { try{$this->svc->delete($salesReturn); return redirect()->route('sales.returns.index')->with('success','Deleted.');}catch(\DomainException $e){return back()->with('error',$e->getMessage());}}
    public function approve(SalesReturn $salesReturn): RedirectResponse { try{$this->svc->approve($salesReturn); return back()->with('success',"Return {$salesReturn->return_no} approved — stock restocked & credit note posted.");}catch(\DomainException $e){return back()->with('error',$e->getMessage());}}
    public function cancel(SalesReturn $salesReturn): RedirectResponse { try{$this->svc->cancel($salesReturn); return back()->with('success','Cancelled.');}catch(\DomainException $e){return back()->with('error',$e->getMessage());}}
}
