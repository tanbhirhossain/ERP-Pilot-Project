<?php

namespace Modules\HRM\Interfaces\Leave;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Modules\CORE\Support\DataTableQuery;
use Modules\HRM\Models\Leave\LeaveRequest;

interface LeaveRequestRepositoryInterface
{
    public function paginate(DataTableQuery $dt): LengthAwarePaginator;

    public function exportBuilder(DataTableQuery $dt): Builder;

    public function findOrFail(int $id): LeaveRequest;

    public function create(array $data): LeaveRequest;

    public function update(LeaveRequest $req, array $data): LeaveRequest;

    public function delete(LeaveRequest $req): void;
}
