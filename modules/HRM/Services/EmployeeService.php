<?php
namespace Modules\HRM\Services;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Modules\CORE\Support\DataTableQuery;
use Modules\HRM\Interfaces\Employee\EmployeeRepositoryInterface;
use Modules\HRM\Models\Employee\Employee;
class EmployeeService {
    public function __construct(private readonly EmployeeRepositoryInterface $repo) {}
    public function paginate(DataTableQuery $dt): LengthAwarePaginator { return $this->repo->paginate($dt); }
    public function options(?string $s=null,int $l=25): Collection { return $this->repo->options($s,$l); }
    public function findOrFail(int $id): Employee { return $this->repo->findOrFail($id); }
    public function findByEmployeeId(string $eid): ?Employee { return $this->repo->findByEmployeeId($eid); }
    public function create(array $d): Employee { return $this->repo->create($d); }
    public function update(Employee $e,array $data): Employee { return $this->repo->update($e,$data); }
    public function delete(Employee $e): void { $this->repo->delete($e); }
}
