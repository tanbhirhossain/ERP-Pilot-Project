<?php

namespace Modules\INVENTORY\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\CORE\Support\DataTableQuery;
use Modules\CORE\Support\PaginatedResource;
use Modules\INVENTORY\Models\Brand;
use Modules\INVENTORY\Services\BrandService;

class BrandController extends Controller
{
    public function __construct(private readonly BrandService $svc) {}

    public function index(Request $r): Response
    {
        $dt = DataTableQuery::fromRequest($r, ['name', 'code', 'created_at'], ['is_active']);

        return Inertia::render('INVENTORY::Brands/Index', ['brands' => PaginatedResource::from($this->svc->paginate($dt)->through(fn (Brand $b) => ['id' => $b->id, 'name' => $b->name, 'code' => $b->code, 'is_active' => $b->is_active, 'products_count' => $b->products_count, 'created_at' => $b->created_at?->toIso8601String()]))]);
    }

    public function create(): Response
    {
        return Inertia::render('INVENTORY::Brands/Create');
    }

    public function store(Request $r): RedirectResponse
    {
        $this->svc->create($r->validate(['name' => ['required', 'string', 'max:255'], 'code' => ['nullable', 'string', 'max:20'], 'description' => ['nullable', 'string', 'max:500'], 'is_active' => ['nullable', 'boolean']]));

        return redirect()->route('inventory.brands.index')->with('success', 'Brand created.');
    }

    public function edit(Brand $brand): Response
    {
        return Inertia::render('INVENTORY::Brands/Edit', ['brand' => $brand]);
    }

    public function update(Request $r, Brand $brand): RedirectResponse
    {
        $this->svc->update($brand, $r->validate(['name' => ['required', 'string', 'max:255'], 'code' => ['nullable', 'string', 'max:20'], 'description' => ['nullable', 'string', 'max:500'], 'is_active' => ['nullable', 'boolean']]));

        return redirect()->route('inventory.brands.index')->with('success', 'Brand updated.');
    }

    public function destroy(Brand $brand): RedirectResponse
    {
        try {
            $this->svc->delete($brand);

            return redirect()->route('inventory.brands.index')->with('success', 'Brand deleted.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
