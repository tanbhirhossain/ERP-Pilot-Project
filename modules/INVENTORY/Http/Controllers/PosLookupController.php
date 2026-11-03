<?php

namespace Modules\INVENTORY\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\INVENTORY\Models\Product;
use Modules\INVENTORY\Models\Stock;

class PosLookupController extends Controller
{
    public function __invoke(Request $request, string $key): JsonResponse
    {
        // key can be id, sku, or barcode
        $product = Product::query()->active()
            ->where(function ($q) use ($key) {
                $q->where('id', $key)->orWhere('sku', $key)->orWhere('barcode', $key);
            })->first();

        if (! $product) {
            return response()->json(['found' => false, 'message' => 'Product not found for: '.$key]);
        }

        $warehouseId = $request->query('warehouse_id');
        $stock = Stock::where('product_id', $product->id)->when($warehouseId, fn($q)=>$q->where('warehouse_id', $warehouseId))->first();

        return response()->json([
            'found' => true,
            'data' => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'barcode' => $product->barcode,
                'selling_price' => $product->selling_price,
                'vat_rate' => $product->vat_rate ?? 0,
                'uom' => $product->uom?->symbol,
                'available' => $stock?->qty_available ?? $stock?->qty_on_hand ?? 0,
                'on_hand' => $stock?->qty_on_hand ?? 0,
            ],
        ]);
    }
}
