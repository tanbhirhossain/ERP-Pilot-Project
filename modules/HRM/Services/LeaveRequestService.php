<?php
namespace Modules\HRM\Services;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\HRM\Models\Employee\Employee;
use Modules\CORE\Support\DataTableQuery;
use Modules\HRM\Interfaces\Leave\LeaveRequestRepositoryInterface;
use Modules\HRM\Models\Leave\LeaveRequest;
use Modules\HRM\Models\Leave\LeaveApprovalAction;
use Modules\HRM\Models\Leave\LeaveType;
use Modules\HRM\Models\Leave\LeaveRequestWorkflow;
use Modules\HRM\Models\Attendance\AttendanceDaily;
use Modules\HRM\Models\Holiday;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
class LeaveRequestService {
    public function __construct(private readonly LeaveRequestRepositoryInterface $repo, private readonly LeaveBalanceService $balances) {}
    public function paginate(DataTableQuery $dt): LengthAwarePaginator { return $this->repo->paginate($dt); }
    public function findOrFail(int $id): LeaveRequest { return $this->repo->findOrFail($id); }
    public function create(array $d): LeaveRequest {
        return DB::transaction(function() use($d){
            Employee::where('employee_id',$d['employee_id'])->lockForUpdate()->firstOrFail();
            $this->assertNoOverlap($d['employee_id'], $d['from_date'], $d['to_date']);
            $d['days']=$this->balances->assertAvailable($d['employee_id'],(int)$d['leave_type_id'],$d['from_date'],$d['to_date'],(bool)($d['is_half_day']??false));
            $leave=$this->repo->create($d);
            $type=LeaveType::findOrFail($leave->leave_type_id);
            LeaveRequestWorkflow::create(['leave_request_id'=>$leave->id,'steps'=>$this->balances->settings($type)['approval_steps']?:['HR'],'current_stage'=>0]);
            return $leave;
        });
    }
    public function update(LeaveRequest $r, array $d): LeaveRequest {
        if($r->status!=='Pending') throw ValidationException::withMessages(['status'=>'Only Pending leave applications can be edited.']);
        $employeeId=$d['employee_id']??$r->employee_id; $typeId=(int)($d['leave_type_id']??$r->leave_type_id);
        $from=$d['from_date']??$r->from_date->toDateString(); $to=$d['to_date']??$r->to_date->toDateString(); $half=(bool)($d['is_half_day']??$r->is_half_day);
        if($employeeId!==$r->employee_id || $from!==$r->from_date->toDateString() || $to!==$r->to_date->toDateString()) $this->assertNoOverlap($employeeId,$from,$to,$r->id);
        if($employeeId!==$r->employee_id || $typeId!==(int)$r->leave_type_id || $from!==$r->from_date->toDateString() || $to!==$r->to_date->toDateString() || $half!==$r->is_half_day){
            $d['days']=$this->balances->assertAvailable($employeeId,$typeId,$from,$to,$half,$r->id);
        }
        return $this->repo->update($r,$d);
    }
    public function delete(LeaveRequest $r): void { $this->repo->delete($r); }
    public function approve(LeaveRequest $r): LeaveRequest {
        return DB::transaction(function() use($r){
            $locked=LeaveRequest::whereKey($r->id)->lockForUpdate()->firstOrFail();
            if($locked->status!=='Pending') throw new \RuntimeException('Only Pending can be approved');
            $type=LeaveType::findOrFail($locked->leave_type_id); $settings=$this->balances->settings($type);
            $workflow=LeaveRequestWorkflow::where('leave_request_id',$locked->id)->lockForUpdate()->first();
            if(!$workflow) $workflow=LeaveRequestWorkflow::create(['leave_request_id'=>$locked->id,'steps'=>$settings['approval_steps']?:['HR'],'current_stage'=>(int)LeaveApprovalAction::where('leave_request_id',$locked->id)->where('decision','Approved')->count()]);
            $steps=$workflow->steps?:['HR']; $step=(int)$workflow->current_stage;
            if($step>=count($steps)) throw new \RuntimeException('No approval stage remains for this request');
            LeaveApprovalAction::create(['leave_request_id'=>$locked->id,'stage_index'=>$step+1,'stage_name'=>$steps[$step]??'HR','decision'=>'Approved','actor_id'=>auth()->id(),'decided_at'=>now()]);
            $workflow->update(['current_stage'=>$step+1]);
            if($step+1>=count($steps)){ $locked->update(['status'=>'Approved','approved_by'=>auth()->id(),'approved_at'=>now()]); $this->syncApprovedLeaveToAttendance($locked,$settings); }
            return $locked->refresh();
        });
    }
    public function reject(LeaveRequest $r, ?string $comments=null): LeaveRequest {
        return DB::transaction(function() use($r,$comments){
            $locked=LeaveRequest::whereKey($r->id)->lockForUpdate()->firstOrFail();
            if($locked->status!=='Pending') throw new \RuntimeException('Only Pending can be rejected');
            $type=LeaveType::findOrFail($locked->leave_type_id); $settings=$this->balances->settings($type);
            $workflow=LeaveRequestWorkflow::where('leave_request_id',$locked->id)->lockForUpdate()->first();
            if(!$workflow) $workflow=LeaveRequestWorkflow::create(['leave_request_id'=>$locked->id,'steps'=>$settings['approval_steps']?:['HR'],'current_stage'=>(int)LeaveApprovalAction::where('leave_request_id',$locked->id)->where('decision','Approved')->count()]);
            $steps=$workflow->steps?:['HR']; $step=(int)$workflow->current_stage;
            LeaveApprovalAction::create(['leave_request_id'=>$locked->id,'stage_index'=>$step+1,'stage_name'=>$steps[$step]??'HR','decision'=>'Rejected','actor_id'=>auth()->id(),'comments'=>$comments,'decided_at'=>now()]);
            $locked->update(['status'=>'Rejected','approved_by'=>auth()->id(),'approved_at'=>now()]);
            return $locked->refresh();
        });
    }
    private function syncApprovedLeaveToAttendance(LeaveRequest $leave,array $settings): void {
        $employee=Employee::with('department')->where('employee_id',$leave->employee_id)->first(); if(!$employee)return;
        $start=Carbon::parse($leave->from_date)->startOfDay(); $end=Carbon::parse($leave->to_date)->startOfDay();
        $holidayDates=Holiday::whereBetween('holiday_date',[$start->toDateString(),$end->toDateString()])->where(function($q)use($employee){$q->whereNull('department_id');if($employee->department_id)$q->orWhere('department_id',$employee->department_id);})->pluck('holiday_date')->map(fn($v)=>Carbon::parse($v)->toDateString())->all();
        foreach(CarbonPeriod::create($start,$end) as $date){
            if(!in_array($date->dayOfWeek,$settings['working_days']??[0,1,2,3,4],true)||in_array($date->toDateString(),$holidayDates,true))continue;
            $daily=AttendanceDaily::firstOrNew(['employee_id'=>$leave->employee_id,'duty_date'=>$date->toDateString()]);
            if($daily->exists&&($daily->primary_punch_in||$daily->primary_punch_out))continue;
            $daily->status='On Leave'; $daily->remarks=trim(($daily->remarks? $daily->remarks.' | ':'').'Approved leave #'.$leave->id.($leave->is_half_day?' (half day)':'')); $daily->save();
        }
    }
    public function balance(string $employeeId,int $typeId,?int $year=null): array { return $this->balances->balance($employeeId,$typeId,$year); }
    public function estimateDays(string $employeeId,int $typeId,string $from,string $to,bool $half=false): float { return $this->balances->days($employeeId,$typeId,$from,$to,$half); }
    public function nextApprovalStage(LeaveRequest $leave): ?string { $workflow=LeaveRequestWorkflow::where('leave_request_id',$leave->id)->first(); if(!$workflow){$type=LeaveType::find($leave->leave_type_id);$steps=$type?$this->balances->settings($type)['approval_steps']:['HR'];$index=(int)LeaveApprovalAction::where('leave_request_id',$leave->id)->where('decision','Approved')->count();return $steps[$index]??null;} return $workflow->steps[$workflow->current_stage]??null; }
    public function approvalHistory(int $leaveId): array { return LeaveApprovalAction::with('actor:id,name')->where('leave_request_id',$leaveId)->orderBy('stage_index')->get()->map(fn($a)=>['stage'=>$a->stage_name,'stage_index'=>$a->stage_index,'decision'=>$a->decision,'actor'=>$a->actor?->name,'comments'=>$a->comments,'decided_at'=>$a->decided_at?->toIso8601String()])->all(); }
    private function assertNoOverlap(string $employeeId, string $from, string $to, ?int $ignoreId = null): void {
        $q = LeaveRequest::where('employee_id',$employeeId)
            ->whereIn('status',['Pending','Approved'])
            ->where(function($g) use($from,$to){
                $g->whereBetween('from_date',[$from,$to])
                  ->orWhereBetween('to_date',[$from,$to])
                  ->orWhere(function($h) use($from,$to){ $h->where('from_date','<=',$from)->where('to_date','>=',$to); });
            });
        if($ignoreId) $q->where('id','!=',$ignoreId);
        if($q->exists()) throw ValidationException::withMessages(['from_date'=>'This application overlaps existing Pending/Approved leave.']);
    }
}
