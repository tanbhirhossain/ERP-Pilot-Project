<?php

namespace Modules\INVENTORY\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Modules\CORE\Repositories\Concerns\BuildsDatatableQueries;
use Modules\CORE\Support\DataTableQuery;
use Modules\INVENTORY\Interfaces\BrandRepositoryInterface;
use Modules\INVENTORY\Models\Brand;

class BrandRepository implements BrandRepositoryInterface
{
    use BuildsDatatableQueries;

    public function paginate(DataTableQuery $dt): LengthAwarePaginator
    {
        $q = Brand::query()->withCount('products');
        $this->applyDataTable($q, $dt, ['name', 'code'], ['name' => 'name', 'code' => 'code', 'created_at' => 'created_at'], ['is_active' => fn (Builder $qq, string $v) => $qq->where('is_active', $v === '1' || $v === 'true')]);
        return $q->paginate($dt->perPage, ['*'], 'page', $dt->page)->withQueryString();
    }

    public function exportBuilder(DataTableQuery $dt): Builder
    {
        $q = Brand::query();
        $this->applyDataTable($q, $dt, ['name', 'code'], ['name' => 'name'], []);
        return $q;
    }

    public function options(?string $search = null, int $limit = 100): Collection
    {
        return Brand::query()->active()
            ->when($search, fn (Builder $q, string $t) => $q->where('name', 'like', '%'.addcslashes($t, '%_').'%'))
            ->orderBy('name')->limit($limit)
            ->get(['id', 'name', 'code'])
            ->map(fn (Brand $b) => ['value' => $b->id, 'label' => $b->name . ($b->code ? " ({$b->code})" : '')])->values();
    }

    public function findOrFail(int $id): Brand { return Brand::withCount('products')->findOrFail($id); }

    public function create(array $data): Brand
    {
        if (empty($data['slug']) && ! empty($data['name'])) $data['slug'] = Str::slug($data['name']);
        return Brand::create($data);
    }

    public function update(Brand $brand, array $data): Brand { $brand->update($data); return $brand; }

    public function delete(Brand $brand): void
    {
        if ($brand->products()->exists()) throw new \DomainException('Cannot delete brand with products.');
        $brand->delete();
    }
}
