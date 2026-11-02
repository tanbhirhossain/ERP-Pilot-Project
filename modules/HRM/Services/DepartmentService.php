<?php
namespace Modules\HRM\Services;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Modules\CORE\Support\DataTableQuery;
use Modules\HRM\Interfaces\DepartmentRepositoryInterface;
use Modules\HRM\Models\Department;
class DepartmentService {
    public function __construct(private readonly DepartmentRepositoryInterface $repo) {}
    public function paginate(DataTableQuery $dt): LengthAwarePaginator { return $this->repo->paginate($dt); }
    public function options(?string $search=null,int $limit=25): Collection { return $this->repo->options($search,$limit); }
    public function findOrFail(int $id): Department { return $this->repo->findOrFail($id); }
    public function create(array $data): Department { return $this->repo->create($data); }
    public function update(Department $d, array $data): Department { return $this->repo->update($d,$data); }
    public function delete(Department $d): void { $this->repo->delete($d); }
}
