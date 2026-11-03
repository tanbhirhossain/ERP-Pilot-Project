<?php

namespace Modules\INVENTORY\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\CORE\Repositories\Concerns\BuildsDatatableQueries;
use Modules\CORE\Support\DataTableQuery;
use Modules\INVENTORY\Interfaces\StockRepositoryInterface;
use Modules\INVENTORY\Models\Stock;

class StockRepository implements StockRepositoryInterface
{
    use BuildsDatatableQueries;

    public function paginate(DataTableQuery $dt): LengthAwarePaginator
    {
        $q = $this->baseQuery();
        $this->applyDataTable($q, $dt, ['products.name', 'products.sku'], $this->sortable(), $this->filters());
        return $q->paginate($dt->perPage, ['*'], 'page', $dt->page)->withQueryString();
    }

    public function exportBuilder(DataTableQuery $dt): Builder
    {
        $q = $this->baseQuery();
        $this->applyDataTable($q, $dt, ['products.name', 'products.sku'], $this->sortable(), $this->filters());
        return $q;
    }

    public function findOrFail(int $id): Stock { return Stock::with(['product', 'variant', 'warehouse', 'location'])->findOrFail($id); }

    public function getForProduct(int $productId, ?int $warehouseId = null): Collection
    {
        return Stock::with(['warehouse', 'location'])->where('product_id', $productId)->when($warehouseId, fn (Builder $q) => $q->where('warehouse_id', $warehouseId))->get();
    }

    public function getLowStock(): Collection
    {
        return Stock::with(['product', 'warehouse'])->whereHas('product', fn (Builder $q) => $q->whereColumn('inventory_stocks.qty_on_hand', '<=', 'inventory_products.reorder_level')->where('reorder_level', '>', 0))->get();
    }

    public function getOutOfStock(): Collection
    {
        return Stock::with(['product', 'warehouse'])->where('qty_on_hand', '<=', 0)->get();
    }

    private function baseQuery(): Builder
    {
        return Stock::query()
            ->join('inventory_products as products', 'products.id', '=', 'inventory_stocks.product_id')
            ->with(['product:id,name,sku,barcode,reorder_level', 'variant:id,product_id,variant_code,name', 'warehouse:id,name,code', 'location:id,warehouse_id,name,code'])
            ->select('inventory_stocks.*');
    }

    private function sortable(): array
    {
        return ['qty_on_hand' => 'qty_on_hand', 'qty_available' => 'qty_available', 'avg_cost' => 'avg_cost', 'total_value' => 'total_value', 'last_movement_at' => 'last_movement_at'];
    }

    private function filters(): array
    {
        return [
            'warehouse_id' => fn (Builder $q, string $v) => $q->where('inventory_stocks.warehouse_id', $v),
            'product_id' => fn (Builder $q, string $v) => $q->where('inventory_stocks.product_id', $v),
            'location_id' => fn (Builder $q, string $v) => $q->where('inventory_stocks.location_id', $v),
            'stock_status' => function (Builder $q, string $v): void {
                if ($v === 'out') $q->where('inventory_stocks.qty_on_hand', '<=', 0);
                elseif ($v === 'low') $q->whereColumn('inventory_stocks.qty_on_hand', '<=', 'inventory_products.reorder_level')->where('inventory_products.reorder_level', '>', 0);
                elseif ($v === 'in') $q->where('inventory_stocks.qty_on_hand', '>', 0);
            },
        ];
    }
}
