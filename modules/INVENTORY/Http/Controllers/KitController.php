<?php

namespace Modules\INVENTORY\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\INVENTORY\Models\Kit;
use Modules\INVENTORY\Services\KitService;

class KitController extends Controller
{
    public function __construct(private readonly KitService $svc) {}

    public function index(Request $r): Response
    {
        $q = Kit::with(['product','warehouse'])->latest()->paginate(15);
        return Inertia::render('INVENTORY::Kits/Index', ['kits'=>$q]);
    }

    public function create(): Response
    {
        $products = \Modules\INVENTORY\Models\Product::orderBy('name')->limit(200)->get(['id','name','sku']);
        $warehouses = \Modules\INVENTORY\Models\Warehouse::orderBy('name')->get(['id','name']);
        return Inertia::render('INVENTORY::Kits/Create', ['products'=>$products,'warehouses'=>$warehouses]);
    }

    public function store(Request $r): RedirectResponse
    {
        $r->validate([
            'product_id'=>'required|exists:inventory_products,id',
            'warehouse_id'=>'required|exists:inventory_warehouses,id',
            'qty_to_produce'=>'required|numeric|min:0.01',
            'notes'=>'nullable|string',
            'items'=>'required|array|min:1',
            'items.*.component_product_id'=>'required|exists:inventory_products,id',
            'items.*.required_qty'=>'required|numeric|min:0.001',
            'items.*.is_optional'=>'nullable|boolean',
        ]);
        $kit=$this->svc->create($r->only(['product_id','warehouse_id','qty_to_produce','notes','items']));
        return redirect()->route('inventory.kits.show',$kit->id)->with('success',"Kit {$kit->kit_no} created.");
    }

    public function show(Kit $kit): Response
    {
        $kit->load(['product','warehouse','items.component']);
        $kit->product?->load('bomComponents.component');
        return Inertia::render('INVENTORY::Kits/Show', ['kit'=>$kit]);
    }

    public function complete(Kit $kit): RedirectResponse
    {
        try { $this->svc->complete($kit); return back()->with('success',"Kit {$kit->kit_no} completed — components consumed & kit produced."); }
        catch (\DomainException $e) { return back()->with('error',$e->getMessage()); }
    }

    public function cancel(Kit $kit): RedirectResponse
    {
        try { $this->svc->cancel($kit); return back()->with('success','Cancelled.'); }
        catch (\DomainException $e) { return back()->with('error',$e->getMessage()); }
    }
}
