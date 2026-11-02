<?php
namespace Modules\HRM\Services;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Modules\CORE\Support\DataTableQuery;
use Modules\HRM\Interfaces\EmployeeSupervisorRepositoryInterface;
use Modules\HRM\Models\Employee\EmployeeSupervisor;
class EmployeeSupervisorService {
    public function __construct(private readonly EmployeeSupervisorRepositoryInterface $repo) {}
    public function paginate(DataTableQuery $dt): LengthAwarePaginator { return $this->repo->paginate($dt); }
    public function findOrFail(int $id): EmployeeSupervisor { return $this->repo->findOrFail($id); }
    public function create(array $data): EmployeeSupervisor { return $this->repo->create($data); }
    public function update(EmployeeSupervisor $m, array $data): EmployeeSupervisor { return $this->repo->update($m,$data); }
    public function delete(EmployeeSupervisor $m): void { $this->repo->delete($m); }
    public function employeeOptions(?string $s=null,int $limit=100): Collection { return $this->repo->optionsForEmployee($s,$limit); }
    public function userOptions(?string $s=null,int $limit=100): Collection {
        return \Modules\CORE\Models\User::query()
            ->when($s, fn($q,$t)=> $q->where('name','like',"%$t%")->orWhere('email','like',"%$t%"))
            ->orderBy('name')->limit($limit)->get(['id','name','email'])
            ->map(fn($u)=>['value'=>$u->id,'label'=>$u->name,'description'=>$u->email])->values();
    }
}
