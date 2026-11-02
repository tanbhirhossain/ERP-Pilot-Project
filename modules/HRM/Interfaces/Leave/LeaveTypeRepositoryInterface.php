<?php
namespace Modules\HRM\Interfaces\Leave;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Modules\CORE\Support\DataTableQuery;
use Modules\HRM\Models\Leave\LeaveType;
interface LeaveTypeRepositoryInterface {
    public function paginate(DataTableQuery $dt): LengthAwarePaginator;
    public function exportBuilder(DataTableQuery $dt): Builder;
    public function findOrFail(int $id): LeaveType;
    public function create(array $data): LeaveType;
    public function update(LeaveType $type, array $data): LeaveType;
    public function delete(LeaveType $type): void;
    public function options(): \Illuminate\Support\Collection;
}
