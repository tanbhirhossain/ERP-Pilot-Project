<?php

namespace Modules\PARTNER\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\CORE\Repositories\Concerns\BuildsDatatableQueries;
use Modules\CORE\Support\DataTableQuery;
use Modules\PARTNER\Interfaces\PartnerGroupRepositoryInterface;
use Modules\PARTNER\Models\PartnerGroup;

class PartnerGroupRepository implements PartnerGroupRepositoryInterface
{
    use BuildsDatatableQueries;

    public function paginate(DataTableQuery $dt): LengthAwarePaginator
    {
        $q = $this->baseQuery();
        $this->applyDataTable($q, $dt, ['name', 'code', 'description'], $this->sortable(), $this->filters());
        return $q->paginate($dt->perPage, ['*'], 'page', $dt->page)->withQueryString();
    }

    public function exportBuilder(DataTableQuery $dt): Builder
    {
        $q = $this->baseQuery();
        $this->applyDataTable($q, $dt, ['name', 'code', 'description'], $this->sortable(), $this->filters());
        return $q;
    }

    public function options(?string $search = null, int $limit = 100, ?string $type = null): Collection
    {
        return PartnerGroup::query()
            ->active()
            ->when($type, fn (Builder $q) => $q->ofType($type))
            ->when($search, function (Builder $q, string $term): void {
                $needle = '%'.addcslashes($term, '%_').'%';
                $q->where(fn (Builder $g) => $g->where('name', 'like', $needle)->orWhere('code', 'like', $needle));
            })
            ->orderBy('sort_order')->orderBy('name')
            ->limit($limit)
            ->get(['id', 'name', 'code', 'type', 'parent_id'])
            ->map(fn (PartnerGroup $g) => [
                'value' => $g->id,
                'label' => $g->name . ($g->code ? " ({$g->code})" : ''),
                'description' => $g->type,
                'type' => $g->type,
            ])->values();
    }

    public function tree(?string $type = null): Collection
    {
        return PartnerGroup::with('children')
            ->whereNull('parent_id')
            ->when($type, fn (Builder $q) => $q->ofType($type))
            ->active()
            ->orderBy('sort_order')->orderBy('name')
            ->get();
    }

    public function findOrFail(int $id): PartnerGroup
    {
        return PartnerGroup::with(['parent', 'children', 'partners:id,group_id,name'])->findOrFail($id);
    }

    public function create(array $data): PartnerGroup
    {
        return DB::transaction(function () use ($data): PartnerGroup {
            if (empty($data['code']) && ! empty($data['name'])) {
                $data['code'] = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $data['name']), 0, 6)) . '-' . str_pad((string) (PartnerGroup::count() + 1), 3, '0', STR_PAD_LEFT);
            }
            return PartnerGroup::create($data);
        });
    }

    public function update(PartnerGroup $group, array $data): PartnerGroup
    {
        $group->update($data);
        return $group;
    }

    public function delete(PartnerGroup $group): void
    {
        // Guard: cannot delete group with children or partners
        if ($group->children()->exists()) {
            throw new \DomainException('Cannot delete group with child groups. Move or delete children first.');
        }
        if ($group->partners()->exists()) {
            throw new \DomainException('Cannot delete group with assigned partners. Reassign partners first.');
        }
        $group->delete();
    }

    private function baseQuery(): Builder
    {
        return PartnerGroup::query()->with(['parent:id,name', 'children:id,parent_id,name'])->withCount('partners');
    }

    private function sortable(): array
    {
        return ['name' => 'name', 'code' => 'code', 'type' => 'type', 'sort_order' => 'sort_order', 'created_at' => 'created_at'];
    }

    private function filters(): array
    {
        return [
            'type' => fn (Builder $q, string $v) => $q->where('type', $v),
            'parent_id' => fn (Builder $q, string $v) => $q->where('parent_id', $v),
            'is_active' => fn (Builder $q, string $v) => $q->where('is_active', $v === '1' || $v === 'true'),
        ];
    }
}
