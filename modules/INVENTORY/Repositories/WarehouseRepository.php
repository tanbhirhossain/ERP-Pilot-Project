<?php

namespace Modules\INVENTORY\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\CORE\Repositories\Concerns\BuildsDatatableQueries;
use Modules\CORE\Support\DataTableQuery;
use Modules\INVENTORY\Interfaces\WarehouseRepositoryInterface;
use Modules\INVENTORY\Models\Warehouse;

class WarehouseRepository implements WarehouseRepositoryInterface
{
    use BuildsDatatableQueries;

    public function paginate(DataTableQuery $dt): LengthAwarePaginator
    {
        $q = $this->baseQuery();
        $this->applyDataTable($q, $dt, ['name', 'code', 'city', 'district'], $this->sortable(), $this->filters());

        return $q->paginate($dt->perPage, ['*'], 'page', $dt->page)->withQueryString();
    }

    public function exportBuilder(DataTableQuery $dt): Builder
    {
        $q = $this->baseQuery();
        $this->applyDataTable($q, $dt, ['name', 'code', 'city'], $this->sortable(), $this->filters());

        return $q;
    }

    public function options(?string $search = null, int $limit = 100): Collection
    {
        return Warehouse::query()->active()
            ->when($search, function (Builder $q, string $term): void {
                $needle = '%'.addcslashes($term, '%_').'%';
                $q->where(fn (Builder $g) => $g->where('name', 'like', $needle)->orWhere('code', 'like', $needle));
            })
            ->orderBy('is_default', 'desc')->orderBy('name')->limit($limit)
            ->get(['id', 'name', 'code', 'city', 'is_default'])
            ->map(fn (Warehouse $w) => [
                'value' => $w->id,
                'label' => $w->name,
                'description' => $w->is_default ? 'Default warehouse' : ($w->city ?: null),
                'meta' => $w->code,
                'is_default' => $w->is_default,
            ])->values();
    }

    public function findOrFail(int $id): Warehouse
    {
        return Warehouse::with(['manager', 'locations', 'stocks'])->findOrFail($id);
    }

    public function create(array $data): Warehouse
    {
        return DB::transaction(function () use ($data): Warehouse {
            if (! empty($data['is_default']) && $data['is_default']) {
                Warehouse::query()->update(['is_default' => false]);
            }

            return Warehouse::create($data);
        });
    }

    public function update(Warehouse $warehouse, array $data): Warehouse
    {
        return DB::transaction(function () use ($warehouse, $data): Warehouse {
            if (! empty($data['is_default']) && $data['is_default']) {
                Warehouse::where('id', '!=', $warehouse->id)->update(['is_default' => false]);
            }
            $warehouse->update($data);

            return $warehouse;
        });
    }

    public function delete(Warehouse $warehouse): void
    {
        if ($warehouse->stocks()->where('qty_on_hand', '>', 0)->exists()) {
            throw new \DomainException('Cannot delete warehouse with stock.');
        }
        if ($warehouse->locations()->exists()) {
            throw new \DomainException('Cannot delete warehouse with locations. Delete locations first.');
        }
        $warehouse->delete();
    }

    private function baseQuery(): Builder
    {
        return Warehouse::query()->with(['manager:id,name'])->withCount(['locations', 'stocks']);
    }

    private function sortable(): array
    {
        return ['name' => 'name', 'code' => 'code', 'city' => 'city', 'created_at' => 'created_at', 'type' => 'type'];
    }

    private function filters(): array
    {
        return ['type' => fn (Builder $q, string $v) => $q->where('type', $v), 'is_active' => fn (Builder $q, string $v) => $q->where('is_active', $v === '1' || $v === 'true')];
    }
}
