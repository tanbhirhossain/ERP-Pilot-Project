<?php

namespace Modules\SALES\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Modules\CORE\Repositories\Concerns\BuildsDatatableQueries;
use Modules\CORE\Support\DataTableQuery;
use Modules\SALES\Interfaces\DeliveryRepositoryInterface;
use Modules\SALES\Models\Delivery;

class DeliveryRepository implements DeliveryRepositoryInterface
{
    use BuildsDatatableQueries;

    public function paginate(DataTableQuery $dt): LengthAwarePaginator
    {
        $q = $this->baseQuery();
        $this->applyDataTable($q, $dt, $this->searchable(), $this->sortable(), $this->filters());

        return $q->paginate($dt->perPage, ['*'], 'page', $dt->page)->withQueryString();
    }

    public function exportBuilder(DataTableQuery $dt): Builder
    {
        $q = $this->baseQuery();
        $this->applyDataTable($q, $dt, $this->searchable(), $this->sortable(), $this->filters());

        return $q;
    }

    public function findOrFail(int $id): Delivery
    {
        return Delivery::with(['customer', 'warehouse', 'order', 'items.product'])->findOrFail($id);
    }

    /**
     * Customer name is searched through a correlated subquery rather than a join
     * so the eager-loaded relations and the `select` stay unambiguous.
     */
    private function baseQuery(): Builder
    {
        return Delivery::query()
            ->leftJoin('partners', 'partners.id', '=', 'sales_deliveries.customer_id')
            ->with(['customer', 'warehouse', 'order'])
            ->withCount('items')
            ->select('sales_deliveries.*');
    }

    /** @return list<string> */
    private function searchable(): array
    {
        return [
            'sales_deliveries.delivery_no',
            'sales_deliveries.challan_no',
            'sales_deliveries.vehicle_no',
            'partners.name',
        ];
    }

    /** @return array<string, string> */
    private function sortable(): array
    {
        return [
            'delivery_no' => 'sales_deliveries.delivery_no',
            'challan_no' => 'sales_deliveries.challan_no',
            'delivery_date' => 'sales_deliveries.delivery_date',
            'status' => 'sales_deliveries.status',
            'created_at' => 'sales_deliveries.created_at',
        ];
    }

    /** @return array<string, \Closure(Builder, string): void> */
    private function filters(): array
    {
        return [
            'status' => fn (Builder $q, string $v) => $q->where('sales_deliveries.status', $v),
            'warehouse_id' => fn (Builder $q, string $v) => $q->where('sales_deliveries.warehouse_id', $v),
        ];
    }
}
