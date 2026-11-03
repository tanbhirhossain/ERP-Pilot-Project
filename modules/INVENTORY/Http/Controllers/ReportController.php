<?php

namespace Modules\INVENTORY\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\INVENTORY\Models\FifoLayer;
use Modules\INVENTORY\Models\Lot;
use Modules\INVENTORY\Models\Product;
use Modules\INVENTORY\Models\Stock;
use Modules\INVENTORY\Services\ValuationService;

class ReportController extends Controller
{
    public function fifo(Request $request, ValuationService $valuation): Response
    {
        $productId = $request->input('product_id');
        $warehouseId = $request->input('warehouse_id');
        $products = Product::orderBy('name')->limit(200)->get(['id','name','sku','valuation_method']);
        $warehouses = \Modules\INVENTORY\Models\Warehouse::orderBy('name')->get(['id','name','code']);
        $layers = collect();
        $valuationData = null;
        if ($productId) {
            $valuationData = $valuation->getValuation((int)$productId, $warehouseId ? (int)$warehouseId : null);
            $layers = FifoLayer::with(['warehouse','lot'])
                ->where('product_id', $productId)
                ->when($warehouseId, fn($q)=>$q->where('warehouse_id',$warehouseId))
                ->where('qty_remaining','>',0)
                ->orderBy('received_at')->orderBy('id')
                ->get()->map(fn($l)=>[
                    'id'=>$l->id,'lot_no'=>$l->lot_no,'warehouse'=>$l->warehouse?->name,'qty_in'=>$l->qty_in,'qty_remaining'=>$l->qty_remaining,'unit_cost'=>$l->unit_cost,'total_cost'=>$l->total_cost,'received_at'=>$l->received_at->format('Y-m-d H:i'),
                ]);
        }
        return Inertia::render('INVENTORY::Reports/Fifo', [
            'products'=>$products,
            'warehouses'=>$warehouses,
            'filters'=>['product_id'=>$productId,'warehouse_id'=>$warehouseId],
            'valuation'=>$valuationData?->toArray(),
            'layers'=>$layers,
            'fifoLayers'=>$valuationData?->fifoLayers ?? [],
        ]);
    }

    public function reorder(Request $request): Response
    {
        // low stock + reorder qty suggestion + ABC
        $stocks = Stock::with(['product.category','warehouse'])
            ->get()
            ->groupBy('product_id')
            ->map(function($group){
                $product = $group->first()->product;
                $totalOnHand = (float)$group->sum('qty_on_hand');
                $totalValue = (float)$group->sum('total_value');
                $reorderLevel = (float)($product->reorder_level ?? 0);
                $reorderQty = (float)($product->reorder_qty ?? 0);
                $needed = $reorderLevel > 0 && $totalOnHand <= $reorderLevel ? max($reorderQty, $reorderLevel - $totalOnHand) : 0;
                return [
                    'product_id'=>$product->id,'sku'=>$product->sku,'name'=>$product->name,'category'=>$product->category?->name,
                    'valuation_method'=>$product->valuation_method,'on_hand'=>$totalOnHand,'total_value'=>$totalValue,
                    'reorder_level'=>$reorderLevel,'reorder_qty'=>$reorderQty,'needed'=>$needed,'status'=>$totalOnHand<=0?'out':($totalOnHand <= $reorderLevel ? 'low' : 'ok'),
                ];
            })->values();

        // ABC: sort by total_value desc, cumulative %
        $totalVal = (float)$stocks->sum('total_value');
        $sorted = $stocks->sortByDesc('total_value')->values();
        $cum=0; $abc=[];
        foreach ($sorted as $row) {
            $cum += $row['total_value'];
            $pct = $totalVal>0 ? $cum/$totalVal : 0;
            $class = $pct <= 0.80 ? 'A' : ($pct <= 0.95 ? 'B' : 'C');
            $row['abc']=$class; $row['cumulative_pct']=round($pct*100,1);
            $abc[]=$row;
        }

        return Inertia::render('INVENTORY::Reports/Reorder', [
            'stocks'=>$abc,
            'stats'=>[
                'total_products'=>$stocks->count(),'low_stock'=>$stocks->where('status','low')->count(),'out_stock'=>$stocks->where('status','out')->count(),
                'total_value'=>round($totalVal,2),'a_items'=>collect($abc)->where('abc','A')->count(),'b_items'=>collect($abc)->where('abc','B')->count(),'c_items'=>collect($abc)->where('abc','C')->count(),
            ],
        ]);
    }

    public function lots(Request $request): Response
    {
        $lots = Lot::with(['product','warehouse'])
            ->when($request->input('product_id'), fn($q,$v)=>$q->where('product_id',$v))
            ->when($request->input('expired'), fn($q)=>$q->where('expiry_date','<',now()->toDateString()))
            ->orderByDesc('expiry_date')->paginate(20)->through(fn($l)=>[
                'id'=>$l->id,'lot_no'=>$l->lot_no,'product'=>$l->product?->only(['name','sku']),'warehouse'=>$l->warehouse?->name,
                'manufacture_date'=>$l->manufacture_date?->format('Y-m-d'),'expiry_date'=>$l->expiry_date?->format('Y-m-d'),
                'qty_received'=>$l->qty_received,'qty_remaining'=>$l->qty_remaining,'is_expired'=>$l->isExpired(),'unit_cost'=>$l->unit_cost,
            ]);
        $products = Product::where('is_batch_tracked',true)->orderBy('name')->get(['id','name','sku']);
        return Inertia::render('INVENTORY::Reports/Lots', [
            'lots'=>$lots,'products'=>$products,'filters'=>$request->only('product_id','expired'),
        ]);
    }
}
