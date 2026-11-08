<?php

namespace Modules\INVENTORY\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\CORE\Support\DataTableQuery;
use Modules\CORE\Support\PaginatedResource;
use Modules\INVENTORY\Models\Uom;
use Modules\INVENTORY\Services\UomService;

class UomController extends Controller
{
    public function __construct(private readonly UomService $svc) {}

    public function index(Request $r): Response
    {
        $dt = DataTableQuery::fromRequest($r, ['name', 'symbol', 'code', 'created_at'], ['is_active']);

        return Inertia::render('INVENTORY::Uoms/Index', ['uoms' => PaginatedResource::from($this->svc->paginate($dt)->through(fn (Uom $u) => ['id' => $u->id, 'name' => $u->name, 'symbol' => $u->symbol, 'code' => $u->code, 'is_active' => $u->is_active, 'created_at' => $u->created_at?->toIso8601String()]))]);
    }

    public function create(): Response
    {
        return Inertia::render('INVENTORY::Uoms/Create', ['uomOptions' => $this->svc->options(null, 100)]);
    }

    public function store(Request $r): RedirectResponse
    {
        $this->svc->create($r->validate(['name' => ['required', 'string', 'max:255'], 'symbol' => ['required', 'string', 'max:20'], 'code' => ['required', 'string', 'max:20', 'unique:inventory_uoms,code'], 'base_uom_id' => ['nullable', 'exists:inventory_uoms,id'], 'conversion_factor' => ['nullable', 'numeric', 'min:0.0001'], 'is_active' => ['nullable', 'boolean']]));

        return redirect()->route('inventory.uoms.index')->with('success', 'UOM created.');
    }

    public function edit(Uom $uom): Response
    {
        return Inertia::render('INVENTORY::Uoms/Edit', ['uom' => $uom, 'uomOptions' => $this->svc->options(null, 100)]);
    }

    public function update(Request $r, Uom $uom): RedirectResponse
    {
        $this->svc->update($uom, $r->validate(['name' => ['required', 'string', 'max:255'], 'symbol' => ['required', 'string', 'max:20'], 'code' => ['required', 'string', 'max:20'], 'base_uom_id' => ['nullable', 'exists:inventory_uoms,id'], 'conversion_factor' => ['nullable', 'numeric', 'min:0.0001'], 'is_active' => ['nullable', 'boolean']]));

        return redirect()->route('inventory.uoms.index')->with('success', 'UOM updated.');
    }

    public function destroy(Uom $uom): RedirectResponse
    {
        try {
            $this->svc->delete($uom);

            return redirect()->route('inventory.uoms.index')->with('success', 'UOM deleted.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
