<?php
namespace Modules\HRM\Services;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Modules\CORE\Support\DataTableQuery;
use Modules\HRM\Interfaces\DesignationRepositoryInterface;
use Modules\HRM\Models\Designation;
class DesignationService {
    public function __construct(private readonly DesignationRepositoryInterface $repo) {}
    public function paginate(DataTableQuery $dt): LengthAwarePaginator { return $this->repo->paginate($dt); }
    public function options(?string $s=null,int $l=25): Collection { return $this->repo->options($s,$l); }
    public function findOrFail(int $id): Designation { return $this->repo->findOrFail($id); }
    public function create(array $d): Designation { return $this->repo->create($d); }
    public function update(Designation $d,array $data): Designation { return $this->repo->update($d,$data); }
    public function delete(Designation $d): void { $this->repo->delete($d); }
}
