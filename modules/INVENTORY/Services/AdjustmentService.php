<?php

namespace Modules\INVENTORY\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\CORE\Support\DataTableQuery;
use Modules\INVENTORY\DTO\StockMoveData;
use Modules\INVENTORY\Interfaces\AdjustmentRepositoryInterface;
use Modules\INVENTORY\Models\Adjustment;

class AdjustmentService
{
    public function __construct(private readonly AdjustmentRepositoryInterface $repo, private readonly StockService $stockService) {}

    public function paginate(DataTableQuery $dt): LengthAwarePaginator
    {
        return $this->repo->paginate($dt);
    }

    public function exportBuilder(DataTableQuery $dt): Builder
    {
        return $this->repo->exportBuilder($dt);
    }

    public function findOrFail(int $id): Adjustment
    {
        return $this->repo->findOrFail($id);
    }

    public function create(array $data): Adjustment
    {
        return $this->repo->create($data);
    }

    public function update(Adjustment $a, array $data): Adjustment
    {
        return $this->repo->update($a, $data);
    }

    public function delete(Adjustment $a): void
    {
        $this->repo->delete($a);
    }

    public function approve(Adjustment $adj): Adjustment
    {
        if (! $adj->canApprove()) {
            throw new \DomainException('Cannot approve in status: '.$adj->status);
        }
        $adj->update(['status' => 'approved', 'approved_by' => auth()->id(), 'approved_at' => now()]);

        return $adj;
    }

    public function post(Adjustment $adj): Adjustment
    {
        if (! $adj->canPost()) {
            throw new \DomainException('Adjustment must be approved before posting. Current: '.$adj->status);
        }

        return DB::transaction(function () use ($adj): Adjustment {
            foreach ($adj->items as $item) {
                $isIncrease = $adj->type === 'increase' || $adj->type === 'found';
                $direction = ($item->qty >= 0) ? ($isIncrease ? 'in' : 'out') : ($isIncrease ? 'out' : 'in');
                // qty sign handling: stored as positive for increase, negative or positive for decrease — we treat absolute
                $qty = abs((float) $item->qty);
                if ($qty == 0) {
                    continue;
                }
                $this->stockService->move(new StockMoveData(
                    productId: $item->product_id,
                    variantId: $item->variant_id,
                    warehouseId: $adj->warehouse_id,
                    locationId: $item->location_id,
                    qty: $qty,
                    direction: $direction,
                    unitCost: (float) $item->unit_cost,
                    referenceType: 'adjustment',
                    referenceNo: $adj->adjustment_no,
                    reason: $adj->reason,
                ));
            }
            $adj->update(['status' => 'posted', 'posted_by' => auth()->id(), 'posted_at' => now()]);

            return $adj;
        });
    }

    public function cancel(Adjustment $adj, ?string $reason = null): Adjustment
    {
        if (in_array($adj->status, ['posted', 'cancelled'], true)) {
            throw new \DomainException('Cannot cancel posted/cancelled adjustment.');
        }
        $adj->update(['status' => 'cancelled', 'notes' => ($adj->notes ? $adj->notes."\n" : '').'Cancelled: '.($reason ?? 'No reason')]);

        return $adj;
    }
}
