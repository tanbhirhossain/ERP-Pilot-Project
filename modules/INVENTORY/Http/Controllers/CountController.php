<?php

namespace Modules\INVENTORY\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\CORE\Support\DataTableQuery;
use Modules\CORE\Support\PaginatedResource;
use Modules\INVENTORY\Models\InventoryCount;
use Modules\INVENTORY\Services\WarehouseService;

class CountController extends Controller
{
    public function __construct(private readonly WarehouseService $warehouses) {}

    public function index(Request $r): Response
    {
        $dt = DataTableQuery::fromRequest($r, ['count_no', 'status', 'created_at'], ['status', 'warehouse_id']);
        // minimal paginate via model if service not yet
        $query = InventoryCount::query()->with(['warehouse'])->withCount('items');
        $dt->apply($query);
        return Inertia::render('INVENTORY::Counts/Index', [
            'counts' => PaginatedResource::from($query->latest()->paginate($dt->perPage)->through(fn (InventoryCount $c) => [
                'id' => $c->id, 'count_no' => $c->count_no, 'warehouse' => $c->warehouse ? ['name' => $c->warehouse->name] : null,
                'status' => $c->status, 'items_count' => $c->items_count, 'created_at' => $c->created_at?->toIso8601String(),
            ])),
        ]);
    }

    public function create(): Response { return Inertia::render('INVENTORY::Counts/Create', ['warehouseOptions' => $this->warehouses->options(null, 100)]); }
    public function store(Request $r): RedirectResponse
    {
        $data = $r->validate(['warehouse_id' => 'required|exists:inv_warehouses,id', 'notes' => 'nullable|string|max:2000']);
        $count = InventoryCount::create(['count_no' => 'CNT-' . str_pad((string)(InventoryCount::max('id') + 1), 5, '0', STR_PAD_LEFT), 'warehouse_id' => $data['warehouse_id'], 'status' => 'draft', 'notes' => $data['notes'] ?? null, 'created_by' => $r->user()?->id]);
        return redirect()->route('inventory.counts.show', $count->id)->with('success', "Count {$count->count_no} created.");
    }
    public function show(InventoryCount $count): Response { $count->load(['warehouse', 'items.product']); return Inertia::render('INVENTORY::Counts/Show', ['count' => $count]); }
}
