<?php

namespace Modules\INVENTORY\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\CORE\Support\DataTableQuery;
use Modules\CORE\Support\PaginatedResource;
use Modules\INVENTORY\Models\Category;
use Modules\INVENTORY\Requests\StoreCategoryRequest;
use Modules\INVENTORY\Services\CategoryService;

class CategoryController extends Controller
{
    public function __construct(private readonly CategoryService $svc) {}

    public function index(Request $r): Response
    {
        $dt = DataTableQuery::fromRequest($r, ['name', 'code', 'sort_order', 'created_at'], ['parent_id', 'is_active']);
        return Inertia::render('INVENTORY::Categories/Index', [
            'categories' => PaginatedResource::from($this->svc->paginate($dt)->through(fn (Category $c) => [
                'id' => $c->id, 'name' => $c->name, 'code' => $c->code, 'parent' => $c->parent ? ['id' => $c->parent->id, 'name' => $c->parent->name] : null,
                'description' => $c->description, 'sort_order' => $c->sort_order, 'is_active' => $c->is_active, 'products_count' => $c->products_count, 'created_at' => $c->created_at?->toIso8601String(),
            ])),
            'tree' => $this->svc->tree(),
            'parentOptions' => $this->svc->options(null, 100),
        ]);
    }

    public function create(): Response { return Inertia::render('INVENTORY::Categories/Create', ['parentOptions' => $this->svc->options(null, 100)]); }
    public function store(StoreCategoryRequest $r): RedirectResponse { $this->svc->create($r->validated()); return redirect()->route('inventory.categories.index')->with('success', 'Category created.'); }
    public function edit(Category $category): Response { return Inertia::render('INVENTORY::Categories/Edit', ['category' => $category, 'parentOptions' => $this->svc->options(null, 100)]); }
    public function update(Request $r, Category $category): RedirectResponse { $category->update($r->validate(['name' => ['required', 'string', 'max:255'], 'code' => ['nullable', 'string', 'max:20'], 'parent_id' => ['nullable', 'exists:inventory_categories,id'], 'description' => ['nullable', 'string', 'max:500'], 'sort_order' => ['nullable', 'integer'], 'is_active' => ['nullable', 'boolean']])); return redirect()->route('inventory.categories.index')->with('success', 'Category updated.'); }
    public function destroy(Category $category): RedirectResponse
    {
        try { $this->svc->delete($category); return redirect()->route('inventory.categories.index')->with('success', 'Category deleted.'); }
        catch (\DomainException $e) { return back()->with('error', $e->getMessage()); }
    }
}
