<?php
namespace Modules\HRM\Services;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Modules\CORE\Support\DataTableQuery;
use Modules\HRM\Interfaces\Roster\RosterShiftRepositoryInterface;
use Modules\HRM\Models\Roster\RosterShift;
class RosterShiftService {
    public function __construct(private readonly RosterShiftRepositoryInterface $repo) {}
    public function paginate(DataTableQuery $dt): LengthAwarePaginator { return $this->repo->paginate($dt); }
    public function options(?string $s=null,int $l=25): Collection { return $this->repo->options($s,$l); }
    public function findOrFail(int $id): RosterShift { return $this->repo->findOrFail($id); }
    public function create(array $d): RosterShift { return $this->repo->create($d); }
    public function update(RosterShift $s,array $data): RosterShift { return $this->repo->update($s,$data); }
    public function delete(RosterShift $s): void { $this->repo->delete($s); }
}
