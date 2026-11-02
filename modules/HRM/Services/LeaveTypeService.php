<?php
namespace Modules\HRM\Services;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\CORE\Support\DataTableQuery;
use Modules\HRM\Interfaces\Leave\LeaveTypeRepositoryInterface;
use Modules\HRM\Models\Leave\LeaveType;
class LeaveTypeService {
    public function __construct(private readonly LeaveTypeRepositoryInterface $repo) {}
    public function paginate(DataTableQuery $dt): LengthAwarePaginator { return $this->repo->paginate($dt); }
    public function findOrFail(int $id): LeaveType { return $this->repo->findOrFail($id); }
    public function create(array $d): LeaveType { return $this->repo->create($d); }
    public function update(LeaveType $t, array $d): LeaveType { return $this->repo->update($t,$d); }
    public function delete(LeaveType $t): void { $this->repo->delete($t); }
    public function options(): \Illuminate\Support\Collection { return $this->repo->options(); }
}
