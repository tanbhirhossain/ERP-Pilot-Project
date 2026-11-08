<?php

namespace Modules\SALES\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Modules\CORE\Repositories\Concerns\BuildsDatatableQueries;
use Modules\CORE\Support\DataTableQuery;
use Modules\SALES\Interfaces\SalesReturnRepositoryInterface;
use Modules\SALES\Models\SalesReturn;

class SalesReturnRepository implements SalesReturnRepositoryInterface
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

    public function findOrFail(int $id): SalesReturn
    {
        return SalesReturn::with(['customer', 'invoice', 'warehouse', 'items.product', 'journal'])->findOrFail($id);
    }

    private function baseQuery(): Builder
    {
        return SalesReturn::query()
            ->leftJoin('partners', 'partners.id', '=', 'sales_returns.customer_id')
            ->leftJoin('sales_invoices', 'sales_invoices.id', '=', 'sales_returns.invoice_id')
            ->with(['customer', 'invoice', 'warehouse'])
            ->withCount('items')
            ->select('sales_returns.*');
    }

    /** @return list<string> */
    private function searchable(): array
    {
        return [
            'sales_returns.return_no',
            'sales_returns.reason',
            'partners.name',
            'sales_invoices.invoice_no',
        ];
    }

    /** @return array<string, string> */
    private function sortable(): array
    {
        return [
            'return_no' => 'sales_returns.return_no',
            'return_date' => 'sales_returns.return_date',
            'total_amount' => 'sales_returns.total_amount',
            'status' => 'sales_returns.status',
            'created_at' => 'sales_returns.created_at',
        ];
    }

    /** @return array<string, \Closure(Builder, string): void> */
    private function filters(): array
    {
        return [
            'status' => fn (Builder $q, string $v) => $q->where('sales_returns.status', $v),
            'refund_method' => fn (Builder $q, string $v) => $q->where('sales_returns.refund_method', $v),
            'warehouse_id' => fn (Builder $q, string $v) => $q->where('sales_returns.warehouse_id', $v),
        ];
    }
}
