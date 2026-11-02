<?php
namespace Modules\HRM\Repositories\Roster;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\CORE\Repositories\Concerns\BuildsDatatableQueries;
use Modules\CORE\Support\DataTableQuery;
use Modules\HRM\Interfaces\Roster\RosterShiftRepositoryInterface;
use Modules\HRM\Models\Roster\RosterShift;
class RosterShiftRepository implements RosterShiftRepositoryInterface {
    use BuildsDatatableQueries;
    public function paginate(DataTableQuery $dt): LengthAwarePaginator { $q=$this->baseQuery(); $this->applyDataTable($q,$dt,$this->searchColumns(),$this->sortable(),$this->filters()); return $q->paginate($dt->perPage,['*'],'page',$dt->page)->withQueryString(); }
    public function exportBuilder(DataTableQuery $dt): Builder { $q=$this->baseQuery(); $this->applyDataTable($q,$dt,$this->searchColumns(),$this->sortable(),$this->filters()); return $q; }
    public function options(?string $search=null,int $limit=25): Collection { return RosterShift::query()->when($search, fn(Builder $q,$t)=>$q->where('shift_name','like','%'.addcslashes($t,'%_').'%'))->orderBy('shift_name')->limit($limit)->get(['id','shift_name','start_time','end_time'])->map(fn($s)=>['value'=>$s->id,'label'=>$s->shift_name.' ('.$s->start_time.'-'.$s->end_time.')'])->values(); }
    public function findOrFail(int $id): RosterShift { return RosterShift::findOrFail($id); }
    public function create(array $data): RosterShift { return DB::transaction(fn()=>RosterShift::create($data)); }
    public function update(RosterShift $s, array $data): RosterShift { return DB::transaction(function() use($s,$data){ $s->fill($data); $s->save(); return $s; }); }
    public function delete(RosterShift $s): void { DB::transaction(fn()=> $s->delete()); }
    private function baseQuery(): Builder { return RosterShift::query(); }
    private function searchColumns(): array { return ['shift_name']; }
    private function sortable(): array { return ['id'=>'id','shift_name'=>'shift_name','start_time'=>'start_time','created_at'=>'created_at']; }
    private function filters(): array { return ['crosses_midnight'=>fn(Builder $q,string $v)=>$q->where('crosses_midnight',$v==='1')]; }
}
