<?php
namespace Modules\HRM\Interfaces;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\CORE\Support\DataTableQuery;
use Modules\HRM\Models\Employee\EmployeeSupervisor;
interface EmployeeSupervisorRepositoryInterface {
    public function paginate(DataTableQuery $dt): LengthAwarePaginator;
    public function exportBuilder(DataTableQuery $dt): Builder;
    public function findOrFail(int $id): EmployeeSupervisor;
    public function create(array $data): EmployeeSupervisor;
    public function update(EmployeeSupervisor $m, array $data): EmployeeSupervisor;
    public function delete(EmployeeSupervisor $m): void;
    public function optionsForEmployee(?string $search=null, int $limit=25): Collection;
}
