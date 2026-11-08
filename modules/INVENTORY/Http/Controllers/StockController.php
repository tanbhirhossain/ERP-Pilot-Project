<?php

namespace Modules\INVENTORY\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\CORE\Support\DataTableQuery;
use Modules\CORE\Support\PaginatedResource;
use Modules\INVENTORY\Models\Stock;
use Modules\INVENTORY\Services\StockService;
use Modules\INVENTORY\Services\ValuationService;
use Modules\INVENTORY\Services\WarehouseService;

class StockController extends Controller
{
    public function __construct(private readonly StockService $stocks, private readonly WarehouseService $warehouses, private readonly ValuationService $valuation) {}

    public function index(Request $r): Response
    {
        $dt = DataTableQuery::fromRequest($r, ['qty_on_hand', 'qty_available', 'avg_cost', 'total_value', 'last_movement_at'], ['warehouse_id', 'product_id', 'location_id', 'stock_status']);

        return Inertia::render('INVENTORY::Stocks/Index', [
            'stocks' => PaginatedResource::from($this->stocks->paginate($dt)->through(fn (Stock $s) => [
                'id' => $s->id,
                'product' => ['id' => $s->product->id, 'name' => $s->product->name, 'sku' => $s->product->sku, 'barcode' => $s->product->barcode, 'reorder_level' => $s->product->reorder_level, 'image' => $s->product->image],
                'variant' => $s->variant ? ['id' => $s->variant->id, 'name' => $s->variant->name] : null,
                'warehouse' => ['id' => $s->warehouse->id, 'name' => $s->warehouse->name, 'code' => $s->warehouse->code],
                'location' => $s->location ? ['id' => $s->location->id, 'name' => $s->location->name] : null,
                'lot_no' => $s->lot_no, 'qty_on_hand' => $s->qty_on_hand, 'qty_reserved' => $s->qty_reserved, 'qty_available' => $s->qty_available,
                'avg_cost' => $s->avg_cost, 'total_value' => $s->total_value, 'last_movement_at' => $s->last_movement_at?->toIso8601String(),
                'status' => $s->isOutOfStock() ? 'out' : ($s->isLowStock() ? 'low' : 'in'),
            ])),
            'warehouseOptions' => $this->warehouses->options(null, 100),
            'summary' => ['total_value' => $this->valuation->getTotalValuation(), 'low_count' => $this->stocks->getLowStock()->count(), 'out_count' => $this->stocks->getOutOfStock()->count()],
        ]);
    }
}
