<?php
namespace Modules\HRM\Repositories;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\CORE\Repositories\Concerns\BuildsDatatableQueries;
use Modules\CORE\Support\DataTableQuery;
use Modules\HRM\Interfaces\EmployeeSupervisorRepositoryInterface;
use Modules\HRM\Models\Employee\EmployeeSupervisor;
class EmployeeSupervisorRepository implements EmployeeSupervisorRepositoryInterface {
    use BuildsDatatableQueries;
    public function paginate(DataTableQuery $dt): LengthAwarePaginator {
        $q=$this->baseQuery(); $this->applyDataTable($q,$dt,$this->searchColumns(),$this->sortable(),$this->filters());
        return $q->paginate($dt->perPage,['*'],'page',$dt->page)->withQueryString();
    }
    public function exportBuilder(DataTableQuery $dt): Builder { $q=$this->baseQuery(); $this->applyDataTable($q,$dt,$this->searchColumns(),$this->sortable(),$this->filters()); return $q; }
    public function findOrFail(int $id): EmployeeSupervisor { return EmployeeSupervisor::with(['employee:id,employee_id,full_name,first_name,last_name','supervisor:id,name,email','supervisorEmployee:employee_id,full_name,first_name,last_name,email,department_id'])->findOrFail($id); }
    public function create(array $data): EmployeeSupervisor {
        return DB::transaction(function() use($data){
            // Ensure only one kind set
            if (!empty($data['supervisor_employee_id'])) $data['supervisor_id']=null;
            if (!empty($data['supervisor_id'])) $data['supervisor_employee_id']=null;
            // If is_primary, demote other primaries for same employee
            if (!empty($data['is_primary'])) {
                EmployeeSupervisor::where('employee_id',$data['employee_id'])->update(['is_primary'=>false]);
            }
            return EmployeeSupervisor::create($data);
        });
    }
    public function update(EmployeeSupervisor $m, array $data): EmployeeSupervisor {
        return DB::transaction(function() use($m,$data){
            if (array_key_exists('supervisor_employee_id',$data) && !empty($data['supervisor_employee_id'])) $data['supervisor_id']=null;
            if (array_key_exists('supervisor_id',$data) && !empty($data['supervisor_id'])) $data['supervisor_employee_id']=null;
            if (!empty($data['is_primary'])) {
                EmployeeSupervisor::where('employee_id',$m->employee_id)->where('id','!=',$m->id)->update(['is_primary'=>false]);
            }
            $m->fill($data); $m->save(); return $m;
        });
    }
    public function delete(EmployeeSupervisor $m): void { DB::transaction(fn()=> $m->delete()); }
    public function optionsForEmployee(?string $search=null,int $limit=25): Collection {
        return \Modules\HRM\Models\Employee\Employee::query()
            ->when($search, fn(Builder $q,$t)=> $q->where(fn($g)=> $g->where('employee_id','like',"%$t%")->orWhere('full_name','like',"%$t%")))
            ->orderBy('full_name')->limit($limit)->get(['employee_id','full_name','first_name'])->map(fn($e)=>[
                'value'=>$e->employee_id,'label'=>$e->full_name ?? $e->first_name.' ('.$e->employee_id.')'
            ])->values();
    }
    private function baseQuery(): Builder {
        return EmployeeSupervisor::query()->with(['employee:id,employee_id,full_name','supervisor:id,name,email','supervisorEmployee:employee_id,full_name,first_name,last_name,email']);
    }
    private function searchColumns(): array { return ['employee_id','supervisor_type']; }
    private function sortable(): array { return ['id'=>'id','employee_id'=>'employee_id','created_at'=>'created_at','supervisor_type'=>'supervisor_type']; }
    private function filters(): array {
        return [
            'employee_id'=>fn(Builder $q,string $v)=> $q->where('employee_id',$v),
            'supervisor_type'=>fn(Builder $q,string $v)=> $q->where('supervisor_type',$v),
            'is_active'=>fn(Builder $q,string $v)=> $q->where('is_active',$v==='1'),
            'is_primary'=>fn(Builder $q,string $v)=> $q->where('is_primary',$v==='1'),
        ];
    }
}
