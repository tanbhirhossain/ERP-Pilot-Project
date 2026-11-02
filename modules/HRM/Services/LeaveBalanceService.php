<?php
namespace Modules\HRM\Services;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Validation\ValidationException;
use Modules\HRM\Models\Employee\Employee;
use Modules\HRM\Models\Holiday;
use Modules\HRM\Models\Leave\LeaveRequest;
use Modules\HRM\Models\Leave\LeaveType;
use Modules\HRM\Models\Leave\LeaveTypeSetting;
class LeaveBalanceService {
    private array $settingsCache = [];

    public function settings(LeaveType $type): array {
        if (isset($this->settingsCache[$type->id])) return $this->settingsCache[$type->id];
        $setting=LeaveTypeSetting::firstOrCreate(['leave_type_id'=>$type->id],[]);
        return $this->settingsCache[$type->id] = ['day_count_mode'=>$setting->day_count_mode?:'calendar_days','accrual_mode'=>$setting->accrual_mode?:'annual','proration_mode'=>$setting->proration_mode?:'none','working_days'=>$setting->working_days??[0,1,2,3,4],'approval_steps'=>$setting->approval_steps?:['HR']];
    }
    public function days(string $employeeId, int $typeId, string $from, string $to, bool $half=false, ?LeaveType $loadedType=null, ?Employee $loadedEmployee=null, ?array $loadedHolidays=null): float {
        $type=$loadedType??LeaveType::findOrFail($typeId); $settings=$this->settings($type);
        $start=Carbon::parse($from)->startOfDay(); $end=Carbon::parse($to)->startOfDay();
        if($end->lt($start)) throw ValidationException::withMessages(['to_date'=>'The end date must be on or after the start date.']);
        if($start->year!==$end->year) throw ValidationException::withMessages(['to_date'=>'A leave application must be within one calendar year. Submit separate applications for each year.']);
        if($half && !$start->isSameDay($end)) throw ValidationException::withMessages(['is_half_day'=>'Half-day leave must be for a single date.']);
        if($settings['day_count_mode']==='calendar_days') return $half?0.5:(float)($start->diffInDays($end)+1);
        $employee=$loadedEmployee??Employee::with('department')->where('employee_id',$employeeId)->firstOrFail();
        $holidays=$loadedHolidays??Holiday::whereBetween('holiday_date',[$start->toDateString(),$end->toDateString()])->where(function($q)use($employee){$q->whereNull('department_id');if($employee->department_id)$q->orWhere('department_id',$employee->department_id);})->pluck('holiday_date')->map(fn($date)=>Carbon::parse($date)->toDateString())->all();
        $days=0; foreach(CarbonPeriod::create($start,$end) as $date){if(in_array($date->dayOfWeek,$settings['working_days'],true)&&!in_array($date->toDateString(),$holidays,true))$days++;}
        return $half?($days>0?0.5:0.0):(float)$days;
    }
    public function balance(string $employeeId, int $typeId, ?int $year=null): array {
        $year=$year??(int)now()->format('Y'); $type=LeaveType::findOrFail($typeId); $employee=Employee::where('employee_id',$employeeId)->firstOrFail(); $settings=$this->settings($type);
        $annual=(float)$type->days_per_year; $yearStart=Carbon::create($year,1,1)->startOfDay(); $yearEnd=Carbon::create($year,12,31)->endOfDay();
        $asOf=$year===(int)now()->year?now()->copy()->endOfDay():($year<(int)now()->year?$yearEnd: $yearStart->copy()->subDay());
        if($settings['accrual_mode']==='monthly'){
            $activeStart=$employee->joining_date?Carbon::parse($employee->joining_date)->max($yearStart):$yearStart;
            $months=$asOf->lt($activeStart)?0:($activeStart->diffInMonths($asOf)+1);
            $entitlement=round($annual*min(12,$months)/12,2);
        } elseif($settings['proration_mode']==='daily') {
            $activeStart=$employee->joining_date?Carbon::parse($employee->joining_date)->max($yearStart):$yearStart;
            $activeDays=$asOf->lt($activeStart)?0:($activeStart->diffInDays($asOf)+1);
            $entitlement=round($annual*$activeDays/($yearStart->isLeapYear()?366:365),2);
        } elseif($settings['proration_mode']==='monthly') {
            $activeStart=$employee->joining_date?Carbon::parse($employee->joining_date)->max($yearStart):$yearStart;
            $months=$activeStart->year>$year?0:(12-$activeStart->month+1);
            $entitlement=round($annual*min(12,$months)/12,2);
        } else $entitlement=$annual;
        $requests=LeaveRequest::where('employee_id',$employeeId)->where('leave_type_id',$typeId)->whereBetween('from_date',[$yearStart->toDateString(),$yearEnd->toDateString()])->whereIn('status',['Approved','Pending'])->get(['days','status']);
        $taken=round((float)$requests->where('status','Approved')->sum('days'),2); $pending=round((float)$requests->where('status','Pending')->sum('days'),2);
        return ['leave_type_id'=>$typeId,'leave_type'=>$type->name,'year'=>$year,'entitlement'=>$entitlement,'approved'=>$taken,'pending'=>$pending,'available'=>round(max(0,$entitlement-$taken-$pending),2),'calculation_mode'=>$settings['day_count_mode'],'accrual_mode'=>$settings['accrual_mode'],'proration_mode'=>$settings['proration_mode']];
    }
    public function assertAvailable(string $employeeId,int $typeId,string $from,string $to,bool $half=false,?int $ignoreId=null): float {
        $requested=$this->days($employeeId,$typeId,$from,$to,$half); $year=(int)Carbon::parse($from)->year; $balance=$this->balance($employeeId,$typeId,$year);
        if($ignoreId){$old=LeaveRequest::find($ignoreId);if($old&&$old->status==='Pending'&&$old->employee_id===$employeeId&&(int)$old->leave_type_id===$typeId)$balance['available']+=$old->days;}
        if($requested>$balance['available']) throw ValidationException::withMessages(['leave_type_id'=>"Insufficient leave balance. Available: {$balance['available']} day(s); requested: {$requested} day(s)."]);
        return $requested;
    }
}
