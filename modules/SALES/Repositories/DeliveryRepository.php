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
        $q = Delivery::with(['customer','warehouse','order'])->withCount('items');
        return $this->applyDatatable($q, $dt)->paginate($dt->perPage, ['*'], 'page', $dt->page);
    }
    public function exportBuilder(DataTableQuery $dt): Builder { return $this->applyDatatable(Delivery::query(), $dt); }
    public function findOrFail(int $id): Delivery { return Delivery::with(['customer','warehouse','order','items.product'])->findOrFail($id); }
}
