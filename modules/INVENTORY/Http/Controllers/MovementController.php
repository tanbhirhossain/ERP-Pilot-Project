<?php

namespace Modules\INVENTORY\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\CORE\Support\DataTableQuery;
use Modules\CORE\Support\PaginatedResource;
use Modules\INVENTORY\Models\StockMove;
use Modules\INVENTORY\Services\WarehouseService;

class MovementController extends Controller
{
    public function __construct(private readonly WarehouseService $warehouses) {}

    public function index(Request $r): Response
    {
        $dt = DataTableQuery::fromRequest($r, ['move_no', 'qty', 'unit_cost', 'moved_at', 'created_at'], ['warehouse_id', 'product_id', 'reference_type', 'direction']);
        $q = StockMove::query()->with(['product:id,name,sku', 'warehouse:id,name,code', 'mover:id,name']);
        // Apply manual filters via DataTable pattern
        if ($search = $r->input('search')) { $needle = '%'.addcslashes($search, '%_').'%'; $q->where(fn ($g) => $g->where('move_no', 'like', $needle)->orWhere('reference_no', 'like', $needle)); }
        if ($wh = $r->input('filters.warehouse_id') ?? $r->input('filters')['warehouse_id'] ?? null) $q->where('warehouse_id', $wh);
        if ($type = $r->input('filters.reference_type') ?? $r->input('filters')['reference_type'] ?? null) $q->where('reference_type', $type);
        if ($dir = $r->input('filters.direction') ?? $r->input('filters')['direction'] ?? null) $q->where('direction', $dir);
        $sort = $r->input('sort', 'moved_at'); $dir = $r->input('dir', 'desc');
        $allowed = ['move_no' => 'move_no', 'qty' => 'qty', 'moved_at' => 'moved_at', 'created_at' => 'created_at'];
        $col = $allowed[$sort] ?? 'moved_at';
        $q->orderBy($col, in_array($dir, ['asc', 'desc'], true) ? $dir : 'desc');
        $perPage = min(100, max(1, (int) ($r->input('per_page', 25))));
        $p = $q->paginate($perPage)->withQueryString();
        return Inertia::render('INVENTORY::Movements/Index', [
            'movements' => PaginatedResource::from($p->through(fn (StockMove $m) => [
                'id' => $m->id, 'move_no' => $m->move_no, 'product' => $m->product ? ['name' => $m->product->name, 'sku' => $m->product->sku] : null,
                'warehouse' => $m->warehouse ? ['name' => $m->warehouse->name] : null, 'qty' => $m->qty, 'direction' => $m->direction,
                'unit_cost' => $m->unit_cost, 'total_cost' => $m->total_cost, 'reference_type' => $m->reference_type, 'reference_no' => $m->reference_no,
                'lot_no' => $m->lot_no, 'reason' => $m->reason, 'mover' => $m->mover?->name, 'moved_at' => $m->moved_at?->toIso8601String(),
            ])),
            'warehouseOptions' => $this->warehouses->options(null, 100),
        ]);
    }
}
