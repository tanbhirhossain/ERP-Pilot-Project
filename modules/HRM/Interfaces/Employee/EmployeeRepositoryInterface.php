<?php
namespace Modules\HRM\Interfaces\Employee;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\CORE\Support\DataTableQuery;
use Modules\HRM\Models\Employee\Employee;
interface EmployeeRepositoryInterface {
    public function paginate(DataTableQuery $dt): LengthAwarePaginator;
    public function exportBuilder(DataTableQuery $dt): Builder;
    public function options(?string $search=null,int $limit=25): Collection;
    public function findOrFail(int $id): Employee;
    public function findByEmployeeId(string $employeeId): ?Employee;
    public function create(array $data): Employee;
    public function update(Employee $e, array $data): Employee;
    public function delete(Employee $e): void;
}
