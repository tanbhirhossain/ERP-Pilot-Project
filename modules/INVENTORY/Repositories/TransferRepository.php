<?php

namespace Modules\INVENTORY\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\CORE\Repositories\Concerns\BuildsDatatableQueries;
use Modules\CORE\Support\DataTableQuery;
use Modules\INVENTORY\Interfaces\TransferRepositoryInterface;
use Modules\INVENTORY\Models\Transfer;
use Modules\INVENTORY\Services\InventoryCodeGeneratorService;

class TransferRepository implements TransferRepositoryInterface
{
    use BuildsDatatableQueries;

    public function paginate(DataTableQuery $dt): LengthAwarePaginator
    {
        $q = $this->baseQuery();
        $this->applyDataTable($q, $dt, ['transfer_no'], $this->sortable(), $this->filters());

        return $q->paginate($dt->perPage, ['*'], 'page', $dt->page)->withQueryString();
    }

    public function exportBuilder(DataTableQuery $dt): Builder
    {
        $q = $this->baseQuery();
        $this->applyDataTable($q, $dt, ['transfer_no'], $this->sortable(), $this->filters());

        return $q;
    }

    public function findOrFail(int $id): Transfer
    {
        return Transfer::with(['fromWarehouse', 'toWarehouse', 'items.product', 'requester', 'approver'])->findOrFail($id);
    }

    public function create(array $data): Transfer
    {
        return DB::transaction(function () use ($data): Transfer {
            if (empty($data['transfer_no'])) {
                $data['transfer_no'] = app(InventoryCodeGeneratorService::class)->generate('TRF');
            }
            $data['requested_by'] = auth()->id();
            $items = $data['items'] ?? [];
            unset($data['items']);
            $transfer = Transfer::create($data);
            foreach ($items as $it) {
                if (empty($it['product_id'])) {
                    continue;
                } $transfer->items()->create($it);
            }

            return $transfer->load(['items']);
        });
    }

    public function update(Transfer $transfer, array $data): Transfer
    {
        if (! in_array($transfer->status, ['draft', 'pending'], true)) {
            throw new \DomainException('Only draft/pending transfers can be edited.');
        }
        $items = $data['items'] ?? null;
        unset($data['items']);
        $transfer->update($data);
        if (is_array($items)) {
            $transfer->items()->delete();
            foreach ($items as $it) {
                if (empty($it['product_id'])) {
                    continue;
                } $transfer->items()->create($it);
            }
        }

        return $transfer->load(['items']);
    }

    public function delete(Transfer $transfer): void
    {
        if (! in_array($transfer->status, ['draft', 'cancelled'], true)) {
            throw new \DomainException('Only draft/cancelled transfers can be deleted.');
        }
        $transfer->delete();
    }

    private function baseQuery(): Builder
    {
        return Transfer::query()->with(['fromWarehouse:id,name,code', 'toWarehouse:id,name,code', 'requester:id,name'])->withCount('items');
    }

    private function sortable(): array
    {
        return ['transfer_no' => 'transfer_no', 'status' => 'status', 'created_at' => 'created_at'];
    }

    private function filters(): array
    {
        return ['status' => fn (Builder $q, string $v) => $q->where('status', $v), 'from_warehouse_id' => fn (Builder $q, string $v) => $q->where('from_warehouse_id', $v), 'to_warehouse_id' => fn (Builder $q, string $v) => $q->where('to_warehouse_id', $v)];
    }
}
