<?php
namespace Modules\HRM\Repositories\Roster;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\CORE\Repositories\Concerns\BuildsDatatableQueries;
use Modules\CORE\Support\DataTableQuery;
use Modules\HRM\Interfaces\Roster\RosterShiftSwapRepositoryInterface;
use Modules\HRM\Models\Roster\RosterShiftSwap;
class RosterShiftSwapRepository implements RosterShiftSwapRepositoryInterface {
    use BuildsDatatableQueries;
    public function paginate(DataTableQuery $dt): LengthAwarePaginator { $q=$this->baseQuery(); $this->applyDataTable($q,$dt,$this->searchColumns(),$this->sortable(),$this->filters()); return $q->paginate($dt->perPage,['*'],'page',$dt->page)->withQueryString(); }
    public function exportBuilder(DataTableQuery $dt): Builder { $q=$this->baseQuery(); $this->applyDataTable($q,$dt,$this->searchColumns(),$this->sortable(),$this->filters()); return $q; }
    public function findOrFail(int $id): RosterShiftSwap { return RosterShiftSwap::with(['duty','requester:id,employee_id,full_name','requestedShift:id,shift_name','targetShift:id,shift_name'])->findOrFail($id); }
    public function create(array $data): RosterShiftSwap { return DB::transaction(fn()=> RosterShiftSwap::create($data)); }
    public function update(RosterShiftSwap $swap, array $data): RosterShiftSwap { return DB::transaction(function() use($swap,$data){ $swap->fill($data); $swap->save(); return $swap; }); }
    public function delete(RosterShiftSwap $swap): void { DB::transaction(fn()=> $swap->delete()); }
    private function baseQuery(): Builder { return RosterShiftSwap::query()->with(['duty','requester:id,employee_id,full_name','requestedShift:id,shift_name','targetShift:id,shift_name']); }
    private function searchColumns(): array { return ['requester_employee_id','reason']; }
    private function sortable(): array { return ['id'=>'id','swap_date'=>'swap_date','status'=>'status','created_at'=>'created_at']; }
    private function filters(): array { return ['status'=>fn(Builder $q,string $v)=>$q->where('status',$v),'requester_employee_id'=>fn(Builder $q,string $v)=>$q->where('requester_employee_id',$v)]; }
}
