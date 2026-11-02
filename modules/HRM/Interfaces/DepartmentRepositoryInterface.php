<?php
namespace Modules\HRM\Interfaces;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\HRM\Models\Department;
use Modules\CORE\Support\DataTableQuery;
interface DepartmentRepositoryInterface {
    public function paginate(DataTableQuery $dt): LengthAwarePaginator;
    public function exportBuilder(DataTableQuery $dt): Builder;
    public function options(?string $search=null, int $limit=25): Collection;
    public function findOrFail(int $id): Department;
    public function create(array $data): Department;
    public function update(Department $d, array $data): Department;
    public function delete(Department $d): void;
}
