<?php

namespace Modules\SALES\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\CORE\Repositories\Concerns\BuildsDatatableQueries;
use Modules\CORE\Support\DataTableQuery;
use Modules\SALES\Interfaces\OrderRepositoryInterface;
use Modules\SALES\Models\Order;

class OrderRepository implements OrderRepositoryInterface
{
    use BuildsDatatableQueries;

    public function paginate(DataTableQuery $dt): LengthAwarePaginator
    {
        $q = $this->baseQuery();
        $this->applyDataTable($q, $dt, ['sales_orders.order_no', 'partners.name'], $this->sortable(), $this->filters());

        return $q->paginate($dt->perPage, ['*'], 'page', $dt->page)->withQueryString();
    }

    public function exportBuilder(DataTableQuery $dt): Builder
    {
        $q = $this->baseQuery();
        $this->applyDataTable($q, $dt, ['sales_orders.order_no'], $this->sortable(), $this->filters());

        return $q;
    }

    public function findOrFail(int $id): Order
    {
        return Order::with(['customer', 'warehouse', 'quotation', 'items.product', 'invoices'])->findOrFail($id);
    }

    public function create(array $data): Order
    {
        return DB::transaction(function () use ($data): Order {
            $items = $data['items'] ?? [];
            unset($data['items']);
            $data['order_no'] = $this->nextNo();
            $data['status'] = $data['status'] ?? 'draft';
            $data['payment_status'] = $data['payment_status'] ?? 'unpaid';
            $data['created_by'] = auth()->id();
            $o = Order::create($data);
            foreach ($items as $it) {
                $o->items()->create($it);
            }

            return $o->load('items');
        });
    }

    public function update(Order $o, array $data): Order
    {
        if (! in_array($o->status, ['draft'])) {
            throw new \DomainException('Only draft orders can be edited.');
        }

        return DB::transaction(function () use ($o, $data): Order {
            $items = $data['items'] ?? null;
            unset($data['items']);
            if ($items !== null) {
                $o->items()->delete();
                foreach ($items as $it) {
                    $o->items()->create($it);
                }
            }
            $o->update($data);

            return $o->load('items');
        });
    }

    public function delete(Order $o): void
    {
        if ($o->invoices()->exists()) {
            throw new \DomainException('Order with invoices cannot be deleted.');
        } $o->items()->delete();
        $o->delete();
    }

    public function nextNo(): string
    {
        $pad = (int) config('sales.code.pad', 5);
        $pref = config('sales.code.order_prefix', 'SO-');

        return DB::transaction(function () use ($pref, $pad): string {
            $last = Order::where('order_no', 'like', $pref.'%')->lockForUpdate()->orderByDesc('id')->value('order_no');
            $n = $last ? ((int) substr($last, strlen($pref)) + 1) : 1;

            return $pref.str_pad((string) $n, $pad, '0', STR_PAD_LEFT);
        });
    }

    private function baseQuery(): Builder
    {
        return Order::query()->leftJoin('partners', 'partners.id', '=', 'sales_orders.customer_id')->with(['customer', 'warehouse'])->withCount(['items', 'invoices'])->select('sales_orders.*');
    }

    private function sortable(): array
    {
        return ['order_no' => 'sales_orders.order_no', 'order_date' => 'sales_orders.order_date', 'status' => 'sales_orders.status', 'created_at' => 'sales_orders.created_at'];
    }

    private function filters(): array
    {
        return ['status' => fn (Builder $q, string $v) => $q->where('sales_orders.status', $v), 'payment_status' => fn (Builder $q, string $v) => $q->where('sales_orders.payment_status', $v)];
    }
}
