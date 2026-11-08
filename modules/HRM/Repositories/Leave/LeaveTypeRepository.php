<?php

namespace Modules\HRM\Repositories\Leave;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\CORE\Repositories\Concerns\BuildsDatatableQueries;
use Modules\CORE\Support\DataTableQuery;
use Modules\HRM\Interfaces\Leave\LeaveTypeRepositoryInterface;
use Modules\HRM\Models\Leave\LeaveType;

class LeaveTypeRepository implements LeaveTypeRepositoryInterface
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

    public function findOrFail(int $id): LeaveType
    {
        return LeaveType::findOrFail($id);
    }

    public function create(array $data): LeaveType
    {
        return DB::transaction(fn () => LeaveType::create($data));
    }

    public function update(LeaveType $type, array $data): LeaveType
    {
        return DB::transaction(function () use ($type, $data) {
            $type->fill($data);
            $type->save();

            return $type;
        });
    }

    public function delete(LeaveType $type): void
    {
        DB::transaction(fn () => $type->delete());
    }

    public function options(): Collection
    {
        return LeaveType::where('is_active', true)->orderBy('name')->get(['id', 'name', 'code', 'color'])->map(fn ($t) => ['value' => $t->id, 'label' => $t->code ? $t->name.' ('.$t->code.')' : $t->name, 'color' => $t->color]);
    }

    private function baseQuery(): Builder
    {
        return LeaveType::query();
    }

    private function searchColumns(): array
    {
        return ['name', 'code', 'description'];
    }

    private function sortable(): array
    {
        return ['id' => 'id', 'name' => 'name', 'code' => 'code', 'days_per_year' => 'days_per_year', 'created_at' => 'created_at'];
    }

    private function filters(): array
    {
        return ['is_active' => fn (Builder $q, string $v) => $q->where('is_active', $v), 'is_paid' => fn (Builder $q, string $v) => $q->where('is_paid', $v)];
    }
}
