<?php

namespace Modules\INVENTORY\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\CORE\Repositories\Concerns\BuildsDatatableQueries;
use Modules\CORE\Support\DataTableQuery;
use Modules\INVENTORY\Interfaces\ProductRepositoryInterface;
use Modules\INVENTORY\Models\Product;

class ProductRepository implements ProductRepositoryInterface
{
    use BuildsDatatableQueries;

    public function paginate(DataTableQuery $dt): LengthAwarePaginator
    {
        $q = $this->baseQuery();
        $this->applyDataTable($q, $dt, $this->searchColumns(), $this->sortable(), $this->filters());
        return $q->paginate($dt->perPage, ['*'], 'page', $dt->page)->withQueryString();
    }

    public function exportBuilder(DataTableQuery $dt): Builder
    {
        $q = $this->baseQuery();
        $this->applyDataTable($q, $dt, $this->searchColumns(), $this->sortable(), $this->filters());
        return $q;
    }

    public function options(?string $search = null, int $limit = 25): Collection
    {
        return Product::query()->active()
            ->when($search, function (Builder $q, string $term): void {
                $needle = '%'.addcslashes($term, '%_').'%';
                $q->where(fn (Builder $g) => $g->where('name', 'like', $needle)->orWhere('sku', 'like', $needle)->orWhere('barcode', 'like', $needle));
            })
            ->orderBy('name')->limit($limit)
            ->get(['id', 'name', 'sku', 'barcode', 'selling_price', 'cost_price', 'vat_rate'])
            ->map(fn (Product $p) => [
                'value' => $p->id,
                'label' => "{$p->name} ({$p->sku})",
                'description' => $p->barcode ? "BC: {$p->barcode}" : "SKU: {$p->sku}",
                'sku' => $p->sku,
                'barcode' => $p->barcode,
                'price' => $p->selling_price,
                'vat_rate' => $p->vat_rate ?? 0,
            ])->values();
    }

    public function findOrFail(int $id): Product
    {
        return Product::with(['category', 'brand', 'uom', 'variants', 'suppliers.partner', 'bomComponents.component', 'images', 'stocks.warehouse', 'creator'])->findOrFail($id);
    }

    public function findBySku(string $sku): ?Product
    {
        return Product::where('sku', $sku)->orWhere('barcode', $sku)->first();
    }

    public function create(array $data): Product
    {
        return DB::transaction(function () use ($data): Product {
            $productData = $this->extractProductData($data);
            if (empty($productData['sku'])) {
                $productData['sku'] = app(\Modules\INVENTORY\Services\ProductCodeGeneratorService::class)->generate();
            }
            if (empty($productData['slug']) && ! empty($productData['name'])) {
                $productData['slug'] = Str::slug($productData['name']).'-'.strtolower($productData['sku']);
            }
            $productData['created_by'] = auth()->id();
            $product = Product::create($productData);
            $this->syncRelations($product, $data);
            return $product->load(['category', 'brand', 'uom']);
        });
    }

    public function update(Product $product, array $data): Product
    {
        return DB::transaction(function () use ($product, $data): Product {
            $productData = $this->extractProductData($data);
            $productData['updated_by'] = auth()->id();
            $product->update($productData);
            $this->syncRelations($product, $data, true);
            return $product->load(['category', 'brand', 'uom', 'variants', 'suppliers', 'images']);
        });
    }

    public function delete(Product $product): void
    {
        if ($product->stocks()->where('qty_on_hand', '>', 0)->exists()) {
            throw new \DomainException('Cannot delete product with on-hand stock. Adjust stock to zero first.');
        }
        if ($product->moves()->exists()) {
            throw new \DomainException('Cannot delete product with stock movement history. Set status to discontinued instead.');
        }
        $product->delete();
    }

    private function baseQuery(): Builder
    {
        return Product::query()->with(['category:id,name', 'brand:id,name', 'uom:id,name,symbol', 'stocks'])->withSum('stocks as total_stock_sum', 'qty_on_hand');
    }

