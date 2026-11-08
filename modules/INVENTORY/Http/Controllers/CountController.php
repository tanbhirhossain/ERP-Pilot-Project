<?php

namespace Modules\INVENTORY\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\CORE\Repositories\Concerns\BuildsDatatableQueries;
use Modules\CORE\Support\DataTableQuery;
use Modules\CORE\Support\PaginatedResource;
use Modules\INVENTORY\Models\StockCount;
use Modules\INVENTORY\Services\WarehouseService;

class CountController extends Controller
{
    use BuildsDatatableQueries;

    public function __construct(private readonly WarehouseService $warehouses) {}

    public function index(Request $r): Response
    {
        $dt = DataTableQuery::fromRequest($r, ['count_no', 'status', 'created_at'], ['status', 'warehouse_id']);
        $query = StockCount::query()->with(['warehouse'])->withCount('items');
        $this->applyDataTable(
            $query,
            $dt,
            ['inventory_stock_counts.count_no', 'inventory_stock_counts.status'],
            [
                'count_no' => 'inventory_stock_counts.count_no',
                'status' => 'inventory_stock_counts.status',
                'created_at' => 'inventory_stock_counts.created_at',
            ],
            [
                'status' => fn ($q, string $v) => $q->where('inventory_stock_counts.status', $v),
                'warehouse_id' => fn ($q, string $v) => $q->where('inventory_stock_counts.warehouse_id', $v),
            ],
        );

        return Inertia::render('INVENTORY::Counts/Index', [
            'counts' => PaginatedResource::from($query->paginate($dt->perPage, ['*'], 'page', $dt->page)->withQueryString()->through(fn (StockCount $c) => [
                'id' => $c->id, 'count_no' => $c->count_no, 'warehouse' => $c->warehouse ? ['name' => $c->warehouse->name] : null,
                'status' => $c->status, 'items_count' => $c->items_count, 'created_at' => $c->created_at?->toIso8601String(),
            ])),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('INVENTORY::Counts/Create', ['warehouseOptions' => $this->warehouses->options(null, 100)]);
    }

    public function store(Request $r): RedirectResponse
    {
        $data = $r->validate(['warehouse_id' => 'required|exists:inventory_warehouses,id', 'notes' => 'nullable|string|max:2000']);
        $count = StockCount::create(['count_no' => 'CNT-'.str_pad((string) (StockCount::max('id') + 1), 5, '0', STR_PAD_LEFT), 'warehouse_id' => $data['warehouse_id'], 'status' => 'draft', 'notes' => $data['notes'] ?? null, 'created_by' => $r->user()?->id]);

        return redirect()->route('inventory.counts.show', $count->id)->with('success', "Count {$count->count_no} created.");
    }

    public function show(StockCount $count): Response
    {
        $count->load(['warehouse', 'items.product']);

        return Inertia::render('INVENTORY::Counts/Show', ['count' => $count]);
    }
}
