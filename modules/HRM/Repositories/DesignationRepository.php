<?php

namespace Modules\HRM\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\CORE\Repositories\Concerns\BuildsDatatableQueries;
use Modules\CORE\Support\DataTableQuery;
use Modules\HRM\Interfaces\DesignationRepositoryInterface;
use Modules\HRM\Models\Designation;

class DesignationRepository implements DesignationRepositoryInterface
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
        return Designation::query()->when($search, fn (Builder $q, $t) => $q->where('name', 'like', '%'.addcslashes($t, '%_').'%'))->orderBy('name')->limit($limit)->get(['id', 'name', 'code'])->map(fn ($d) => ['value' => $d->id, 'label' => $d->name, 'description' => $d->code])->values();
    }

    public function findOrFail(int $id): Designation
    {
        return Designation::with('department')->findOrFail($id);
    }

    public function create(array $data): Designation
    {
        return DB::transaction(fn () => Designation::create($data));
    }

    public function update(Designation $d, array $data): Designation
    {
        return DB::transaction(function () use ($d, $data) {
            $d->fill($data);
            $d->save();

            return $d;
        });
    }

    public function delete(Designation $d): void
    {
        DB::transaction(fn () => $d->delete());
    }

    private function baseQuery(): Builder
    {
        return Designation::query()->with(['department:id,name']);
    }

    private function searchColumns(): array
    {
        return ['name', 'code', 'grade'];
    }

    private function sortable(): array
    {
        return ['id' => 'id', 'name' => 'name', 'code' => 'code', 'level' => 'level', 'created_at' => 'created_at'];
    }

    private function filters(): array
    {
        return ['department_id' => fn (Builder $q, string $v) => $q->where('department_id', $v), 'is_active' => fn (Builder $q, string $v) => $q->where('is_active', $v === '1')];
    }
}
