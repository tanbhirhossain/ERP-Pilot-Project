<?php

namespace Modules\INVENTORY\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\CORE\Support\DataTableQuery;
use Modules\CORE\Support\PaginatedResource;
use Modules\INVENTORY\Models\Adjustment;
use Modules\INVENTORY\Requests\StoreAdjustmentRequest;
use Modules\INVENTORY\Services\AdjustmentService;
use Modules\INVENTORY\Services\WarehouseService;

class AdjustmentController extends Controller
{
    public function __construct(private readonly AdjustmentService $svc, private readonly WarehouseService $warehouses) {}

    public function index(Request $r): Response
    {
        $dt = DataTableQuery::fromRequest($r, ['adjustment_no', 'type', 'status', 'created_at'], ['type', 'status', 'warehouse_id']);
        return Inertia::render('INVENTORY::Adjustments/Index', [
            'adjustments' => PaginatedResource::from($this->svc->paginate($dt)->through(fn (Adjustment $a) => [
                'id' => $a->id, 'adjustment_no' => $a->adjustment_no, 'warehouse' => $a->warehouse ? ['name' => $a->warehouse->name] : null,
                'type' => $a->type, 'status' => $a->status, 'reason' => $a->reason, 'items_count' => $a->items_count, 'creator' => $a->creator?->name, 'created_at' => $a->created_at?->toIso8601String(),
            ])),
            'warehouseOptions' => $this->warehouses->options(null, 100),
        ]);
    }

    public function create(): Response { return Inertia::render('INVENTORY::Adjustments/Create', ['warehouseOptions' => $this->warehouses->options(null, 100)]); }
    public function store(StoreAdjustmentRequest $r): RedirectResponse { $a = $this->svc->create($r->validated()); return redirect()->route('inventory.adjustments.show', $a->id)->with('success', "Adjustment {$a->adjustment_no} created."); }
    public function show(Adjustment $adjustment): Response { $adjustment->load(['warehouse', 'items.product', 'creator', 'approver']); return Inertia::render('INVENTORY::Adjustments/Show', ['adjustment' => $adjustment]); }
    public function edit(Adjustment $adjustment): Response { $adjustment->load('items'); return Inertia::render('INVENTORY::Adjustments/Edit', ['adjustment' => $adjustment, 'warehouseOptions' => $this->warehouses->options(null, 100)]); }
    public function update(StoreAdjustmentRequest $r, Adjustment $adjustment): RedirectResponse { $this->svc->update($adjustment, $r->validated()); return redirect()->route('inventory.adjustments.show', $adjustment->id)->with('success', 'Adjustment updated.'); }
    public function destroy(Adjustment $adjustment): RedirectResponse { try { $this->svc->delete($adjustment); return redirect()->route('inventory.adjustments.index')->with('success', 'Adjustment deleted.'); } catch (\DomainException $e) { return back()->with('error', $e->getMessage()); } }
    public function approve(Adjustment $adjustment): RedirectResponse { try { $this->svc->approve($adjustment); return back()->with('success', 'Adjustment approved.'); } catch (\DomainException $e) { return back()->with('error', $e->getMessage()); } }
    public function post(Adjustment $adjustment): RedirectResponse { try { $this->svc->post($adjustment); return back()->with('success', "Adjustment {$adjustment->adjustment_no} posted — stock updated."); } catch (\DomainException $e) { return back()->with('error', $e->getMessage()); } }
}