    private function searchColumns(): array { return ['sku', 'barcode', 'name', 'slug']; }
    private function sortable(): array
    {
        return [
            'sku' => 'sku', 'name' => 'name', 'category_id' => 'category_id', 'brand_id' => 'brand_id',
            'cost_price' => 'cost_price', 'selling_price' => 'selling_price', 'reorder_level' => 'reorder_level',
            'created_at' => 'created_at', 'type' => 'type', 'status' => 'status',
        ];
    }

    private function filters(): array
    {
        return [
            'category_id' => fn (Builder $q, string $v) => $q->where('category_id', $v),
            'brand_id' => fn (Builder $q, string $v) => $q->where('brand_id', $v),
            'type' => fn (Builder $q, string $v) => $q->where('type', $v),
            'status' => fn (Builder $q, string $v) => $q->where('status', $v),
            'is_active' => fn (Builder $q, string $v) => $q->where('is_active', $v === '1' || $v === 'true'),
            'stock_status' => function (Builder $q, string $v): void {
                if ($v === 'out') $q->whereHas('stocks', fn (Builder $qq) => $qq->where('qty_on_hand', '<=', 0))->orWhereDoesntHave('stocks');
                elseif ($v === 'low') $q->whereHas('stocks', fn (Builder $qq) => $qq->whereColumn('inventory_stocks.qty_on_hand', '<=', 'inventory_products.reorder_level'));
            },
        ];
    }

    private function extractProductData(array $data): array
    {
        return collect($data)->only([
            'name', 'sku', 'barcode', 'slug', 'category_id', 'brand_id', 'uom_id', 'type', 'description', 'short_description',
            'hs_code', 'weight', 'weight_uom', 'dimensions', 'is_serialized', 'is_batch_tracked', 'shelf_life_days',
            'reorder_level', 'reorder_qty', 'valuation_method', 'cost_price', 'selling_price', 'mrp', 'vat_rate', 'currency',
            'image', 'is_active', 'is_featured', 'status',
        ])->toArray();
    }

    private function syncRelations(Product $product, array $data, bool $isUpdate = false): void
    {
        if ($isUpdate) {
            if (array_key_exists('variants', $data)) {
                $product->variants()->delete();
                foreach ($data['variants'] ?? [] as $v) { if (empty(array_filter($v))) continue; $product->variants()->create($v); }
            }
            if (array_key_exists('suppliers', $data)) {
                $product->suppliers()->delete();
                foreach ($data['suppliers'] ?? [] as $s) { if (empty($s['partner_id'])) continue; $product->suppliers()->create($s); }
            }
            if (array_key_exists('bom', $data)) {
                $product->bomComponents()->delete();
                foreach ($data['bom'] ?? [] as $b) { if (empty($b['component_product_id'])) continue; $product->bomComponents()->create($b); }
            }
            if (array_key_exists('images', $data) && is_array($data['images'])) {
                // handled via controller upload — keep existing unless replaced
            }
            if (isset($data['image']) && $data['image'] instanceof \Illuminate\Http\UploadedFile) {
                $path = $data['image']->store('products', 'public');
                $product->update(['image' => $path]);
            }
        } else {
            foreach ($data['variants'] ?? [] as $v) { if (empty(array_filter($v))) continue; $product->variants()->create($v); }
            foreach ($data['suppliers'] ?? [] as $s) { if (empty($s['partner_id'])) continue; $product->suppliers()->create($s); }
            foreach ($data['bom'] ?? [] as $b) { if (empty($b['component_product_id'])) continue; $product->bomComponents()->create($b); }
            if (isset($data['image']) && $data['image'] instanceof \Illuminate\Http\UploadedFile) {
                $path = $data['image']->store('products', 'public');
                $product->update(['image' => $path]);
            }
            foreach ($data['images'] ?? [] as $file) {
                if ($file instanceof \Illuminate\Http\UploadedFile) {
                    $path = $file->store('products/gallery', 'public');
                    $product->images()->create(['file_path' => $path, 'file_name' => $file->getClientOriginalName()]);
                }
            }
        }
    }
}
