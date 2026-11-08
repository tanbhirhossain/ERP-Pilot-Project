<?php

namespace Modules\INVENTORY\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\CORE\Repositories\Concerns\BuildsDatatableQueries;
use Modules\CORE\Support\DataTableQuery;
use Modules\INVENTORY\Interfaces\AdjustmentRepositoryInterface;
use Modules\INVENTORY\Models\Adjustment;
use Modules\INVENTORY\Services\InventoryCodeGeneratorService;

class AdjustmentRepository implements AdjustmentRepositoryInterface
{
    use BuildsDatatableQueries;

    public function paginate(DataTableQuery $dt): LengthAwarePaginator
    {
        $q = $this->baseQuery();
        $this->applyDataTable($q, $dt, ['adjustment_no', 'reason'], $this->sortable(), $this->filters());

        return $q->paginate($dt->perPage, ['*'], 'page', $dt->page)->withQueryString();
    }

    public function exportBuilder(DataTableQuery $dt): Builder
    {
        $q = $this->baseQuery();
        $this->applyDataTable($q, $dt, ['adjustment_no'], $this->sortable(), $this->filters());

        return $q;
    }

    public function findOrFail(int $id): Adjustment
    {
        return Adjustment::with(['warehouse', 'items.product', 'creator', 'approver'])->findOrFail($id);
    }

    public function create(array $data): Adjustment
    {
        return DB::transaction(function () use ($data): Adjustment {
            if (empty($data['adjustment_no'])) {
                $data['adjustment_no'] = app(InventoryCodeGeneratorService::class)->generate('ADJ');
            }
            $data['created_by'] = auth()->id();
            $items = $data['items'] ?? [];
            unset($data['items']);
            $adj = Adjustment::create($data);
            foreach ($items as $it) {
                if (empty($it['product_id'])) {
                    continue;
                } $it['total_cost'] = (float) ($it['qty'] ?? 0) * (float) ($it['unit_cost'] ?? 0);
                $adj->items()->create($it);
            }

            return $adj->load(['items']);
        });
    }

    public function update(Adjustment $adjustment, array $data): Adjustment
    {
        if (! in_array($adjustment->status, ['draft', 'pending_approval'], true)) {
            throw new \DomainException('Only draft/pending adjustments can be edited.');
        }
        $items = $data['items'] ?? null;
        unset($data['items']);
        $adjustment->update($data);
        if (is_array($items)) {
            $adjustment->items()->delete();
            foreach ($items as $it) {
                if (empty($it['product_id'])) {
                    continue;
                } $it['total_cost'] = (float) ($it['qty'] ?? 0) * (float) ($it['unit_cost'] ?? 0);
                $adjustment->items()->create($it);
            }
        }

        return $adjustment->load(['items']);
    }

    public function delete(Adjustment $adjustment): void
    {
        if (! in_array($adjustment->status, ['draft', 'cancelled'], true)) {
            throw new \DomainException('Only draft/cancelled adjustments can be deleted.');
        }
        $adjustment->delete();
    }

    private function baseQuery(): Builder
    {
        return Adjustment::query()->with(['warehouse:id,name,code', 'creator:id,name'])->withCount('items')->withSum('items as total_items_qty', 'qty');
    }

    private function sortable(): array
    {
        return ['adjustment_no' => 'adjustment_no', 'type' => 'type', 'status' => 'status', 'created_at' => 'created_at'];
    }

    private function filters(): array
    {
        return ['type' => fn (Builder $q, string $v) => $q->where('type', $v), 'status' => fn (Builder $q, string $v) => $q->where('status', $v), 'warehouse_id' => fn (Builder $q, string $v) => $q->where('warehouse_id', $v)];
    }
}
