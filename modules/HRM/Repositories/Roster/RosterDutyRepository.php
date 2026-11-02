<?php
namespace Modules\HRM\Repositories\Roster;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\CORE\Repositories\Concerns\BuildsDatatableQueries;
use Modules\CORE\Support\DataTableQuery;
use Modules\HRM\Interfaces\Roster\RosterDutyRepositoryInterface;
use Modules\HRM\Models\Roster\RosterDuty;
class RosterDutyRepository implements RosterDutyRepositoryInterface {
    use BuildsDatatableQueries;
    public function paginate(DataTableQuery $dt): LengthAwarePaginator { $q=$this->baseQuery(); $this->applyDataTable($q,$dt,$this->searchColumns(),$this->sortable(),$this->filters()); return $q->paginate($dt->perPage,['*'],'page',$dt->page)->withQueryString(); }
    public function exportBuilder(DataTableQuery $dt): Builder { $q=$this->baseQuery(); $this->applyDataTable($q,$dt,$this->searchColumns(),$this->sortable(),$this->filters()); return $q; }
    public function options(?string $search=null,int $limit=25): Collection { return RosterDuty::query()->when($search, fn(Builder $q,$t)=>$q->where('employee_id','like','%'.addcslashes($t,'%_').'%'))->orderBy('roster_date','desc')->limit($limit)->get(['id','employee_id','roster_date'])->map(fn($d)=>['value'=>$d->id,'label'=>$d->employee_id.' @ '.$d->roster_date])->values(); }
    public function findOrFail(int $id): RosterDuty { return RosterDuty::with(['employee:id,employee_id,full_name,first_name','shift'])->findOrFail($id); }
    public function create(array $data): RosterDuty { return DB::transaction(function() use($data){ if(!empty($data['shift_id'])){ $shift=\Modules\HRM\Models\Roster\RosterShift::find($data['shift_id']); if($shift){ $data['scheduled_start_time']=$data['scheduled_start_time']??$shift->start_time; $data['scheduled_end_time']=$data['scheduled_end_time']??$shift->end_time; } } return RosterDuty::create($data); }); }
    public function update(RosterDuty $d, array $data): RosterDuty { return DB::transaction(function() use($d,$data){ $d->fill($data); $d->save(); return $d; }); }
    public function delete(RosterDuty $d): void { DB::transaction(fn()=> $d->delete()); }
    public function bulkAssign(array $employeeIds, int $shiftId, string $from, string $to): int {
        return DB::transaction(function() use($employeeIds,$shiftId,$from,$to){
            $shift=\Modules\HRM\Models\Roster\RosterShift::findOrFail($shiftId);
            $count=0;
            $start=new \DateTime($from); $end=new \DateTime($to);
            // Guard: limit to 60 days to prevent timeout (6000 records max reasonable)
            $days = (int) $start->diff($end)->days;
            if($days > 60) throw new \InvalidArgumentException('Bulk range cannot exceed 60 days (requested '.$days.' days)');
            if(count($employeeIds) > 200) throw new \InvalidArgumentException('Cannot bulk assign more than 200 employees at once');
            for($date=clone $start; $date <= $end; $date->modify('+1 day')){
                $dateStr=$date->format('Y-m-d');
                $rows=[];
                foreach($employeeIds as $empId){
                    $rows[]=[
                        'employee_id'=>$empId,
                        'roster_date'=>$dateStr,
                        'shift_id'=>$shiftId,
                        'scheduled_start_time'=>$shift->start_time,
                        'scheduled_end_time'=>$shift->end_time,
                        'status'=>'Scheduled',
                        'created_at'=>now(),
                        'updated_at'=>now(),
                    ];
                }
                // Use upsert for performance (one query per day vs N queries)
                RosterDuty::upsert($rows, ['employee_id','roster_date'], ['shift_id','scheduled_start_time','scheduled_end_time','status','updated_at']);
                $count += count($rows);
            }
            return $count;
        });
    }
    private function baseQuery(): Builder { return RosterDuty::query()->with(['employee:id,employee_id,full_name,first_name,last_name','shift:id,shift_name,start_time,end_time']); }
    private function searchColumns(): array { return ['employee_id']; }
    private function sortable(): array { return ['id'=>'id','roster_date'=>'roster_date','created_at'=>'created_at']; }
    private function filters(): array { return ['employee_id'=>fn(Builder $q,string $v)=>$q->where('employee_id',$v),'shift_id'=>fn(Builder $q,string $v)=>$q->where('shift_id',$v),'status'=>fn(Builder $q,string $v)=>$q->where('status',$v),'roster_date'=>fn(Builder $q,string $v)=>$q->where('roster_date',$v)]; }
}
