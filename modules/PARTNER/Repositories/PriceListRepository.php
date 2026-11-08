<?php

namespace Modules\PARTNER\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\CORE\Repositories\Concerns\BuildsDatatableQueries;
use Modules\CORE\Support\DataTableQuery;
use Modules\PARTNER\Interfaces\PriceListRepositoryInterface;
use Modules\PARTNER\Models\PriceList;

class PriceListRepository implements PriceListRepositoryInterface
{
    use BuildsDatatableQueries;

    public function paginate(DataTableQuery $dt): LengthAwarePaginator
    {
        $q = $this->baseQuery();
        $this->applyDataTable($q, $dt, ['name', 'code', 'currency'], $this->sortable(), $this->filters());

        return $q->paginate($dt->perPage, ['*'], 'page', $dt->page)->withQueryString();
    }

    public function exportBuilder(DataTableQuery $dt): Builder
    {
        $q = $this->baseQuery();
        $this->applyDataTable($q, $dt, ['name', 'code', 'currency'], $this->sortable(), $this->filters());

        return $q;
    }

    public function options(?string $search = null, int $limit = 100): Collection
    {
        return PriceList::query()
            ->active()
            ->when($search, function (Builder $q, string $term): void {
                $needle = '%'.addcslashes($term, '%_').'%';
                $q->where(fn (Builder $g) => $g->where('name', 'like', $needle)->orWhere('code', 'like', $needle));
            })
            ->orderBy('is_default', 'desc')->orderBy('name')
            ->limit($limit)
            ->get(['id', 'name', 'code', 'currency', 'is_default'])
            ->map(fn (PriceList $p) => [
                'value' => $p->id,
                'label' => "{$p->name} ({$p->code})",
                'description' => $p->currency.($p->is_default ? ' — Default' : ''),
            ])->values();
    }

    public function findOrFail(int $id): PriceList
    {
        return PriceList::with(['items'])->findOrFail($id);
    }

    public function create(array $data): PriceList
    {
        return DB::transaction(function () use ($data): PriceList {
            if (! empty($data['is_default']) && $data['is_default']) {
                PriceList::query()->update(['is_default' => false]);
            }
            $items = $data['items'] ?? [];
            unset($data['items']);
            $pl = PriceList::create($data);
            foreach ($items as $item) {
                if (empty($item['unit_price'])) {
                    continue;
                }
                $pl->items()->create($item);
            }

            return $pl->load('items');
        });
    }

    public function update(PriceList $priceList, array $data): PriceList
    {
        return DB::transaction(function () use ($priceList, $data): PriceList {
            if (! empty($data['is_default']) && $data['is_default']) {
                PriceList::where('id', '!=', $priceList->id)->update(['is_default' => false]);
            }
            $items = $data['items'] ?? null;
            unset($data['items']);
            $priceList->update($data);
            if (is_array($items)) {
                $priceList->items()->delete();
                foreach ($items as $item) {
                    if (empty($item['unit_price'])) {
                        continue;
                    }
                    $priceList->items()->create($item);
                }
            }

            return $priceList->load('items');
        });
    }

    public function delete(PriceList $priceList): void
    {
        if ($priceList->partners()->exists()) {
            throw new \DomainException('Cannot delete price list assigned to partners. Reassign partners first.');
        }
        $priceList->delete();
    }

    private function baseQuery(): Builder
    {
        return PriceList::query()->withCount(['items', 'partners']);
    }

    private function sortable(): array
    {
        return ['name' => 'name', 'code' => 'code', 'currency' => 'currency', 'is_default' => 'is_default', 'created_at' => 'created_at'];
    }

    private function filters(): array
    {
        return [
            'is_active' => fn (Builder $q, string $v) => $q->where('is_active', $v === '1' || $v === 'true'),
            'currency' => fn (Builder $q, string $v) => $q->where('currency', $v),
        ];
    }
}
