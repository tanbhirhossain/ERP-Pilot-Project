<?php
namespace Modules\HRM\Repositories\Attendance;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\CORE\Repositories\Concerns\BuildsDatatableQueries;
use Modules\CORE\Support\DataTableQuery;
use Modules\HRM\Interfaces\Attendance\AttendanceDailyRepositoryInterface;
use Modules\HRM\Models\Attendance\AttendanceDaily;
use Modules\HRM\Models\Attendance\AttendancePair;
class AttendanceDailyRepository implements AttendanceDailyRepositoryInterface {
    use BuildsDatatableQueries;
    public function paginate(DataTableQuery $dt): LengthAwarePaginator {
        $q=$this->baseQuery();
        if($dt->search !== null && trim($dt->search) !== ''){
            $needle='%'.addcslashes(trim($dt->search),'%_').'%';
            $q->where(function(Builder $g) use($needle){
                $g->where('employee_id','like',$needle)
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
    public function findOrFail(int $id): AttendanceDaily { return AttendanceDaily::with(['employee','shift'])->findOrFail($id); }
    public function findByEmployeeAndDate(string $employeeId, string $dutyDate): ?AttendanceDaily { return AttendanceDaily::where('employee_id',$employeeId)->whereDate('duty_date',$dutyDate)->first(); }

    public function upsertForPair(AttendancePair $pair): AttendanceDaily {
        return DB::transaction(function() use($pair){
            $daily=AttendanceDaily::where('employee_id',$pair->employee_id)->where('duty_date',$pair->duty_date)->lockForUpdate()->first();
            if(!$daily){
                $daily = new AttendanceDaily(['employee_id'=>$pair->employee_id,'duty_date'=>$pair->duty_date]);
            }
            $daily->primary_shift_id = $daily->primary_shift_id ?? $pair->shift_id;
            $primaryPairs=AttendancePair::where('employee_id',$pair->employee_id)->where('duty_date',$pair->duty_date)->where('duty_type','Primary')->whereNotNull('punch_out')->get();
            $otPairs=AttendancePair::where('employee_id',$pair->employee_id)->where('duty_date',$pair->duty_date)->where('duty_type','Overtime')->whereNotNull('punch_out')->get();

            if($pair->duty_type==='Primary'){
                $allPrimary = AttendancePair::where('employee_id',$pair->employee_id)->where('duty_date',$pair->duty_date)->where('duty_type','Primary')->get();
                $earliestIn = $allPrimary->min('punch_in');
                $latestOut = $allPrimary->whereNotNull('punch_out')->max('punch_out');
                $daily->primary_punch_in = $earliestIn;
                $daily->primary_punch_out = $latestOut;
                $this->syncWorkedHours($daily, $pair, $primaryPairs, $otPairs);

                if(!$daily->expected_in_time){
                    $roster=\Modules\HRM\Models\Roster\RosterDuty::where('employee_id',$pair->employee_id)->whereDate('roster_date',$pair->duty_date)->first();
                    if($roster && $roster->shift){
                        $daily->expected_in_time=$roster->scheduled_start_time ?? $roster->shift->start_time;
                        $daily->expected_out_time=$roster->scheduled_end_time ?? $roster->shift->end_time;
                    } elseif($pair->shift){
                        $daily->expected_in_time=$pair->shift->start_time;
                        $daily->expected_out_time=$pair->shift->end_time;
                    }
                }
                $daily->status = $this->deriveStatus($daily, $pair);
            } else {
                $this->syncWorkedHours($daily, $pair, $primaryPairs, $otPairs);
                if(empty($daily->primary_punch_in)){
                    $daily->status = 'Extra Duty';
                }
                if(!$daily->primary_shift_id) $daily->primary_shift_id = $pair->shift_id;
            }
            $daily->save();
            try { $this->autoSyncOvertime($daily); } catch (\Throwable $e) { \Illuminate\Support\Facades\Log::warning('auto overtime failed', ['duty_date'=>$daily->duty_date,'employee_id'=>$daily->employee_id,'error'=>$e->getMessage()]); }
            return $daily;
        });
    }

    /** Split work beyond policy duty hours out of primary punches so daily overtime is a reliable payroll source. */
    private function syncWorkedHours(AttendanceDaily $daily, AttendancePair $pair, $primaryPairs, $otPairs): void
    {
        $rawPrimaryHours = round((float) $primaryPairs->sum('worked_hours'), 2);
        $explicitOvertimeHours = round((float) $otPairs->sum('worked_hours'), 2);
        $isExtraDay = $daily->status === 'Extra Duty' || $this->isOffOrHoliday($daily, $pair);

        if ($isExtraDay) {
            $daily->primary_worked_hours = $rawPrimaryHours;
            $daily->total_overtime_hours = $explicitOvertimeHours;
        } else {
            $policy = \Modules\HRM\Models\Employee\EmployeePolicy::query()->where('employee_id', $daily->employee_id)->first();
            $employeeDutyHours = \Modules\HRM\Models\Employee\Employee::query()->where('employee_id', $daily->employee_id)->value('duty_hours');
            $dutyHours = max(0.0, (float) ($policy?->duty_hours ?? $employeeDutyHours ?? 8));
            $primaryExcess = round(max(0.0, $rawPrimaryHours - $dutyHours), 2);
            // Keep primary_worked_hours as observed time; total_overtime_hours is the eligible subset above duty.
            $daily->primary_worked_hours = $rawPrimaryHours;
            $daily->total_overtime_hours = round($explicitOvertimeHours + $primaryExcess, 2);
        }

        // Grand total always remains the real total worked time; never add the reclassified OT twice.
        $daily->grand_total_hours = round($rawPrimaryHours + $explicitOvertimeHours, 2);
    }

    private function autoSyncOvertime(AttendanceDaily $daily): void
    {
        $worked=(float)($daily->grand_total_hours ?? 0);
        if($worked<=0) return;
        $emp=\Modules\HRM\Models\Employee\Employee::with('department')->where('employee_id',$daily->employee_id)->first();
        $roster=\Modules\HRM\Models\Roster\RosterDuty::where('employee_id',$daily->employee_id)->whereDate('roster_date',$daily->duty_date)->first();
        $holiday=\Modules\HRM\Models\Holiday::whereDate('holiday_date',$daily->duty_date)
            ->where(function($q) use($emp){ $q->whereNull('department_id'); if($emp?->department_id) $q->orWhere('department_id',$emp->department_id); })
            ->exists();
        $fallbackDay = \Modules\HRM\Models\Employee\EmployeeFallbackOffDay::where('employee_id',$daily->employee_id)->value('day_of_week');
        $fallbackOff = !$roster && $fallbackDay !== null && (int) $fallbackDay === \Carbon\Carbon::parse($daily->duty_date)->dayOfWeek;
        $isExtra=$daily->status==='Extra Duty' || $holiday || $roster?->status==='Off' || $fallbackOff;
        // The normal overtime opt-in must not suppress separately classified holiday/off-day Extra Duty.
        if (!$isExtra && $emp && $emp->allow_overtime !== null && !(bool) $emp->allow_overtime) return;
        $policy=\Modules\HRM\Models\Employee\EmployeePolicy::where('employee_id',$daily->employee_id)->first();
        $dutyHours=(float)($policy?->duty_hours ?? $emp?->duty_hours ?? 8);
        // A complete rostered shift yields normal OT only when it exceeds duty by >30 minutes.
        // Work on a holiday/off-day is classified separately and the whole completed work time is eligible.
        if(!$isExtra && empty($daily->primary_punch_out)) return;
        $hours=$isExtra?$worked:round($worked-$dutyHours,2);
        if(($isExtra && $hours<=0) || (!$isExtra && $hours<=0.5)){
            \Modules\HRM\Models\Roster\RosterOvertime::where('employee_id',$daily->employee_id)->whereDate('overtime_date',$daily->duty_date)->where('status','Pending')->where('reason','like','Auto-generated%')->delete();
            return;
        }
        $existing=\Modules\HRM\Models\Roster\RosterOvertime::where('employee_id',$daily->employee_id)->whereDate('overtime_date',$daily->duty_date)->whereIn('status',['Pending','Approved'])->first();
        if($existing){
            if($existing->status==='Pending' && str_starts_with((string)$existing->reason,'Auto-generated')){
                $existing->update(['hours'=>$hours,'overtime_type'=>$isExtra?'Extra Duty':'Overtime']);
            }
            return;
        }
        $startTime=$daily->expected_out_time?substr((string)$daily->expected_out_time,0,5):null;
        if($isExtra && $daily->primary_punch_in){ try{$startTime=\Carbon\Carbon::parse($daily->primary_punch_in)->format('H:i');}catch(\Throwable $e){}}
        elseif(!$startTime && $daily->primary_punch_in){ try{$startTime=\Carbon\Carbon::parse($daily->primary_punch_in)->addHours($dutyHours)->format('H:i');}catch(\Throwable $e){}}
        $endTime=null;
        if($daily->primary_punch_out){try{$endTime=\Carbon\Carbon::parse($daily->primary_punch_out)->format('H:i');}catch(\Throwable $e){}}
        \Modules\HRM\Models\Roster\RosterOvertime::create([
            'employee_id'=>$daily->employee_id,'overtime_date'=>$daily->duty_date,'overtime_type'=>$isExtra?'Extra Duty':'Overtime',
            'shift_id'=>$daily->primary_shift_id,'start_time'=>$startTime,'end_time'=>$endTime,'hours'=>$hours,
            'reason'=>'Auto-generated from attendance — worked '.$worked.'h'.($isExtra?' on holiday/off day':" vs duty {$dutyHours}h (excess {$hours}h)"),'status'=>'Pending',
        ]);
    }

    private function deriveStatus(AttendanceDaily $daily, AttendancePair $pair): string {
        if (($daily->primary_punch_in || $daily->primary_punch_out) && $this->isOffOrHoliday($daily, $pair)) return 'Extra Duty';
        if(!$daily->primary_punch_in) return 'Missing In';
        if(!$daily->primary_punch_out) return 'Missing Out';
        $policy=\Modules\HRM\Models\Employee\EmployeePolicy::where('employee_id',$pair->employee_id)->first();
        $isLate = false; $isEarly = false;
        $graceIn = (int)($policy?->grace_time_in ?? $policy?->late_grace ?? 15);
        $graceOut = (int)($policy?->grace_time_out ?? 15);
        $parseExpected = function($val){
            if (!$val) return null;
            $s = trim((string)$val);
            if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?/', $s, $m)) {
                $h = str_pad($m[1],2,'0',STR_PAD_LEFT); $mi=$m[2]; $sec=$m[3]??'00';
                $dt = \DateTime::createFromFormat('H:i:s', "$h:$mi:$sec");
                if ($dt) return $dt;
            }
            try { return new \DateTime($s); } catch (\Throwable $e){ return null; }
        };
        if($daily->expected_in_time && $daily->primary_punch_in){
            try {
                $expected=$parseExpected($daily->expected_in_time);
                $actual=new \DateTime($daily->primary_punch_in);
                $actualTime= \DateTime::createFromFormat('H:i:s', $actual->format('H:i:s'));
                if($expected && $actualTime){
                    $lateMinutes=($actualTime->getTimestamp() - $expected->getTimestamp())/60;
                    if($lateMinutes > $graceIn) $isLate = true;
                }
            } catch (\Throwable $e){}
        }
        if($daily->expected_out_time && $daily->primary_punch_out){
            try {
                $expected=$parseExpected($daily->expected_out_time);
                $actual=new \DateTime($daily->primary_punch_out);
                $actualTime= \DateTime::createFromFormat('H:i:s', $actual->format('H:i:s'));
                if($expected && $actualTime){
                    $earlyMinutes=($expected->getTimestamp() - $actualTime->getTimestamp())/60;
                    if($earlyMinutes > $graceOut) $isEarly = true;
                }
            } catch (\Throwable $e){}
        }
        if($isLate && $isEarly) return 'Late & Early Out';
        if($isLate) return 'Late';
        if($isEarly) return 'Early Out';
        return 'Present';
    }
    private function isOffOrHoliday(AttendanceDaily $daily, AttendancePair $pair): bool {
        $roster = \Modules\HRM\Models\Roster\RosterDuty::query()->where('employee_id', $daily->employee_id)->whereDate('roster_date', $daily->duty_date)->first();
        if ($roster?->status === 'Off') return true;
        if (!$roster) {
            $fallback = \Modules\HRM\Models\Employee\EmployeeFallbackOffDay::where('employee_id', $daily->employee_id)->value('day_of_week');
            if ($fallback !== null && (int) $fallback === \Carbon\Carbon::parse($daily->duty_date)->dayOfWeek) return true;
        }
        $employee = \Modules\HRM\Models\Employee\Employee::query()->where('employee_id', $daily->employee_id)->first(['department_id']);
        return \Modules\HRM\Models\Holiday::query()->whereDate('holiday_date', $daily->duty_date)->where(function ($q) use ($employee): void {
            $q->whereNull('department_id');
            if ($employee?->department_id) $q->orWhere('department_id', $employee->department_id);
        })->exists();
    }

    public function delete(AttendanceDaily $d): void { $d->delete(); }
    private function baseQuery(): Builder { return AttendanceDaily::query()->with(['employee:id,employee_id,full_name,department_id,designation_id','employee.department:id,name','employee.designation:id,name','shift:id,shift_name']); }
    private function searchColumns(): array { return ['employee_id']; }
    private function sortable(): array { return ['id'=>'id','duty_date'=>'duty_date','grand_total_hours'=>'grand_total_hours','created_at'=>'created_at']; }
    private function filters(): array {
        return [
            'employee_id'=>fn(Builder $q,string $v)=>$q->where('employee_id',$v),
            'status'=>fn(Builder $q,string $v)=>$q->where('status',$v),
            'duty_date'=>fn(Builder $q,string $v)=>$q->where('duty_date',$v),
            'from'=>fn(Builder $q,string $v)=>$q->whereDate('duty_date','>=',$v),
            'to'=>fn(Builder $q,string $v)=>$q->whereDate('duty_date','<=',$v),
            'department_id'=>fn(Builder $q,string $v)=>$q->whereHas('employee', fn($qq)=>$qq->where('department_id',$v)),
            'designation_id'=>fn(Builder $q,string $v)=>$q->whereHas('employee', fn($qq)=>$qq->where('designation_id',$v)),
        ];
    }
}
