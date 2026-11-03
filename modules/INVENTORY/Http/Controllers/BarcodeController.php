<?php

namespace Modules\INVENTORY\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\INVENTORY\Models\Product;
use Barryvdh\DomPDF\Facade\Pdf;

class BarcodeController extends Controller
{
    public function index(Request $request): Response
    {
        $products = Product::active()->orderBy('name')->limit(200)->get(['id','name','sku','barcode','selling_price','mrp']);
        return Inertia::render('INVENTORY::Products/BarcodePrint', [
            'products' => $products,
            'presets' => [
                ['label'=>'A4 — 24 labels (3×8) 70×33mm', 'cols'=>3, 'rows'=>8, 'width'=>70, 'height'=>33],
                ['label'=>'A4 — 40 labels (4×10) 50×25mm', 'cols'=>4, 'rows'=>10, 'width'=>50, 'height'=>25],
                ['label'=>'Thermal 50×30mm roll', 'cols'=>1, 'rows'=>1, 'width'=>50, 'height'=>30],
                ['label'=>'Thermal 100×50mm', 'cols'=>1, 'rows'=>1, 'width'=>100, 'height'=>50],
            ],
        ]);
    }

    public function pdf(Request $request)
    {
        $request->validate([
            'items' => 'required|array|min:1|max:200',
            'items.*.product_id' => 'required|exists:inventory_products,id',
            'items.*.qty' => 'required|integer|min:1|max:100',
            'cols' => 'nullable|integer|min:1|max:6',
            'paper' => 'nullable|string|in:a4,thermal',
        ]);
        $items = collect($request->input('items'))->map(function($row){
            $p = Product::find($row['product_id']);
            return array_merge($row, ['product'=>$p]);
        });
        $cols = (int)($request->input('cols', 3));
        $paper = $request->input('paper','a4');
        // expand qty
        $expanded = collect();
        foreach ($items as $it) {
            for ($i=0; $i<(int)$it['qty']; $i++) $expanded->push($it);
        }
        $pdf = Pdf::loadView('inventory::barcode-pdf', [
            'labels'=>$expanded,
            'cols'=>$cols,
            'paper'=>$paper,
        ]);
        $paperSize = $paper === 'thermal' ? [0,0,283,170] : 'A4';
        $pdf->setPaper($paperSize, 'portrait');
        return $pdf->download('barcodes-'.now()->format('YmdHis').'.pdf');
    }

    public function zpl(Request $request)
    {
        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:inventory_products,id',
            'items.*.qty' => 'required|integer|min:1|max:100',
        ]);
        $lines = ["^XA","^CI28"];
        foreach ($request->input('items') as $it) {
            $p = Product::find($it['product_id']);
            $code = $p->barcode ?: $p->sku;
            $name = substr($p->name,0,30);
            for ($i=0; $i<(int)$it['qty']; $i++) {
                $lines[] = "^FO20,20^A0N,30,30^FD{$name}^FS";
                $lines[] = "^FO20,60^BY2,3,80^BCN,80,Y,N,N^FD{$code}^FS";
                $lines[] = "^FO20,150^A0N,25,25^FD{$code}  ".number_format((float)$p->selling_price,2)." BDT^FS";
                $lines[] = "^XZ"; $lines[] = "^XA";
            }
        }
        $lines[] = "^XZ";
        return response(implode("\n",$lines), 200, [
            'Content-Type'=>'text/plain',
            'Content-Disposition'=>'attachment; filename="labels-'.now()->format('YmdHis').'.zpl"',
        ]);
    }
}
