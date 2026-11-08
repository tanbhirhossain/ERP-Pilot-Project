<?php

namespace Modules\PURCHASE\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\CORE\Repositories\Concerns\BuildsDatatableQueries;
use Modules\CORE\Support\DataTableQuery;
use Modules\PURCHASE\Interfaces\PurchaseOrderRepositoryInterface;
use Modules\PURCHASE\Models\PurchaseOrder;

class PurchaseOrderRepository implements PurchaseOrderRepositoryInterface
{
    use BuildsDatatableQueries;

    public function paginate(DataTableQuery $dt): LengthAwarePaginator
    {
        $q = $this->baseQuery();
        $this->applyDataTable($q, $dt, ['purchase_orders.order_no'], $this->sortable(), $this->filters());

        return $q->paginate($dt->perPage, ['*'], 'page', $dt->page)->withQueryString();
    }

    public function exportBuilder(DataTableQuery $dt): Builder
    {
        $q = $this->baseQuery();
        $this->applyDataTable($q, $dt, ['purchase_orders.order_no'], $this->sortable(), $this->filters());

        return $q;
    }

    public function findOrFail(int $id): PurchaseOrder
    {
        return PurchaseOrder::with(['vendor', 'warehouse', 'items.product'])->findOrFail($id);
    }

    public function create(array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($data): PurchaseOrder {
            $items = $data['items'] ?? [];
            unset($data['items']);
            $data['order_no'] = $this->nextNo();
            $data['status'] = $data['status'] ?? 'draft';
            $data['created_by'] = auth()->id();
            $order = PurchaseOrder::create($data);
            foreach ($items as $it) {
                $order->items()->create($it);
            }

            return $order->load('items');
        });
    }

    public function update(PurchaseOrder $order, array $data): PurchaseOrder
    {
        if (! $order->isDraft()) {
            throw new \DomainException('Only draft can be edited.');
        }

        return DB::transaction(function () use ($order, $data): PurchaseOrder {
            $items = $data['items'] ?? null;
            unset($data['items']);
            if ($items !== null) {
                $order->items()->delete();
                foreach ($items as $it) {
                    $order->items()->create($it);
                }
            }
            $order->update($data);

            return $order->load('items');
        });
    }

    public function delete(PurchaseOrder $order): void
    {
        if (! $order->isDraft()) {
            throw new \DomainException('Only draft can be deleted.');
        } $order->items()->delete();
        $order->delete();
    }

    public function nextNo(): string
    {
        $pad = (int) config('purchase.code.pad', 5);
        $pref = config('purchase.code.order_prefix', 'PO-');

        return DB::transaction(function () use ($pref, $pad): string {
            $last = PurchaseOrder::where('order_no', 'like', $pref.'%')->lockForUpdate()->orderByDesc('id')->value('order_no');
            $n = $last ? ((int) substr($last, strlen($pref)) + 1) : 1;

            return $pref.str_pad((string) $n, $pad, '0', STR_PAD_LEFT);
        });
    }

    private function baseQuery(): Builder
    {
        return PurchaseOrder::query()->leftJoin('partners', 'partners.id', '=', 'purchase_orders.vendor_id')->with(['vendor', 'warehouse'])->select('purchase_orders.*');
    }

    private function sortable(): array
    {
        return ['order_no' => 'purchase_orders.order_no', 'status' => 'purchase_orders.status', 'created_at' => 'purchase_orders.created_at'];
    }

    private function filters(): array
    {
        return ['status' => fn (Builder $q, string $v) => $q->where('purchase_orders.status', $v)];
    }
}
