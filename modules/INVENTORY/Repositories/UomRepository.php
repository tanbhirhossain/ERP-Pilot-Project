<?php

namespace Modules\INVENTORY\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\CORE\Repositories\Concerns\BuildsDatatableQueries;
use Modules\CORE\Support\DataTableQuery;
use Modules\INVENTORY\Interfaces\UomRepositoryInterface;
use Modules\INVENTORY\Models\Uom;

class UomRepository implements UomRepositoryInterface
{
    use BuildsDatatableQueries;

    public function paginate(DataTableQuery $dt): LengthAwarePaginator
    {
        $q = Uom::query()->with('baseUom');
        $this->applyDataTable($q, $dt, ['name', 'symbol', 'code'], ['name' => 'name', 'symbol' => 'symbol', 'created_at' => 'created_at'], ['is_active' => fn (Builder $qq, string $v) => $qq->where('is_active', $v === '1' || $v === 'true')]);

        return $q->paginate($dt->perPage, ['*'], 'page', $dt->page)->withQueryString();
    }

    public function exportBuilder(DataTableQuery $dt): Builder
    {
        $q = Uom::query();
        $this->applyDataTable($q, $dt, ['name', 'symbol', 'code'], ['name' => 'name'], []);

        return $q;
    }

    public function options(?string $search = null, int $limit = 100): Collection
    {
        return Uom::query()->active()
            ->when($search, fn (Builder $q, string $t) => $q->where('name', 'like', '%'.addcslashes($t, '%_').'%')->orWhere('symbol', 'like', '%'.addcslashes($t, '%_').'%'))
            ->orderBy('name')->limit($limit)
            ->get(['id', 'name', 'symbol', 'code'])
            ->map(fn (Uom $u) => ['value' => $u->id, 'label' => "{$u->name} ({$u->symbol})"])->values();
    }

    public function findOrFail(int $id): Uom
    {
        return Uom::with('baseUom')->findOrFail($id);
    }

    public function create(array $data): Uom
    {
        return Uom::create($data);
    }

    public function update(Uom $uom, array $data): Uom
    {
        $uom->update($data);

        return $uom;
    }

    public function delete(Uom $uom): void
    {
        if ($uom->derivatives()->exists()) {
            throw new \DomainException('Cannot delete UOM with derivatives.');
        } $uom->delete();
    }
}
