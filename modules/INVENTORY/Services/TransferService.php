<?php

namespace Modules\INVENTORY\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\CORE\Support\DataTableQuery;
use Modules\INVENTORY\DTO\StockMoveData;
use Modules\INVENTORY\Interfaces\TransferRepositoryInterface;
use Modules\INVENTORY\Models\Transfer;

class TransferService
{
    public function __construct(private readonly TransferRepositoryInterface $repo, private readonly StockService $stockService) {}

    public function paginate(DataTableQuery $dt): LengthAwarePaginator
    {
        return $this->repo->paginate($dt);
    }

    public function exportBuilder(DataTableQuery $dt): Builder
    {
        return $this->repo->exportBuilder($dt);
    }

    public function findOrFail(int $id): Transfer
    {
        return $this->repo->findOrFail($id);
    }

    public function create(array $data): Transfer
    {
        return $this->repo->create($data);
    }

    public function update(Transfer $t, array $data): Transfer
    {
        return $this->repo->update($t, $data);
    }

    public function delete(Transfer $t): void
    {
        $this->repo->delete($t);
    }

    public function ship(Transfer $transfer): Transfer
    {
        if (! $transfer->canShip()) {
            throw new \DomainException('Transfer cannot be shipped in current status: '.$transfer->status);
        }

        return DB::transaction(function () use ($transfer): Transfer {
            foreach ($transfer->items as $item) {
                $this->stockService->move(new StockMoveData(
                    productId: $item->product_id,
                    variantId: $item->variant_id,
                    warehouseId: $transfer->from_warehouse_id,
                    locationId: null,
                    qty: (float) $item->qty_requested,
                    direction: 'out',
                    unitCost: (float) $item->unit_cost,
                    referenceType: 'transfer',
                    referenceNo: $transfer->transfer_no,
                    reason: "Transfer {$transfer->transfer_no} shipped to {$transfer->toWarehouse?->name}",
                ));
                $item->update(['qty_shipped' => $item->qty_requested]);
            }
            $transfer->update(['status' => 'in_transit', 'shipped_by' => auth()->id(), 'shipped_at' => now()]);

            return $transfer;
        });
    }

    public function receive(Transfer $transfer): Transfer
    {
        if (! $transfer->canReceive()) {
            throw new \DomainException('Transfer cannot be received in current status: '.$transfer->status);
        }

        return DB::transaction(function () use ($transfer): Transfer {
            foreach ($transfer->items as $item) {
                $this->stockService->move(new StockMoveData(
                    productId: $item->product_id,
                    variantId: $item->variant_id,
                    warehouseId: $transfer->to_warehouse_id,
                    locationId: null,
                    qty: (float) $item->qty_shipped ?: (float) $item->qty_requested,
                    direction: 'in',
                    unitCost: (float) $item->unit_cost,
                    referenceType: 'transfer',
                    referenceNo: $transfer->transfer_no,
                    reason: "Transfer {$transfer->transfer_no} received from {$transfer->fromWarehouse?->name}",
                ));
                $item->update(['qty_received' => $item->qty_shipped ?: $item->qty_requested]);
            }
            $transfer->update(['status' => 'received', 'received_by' => auth()->id(), 'received_at' => now()]);

            return $transfer;
        });
    }

    public function cancel(Transfer $transfer, ?string $reason = null): Transfer
    {
        if (in_array($transfer->status, ['received', 'cancelled'], true)) {
            throw new \DomainException('Cannot cancel in status: '.$transfer->status);
        }
        $transfer->update(['status' => 'cancelled', 'notes' => ($transfer->notes ? $transfer->notes."\n" : '').'Cancelled: '.($reason ?? 'No reason')]);

        return $transfer;
    }
}
