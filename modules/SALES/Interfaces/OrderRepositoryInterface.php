<?php

namespace Modules\SALES\Interfaces;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Modules\CORE\Support\DataTableQuery;
use Modules\SALES\Models\Order;

interface OrderRepositoryInterface
{
    public function paginate(DataTableQuery $dt): LengthAwarePaginator;
    public function exportBuilder(DataTableQuery $dt): Builder;
    public function findOrFail(int $id): Order;
    public function create(array $data): Order;
    public function update(Order $o, array $data): Order;
    public function delete(Order $o): void;
    public function nextNo(): string;
}
