<?php

namespace Modules\INVENTORY\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\CORE\Support\DataTableQuery;
use Modules\CORE\Support\PaginatedResource;
use Modules\INVENTORY\Models\Product;
use Modules\INVENTORY\Requests\StoreProductRequest;
use Modules\INVENTORY\Requests\UpdateProductRequest;
use Modules\INVENTORY\Services\BrandService;
use Modules\INVENTORY\Services\CategoryService;
use Modules\INVENTORY\Services\ProductService;
use Modules\INVENTORY\Services\UomService;
use Modules\INVENTORY\Services\WarehouseService;

class ProductController extends Controller
{
    public function __construct(
        private readonly ProductService $products,
        private readonly CategoryService $categories,
        private readonly BrandService $brands,
        private readonly UomService $uoms,
        private readonly WarehouseService $warehouses,
    ) {}

    public function index(Request $request): Response
    {
        $dt = DataTableQuery::fromRequest($request, ['sku', 'name', 'category_id', 'brand_id', 'cost_price', 'selling_price', 'created_at', 'type', 'status'], ['category_id', 'brand_id', 'type', 'status', 'is_active', 'stock_status']);

        return Inertia::render('INVENTORY::Products/Index', [
            'products' => PaginatedResource::from($this->products->paginate($dt)->through(fn (Product $p) => [
                'id' => $p->id, 'name' => $p->name, 'sku' => $p->sku, 'barcode' => $p->barcode,
                'category' => $p->category ? ['id' => $p->category->id, 'name' => $p->category->name] : null,
                'brand' => $p->brand ? ['id' => $p->brand->id, 'name' => $p->brand->name] : null,
                'uom' => $p->uom ? ['symbol' => $p->uom->symbol] : null,
                'type' => $p->type, 'status' => $p->status, 'is_active' => $p->is_active,
                'cost_price' => $p->cost_price, 'selling_price' => $p->selling_price, 'mrp' => $p->mrp,
                'total_stock' => $p->total_stock_sum ?? $p->stocks->sum('qty_on_hand'),
                'reorder_level' => $p->reorder_level, 'image' => $p->image,
                'created_at' => $p->created_at?->toIso8601String(),
            ])),
            'categoryOptions' => $this->categories->options(null, 100),
            'brandOptions' => $this->brands->options(null, 100),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('INVENTORY::Products/Create', [
            'categoryOptions' => $this->categories->options(null, 200),
            'brandOptions' => $this->brands->options(null, 100),
            'uomOptions' => $this->uoms->options(null, 100),
            'warehouseOptions' => $this->warehouses->options(null, 100),
        ]);
    }

    public function store(StoreProductRequest $request): RedirectResponse
    {
        $data = $request->validated();
        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image');
        }
        $product = $this->products->create($data);

        return redirect()->route('inventory.products.show', $product->id)->with('success', "Product {$product->sku} created.");
    }

    public function show(Product $product): Response
    {
        $product->load(['category', 'brand', 'uom', 'variants', 'suppliers.partner', 'bomComponents.component', 'images', 'stocks.warehouse', 'stocks.location', 'moves' => fn ($q) => $q->latest()->limit(10)]);

        return Inertia::render('INVENTORY::Products/Show', [
            'product' => [
                'id' => $product->id, 'name' => $product->name, 'sku' => $product->sku, 'barcode' => $product->barcode, 'slug' => $product->slug,
                'category' => $product->category, 'brand' => $product->brand, 'uom' => $product->uom,
                'type' => $product->type, 'description' => $product->description, 'hs_code' => $product->hs_code,
                'weight' => $product->weight, 'dimensions' => $product->dimensions,
                'is_serialized' => $product->is_serialized, 'is_batch_tracked' => $product->is_batch_tracked, 'shelf_life_days' => $product->shelf_life_days,
                'reorder_level' => $product->reorder_level, 'reorder_qty' => $product->reorder_qty, 'valuation_method' => $product->valuation_method,
                'cost_price' => $product->cost_price, 'selling_price' => $product->selling_price, 'mrp' => $product->mrp, 'vat_rate' => $product->vat_rate,
                'image' => $product->image, 'is_active' => $product->is_active, 'status' => $product->status,
                'variants' => $product->variants, 'suppliers' => $product->suppliers, 'bom' => $product->bomComponents, 'images' => $product->images,
                'stocks' => $product->stocks->map(fn ($s) => ['id' => $s->id, 'warehouse' => $s->warehouse?->name, 'location' => $s->location?->name, 'qty_on_hand' => $s->qty_on_hand, 'qty_available' => $s->qty_available, 'avg_cost' => $s->avg_cost]),
                'moves' => $product->moves,
                'created_at' => $product->created_at?->toIso8601String(),
            ],
            'totalStock' => $product->stocks->sum('qty_on_hand'),
            'totalValue' => $product->stocks->sum('total_value'),
        ]);
    }

    public function edit(Product $product): Response
    {
        $product->load(['variants', 'suppliers', 'images']);

        return Inertia::render('INVENTORY::Products/Edit', [
            'product' => [
                'id' => $product->id, 'name' => $product->name, 'sku' => $product->sku, 'barcode' => $product->barcode,
                'category_id' => $product->category_id, 'brand_id' => $product->brand_id, 'uom_id' => $product->uom_id,
                'type' => $product->type, 'description' => $product->description, 'hs_code' => $product->hs_code,
                'weight' => $product->weight, 'currency' => $product->currency,
                'reorder_level' => $product->reorder_level, 'reorder_qty' => $product->reorder_qty, 'valuation_method' => $product->valuation_method,
                'cost_price' => $product->cost_price, 'selling_price' => $product->selling_price, 'mrp' => $product->mrp, 'vat_rate' => $product->vat_rate,
                'is_serialized' => $product->is_serialized, 'is_batch_tracked' => $product->is_batch_tracked, 'shelf_life_days' => $product->shelf_life_days,
                'is_active' => $product->is_active, 'status' => $product->status, 'image' => $product->image,
                'variants' => $product->variants, 'suppliers' => $product->suppliers->toArray(), 'images' => $product->images,
            ],
            'categoryOptions' => $this->categories->options(null, 200),
            'brandOptions' => $this->brands->options(null, 100),
            'uomOptions' => $this->uoms->options(null, 100),
            'warehouseOptions' => $this->warehouses->options(null, 100),
        ]);
    }

    public function update(UpdateProductRequest $request, Product $product): RedirectResponse
    {
        $data = $request->validated();
        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image');
        }
        $this->products->update($product, $data);

        return redirect()->route('inventory.products.show', $product->id)->with('success', "Product {$product->sku} updated.");
    }

    public function destroy(Product $product): RedirectResponse
    {
        try {
            $this->products->delete($product);

            return redirect()->route('inventory.products.index')->with('success', "Product {$product->sku} deleted.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
