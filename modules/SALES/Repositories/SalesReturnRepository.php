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
        $q = SalesReturn::with(['customer','invoice','warehouse'])->withCount('items');
        return $this->applyDatatable($q, $dt)->paginate($dt->perPage, ['*'], 'page', $dt->page);
    }
    public function exportBuilder(DataTableQuery $dt): Builder { return $this->applyDatatable(SalesReturn::query(), $dt); }
    public function findOrFail(int $id): SalesReturn { return SalesReturn::with(['customer','invoice','warehouse','items.product','journal'])->findOrFail($id); }
}
