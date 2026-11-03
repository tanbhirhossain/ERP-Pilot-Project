<?php

namespace Modules\INVENTORY\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\CORE\Support\DataTableQuery;
use Modules\CORE\Support\PaginatedResource;
use Modules\INVENTORY\Models\Warehouse;
use Modules\INVENTORY\Requests\StoreWarehouseRequest;
use Modules\INVENTORY\Services\WarehouseService;

class WarehouseController extends Controller
{
    public function __construct(private readonly WarehouseService $svc) {}

    public function index(Request $r): Response
    {
        $dt = DataTableQuery::fromRequest($r, ['name', 'code', 'city', 'created_at', 'type'], ['type', 'is_active']);
        return Inertia::render('INVENTORY::Warehouses/Index', [
            'warehouses' => PaginatedResource::from($this->svc->paginate($dt)->through(fn (Warehouse $w) => [
                'id' => $w->id, 'name' => $w->name, 'code' => $w->code, 'type' => $w->type,
                'city' => $w->city, 'district' => $w->district, 'manager' => $w->manager ? ['name' => $w->manager->name] : null,
                'is_default' => $w->is_default, 'is_active' => $w->is_active, 'locations_count' => $w->locations_count, 'stocks_count' => $w->stocks_count, 'created_at' => $w->created_at?->toIso8601String(),
            ])),
        ]);
    }

    public function create(): Response { return Inertia::render('INVENTORY::Warehouses/Create', ['userOptions' => \Modules\CORE\Models\User::orderBy('name')->limit(100)->get()->map(fn ($u) => ['value' => $u->id, 'label' => $u->name])->values()->all()]); }
    public function store(StoreWarehouseRequest $r): RedirectResponse { $w = $this->svc->create($r->validated()); return redirect()->route('inventory.warehouses.show', $w->id)->with('success', "Warehouse {$w->code} created."); }
    public function show(Warehouse $warehouse): Response { $warehouse->load(['manager', 'locations', 'stocks.product']); return Inertia::render('INVENTORY::Warehouses/Show', ['warehouse' => $warehouse, 'stockSummary' => $warehouse->stocks->groupBy('product_id')->map(fn ($g) => ['qty' => $g->sum('qty_on_hand'), 'value' => $g->sum('total_value')])]); }
    public function edit(Warehouse $warehouse): Response { $warehouse->load('locations'); return Inertia::render('INVENTORY::Warehouses/Edit', ['warehouse' => $warehouse, 'userOptions' => \Modules\CORE\Models\User::orderBy('name')->limit(100)->get()->map(fn ($u) => ['value' => $u->id, 'label' => $u->name])->values()->all()]); }
    public function update(Request $r, Warehouse $warehouse): RedirectResponse { $this->svc->update($warehouse, $r->validate((new StoreWarehouseRequest)->rules())); return redirect()->route('inventory.warehouses.index')->with('success', 'Warehouse updated.'); }
    public function destroy(Warehouse $warehouse): RedirectResponse { try { $this->svc->delete($warehouse); return redirect()->route('inventory.warehouses.index')->with('success', 'Warehouse deleted.'); } catch (\DomainException $e) { return back()->with('error', $e->getMessage()); } }
}
