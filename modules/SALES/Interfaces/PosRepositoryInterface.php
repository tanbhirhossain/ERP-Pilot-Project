<?php

namespace Modules\SALES\Interfaces;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\CORE\Support\DataTableQuery;
use Modules\SALES\Models\PosSale;
use Modules\SALES\Models\PosSession;

interface PosRepositoryInterface
{
    public function sessionsPaginate(DataTableQuery $dt): LengthAwarePaginator;
    public function salesPaginate(DataTableQuery $dt): LengthAwarePaginator;
    public function findSessionOrFail(int $id): PosSession;
    public function findSaleOrFail(int $id): PosSale;
    public function openSession(array $data): PosSession;
    public function closeSession(PosSession $s, array $data): PosSession;
    public function createSale(array $data): PosSale;
    public function nextPosNo(): string;
    public function nextSessionNo(): string;
}
