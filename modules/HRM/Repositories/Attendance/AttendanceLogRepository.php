<?php
namespace Modules\HRM\Repositories\Attendance;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\CORE\Repositories\Concerns\BuildsDatatableQueries;
use Modules\CORE\Support\DataTableQuery;
use Modules\HRM\Interfaces\Attendance\AttendanceLogRepositoryInterface;
use Modules\HRM\Models\Attendance\AttendanceLog;
class AttendanceLogRepository implements AttendanceLogRepositoryInterface {
    use BuildsDatatableQueries;
    public function paginate(DataTableQuery $dt): LengthAwarePaginator {
        $q=$this->baseQuery();
        if($dt->search !== null && trim($dt->search) !== ''){
            $needle='%'.addcslashes(trim($dt->search),'%_').'%';
            $q->where(function(Builder $g) use($needle){
                $g->where('employee_id','like',$needle)
                  ->orWhere('device_user_id','like',$needle)
                  ->orWhereHas('employee', fn(Builder $qq)=>$qq->where('full_name','like',$needle)->orWhere('first_name','like',$needle)->orWhere('last_name','like',$needle));
            });
            $dtNoSearch = new DataTableQuery(null,$dt->sort,$dt->direction,$dt->page,$dt->perPage,$dt->filters,$dt->defaultSort,$dt->defaultDirection);
            $this->applyDataTable($q,$dtNoSearch,[],$this->sortable(),$this->filters());
        } else {
            $this->applyDataTable($q,$dt,$this->searchColumns(),$this->sortable(),$this->filters());
        }
        return $q->paginate($dt->perPage,['*'],'page',$dt->page)->withQueryString();
    }
    public function exportBuilder(DataTableQuery $dt): Builder { $q=$this->baseQuery(); $this->applyDataTable($q,$dt,$this->searchColumns(),$this->sortable(),$this->filters()); return $q; }
    public function findOrFail(int $id): AttendanceLog { return AttendanceLog::with(['employee','deviceUser'])->findOrFail($id); }
    public function unprocessed(int $limit=500, ?string $from=null, ?string $to=null): \Illuminate\Support\Collection {
        $q = AttendanceLog::where('is_processed',false)->whereNotNull('employee_id');
        if($from) $q->where('punch_time','>=', $from);
        if($to) $q->where('punch_time','<=', $to);
        return $q->orderBy('punch_time')->limit($limit)->get();
    }
    public function unprocessedCount(?string $from=null, ?string $to=null): int {
        $q = AttendanceLog::where('is_processed',false)->whereNotNull('employee_id');
        if($from) $q->where('punch_time','>=', $from);
        if($to) $q->where('punch_time','<=', $to);
        return $q->count();
    }
    public function orphanCount(?string $from=null, ?string $to=null): int {
        $q = AttendanceLog::where('is_processed',false)->whereNull('employee_id');
        if($from) $q->where('punch_time','>=', $from);
        if($to) $q->where('punch_time','<=', $to);
        return $q->count();
    }
    public function create(array $data): AttendanceLog { return AttendanceLog::create($data); }
    public function createMany(array $rows): int { if(empty($rows)) return 0; $now=now(); foreach($rows as &$r){ $r['created_at']=$now; $r['updated_at']=$now; } return DB::table('attendance_logs')->insert($rows) ? count($rows) : 0; }
    public function markProcessed(array $ids): void { AttendanceLog::whereIn('id',$ids)->update(['is_processed'=>true]); }
    public function delete(AttendanceLog $log): void { $log->delete(); }
    private function baseQuery(): Builder { return AttendanceLog::query()->with(['employee:id,employee_id,full_name,department_id,designation_id','employee.department:id,name','employee.designation:id,name','deviceUser']); }
    private function searchColumns(): array { return ['employee_id','device_user_id']; }
    private function sortable(): array { return ['id'=>'id','punch_time'=>'punch_time','created_at'=>'created_at']; }
    private function filters(): array {
        return [
            'employee_id'=>fn(Builder $q,string $v)=>$q->where('employee_id',$v),
            'is_processed'=>fn(Builder $q,string $v)=>$q->where('is_processed',$v==='1'),
            'device_id'=>fn(Builder $q,string $v)=>$q->where('device_id',$v),
            'duty_date'=>fn(Builder $q,string $v)=>$q->whereDate('punch_time',$v),
            'from'=>fn(Builder $q,string $v)=>$q->whereDate('punch_time','>=',$v),
            'to'=>fn(Builder $q,string $v)=>$q->whereDate('punch_time','<=',$v),
            'department_id'=>fn(Builder $q,string $v)=>$q->whereHas('employee', fn(Builder $qq)=>$qq->where('department_id',$v)),
            'designation_id'=>fn(Builder $q,string $v)=>$q->whereHas('employee', fn(Builder $qq)=>$qq->where('designation_id',$v)),
        ];
    }
}
