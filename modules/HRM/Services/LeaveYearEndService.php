<?php

namespace Modules\HRM\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\HRM\Models\Employee\Employee;
use Modules\HRM\Models\Leave\LeaveType;
use Modules\HRM\Models\Leave\LeaveTypeSetting;
use Modules\HRM\Models\Leave\HrmLeaveBalance;

final class LeaveYearEndService
{
    public function __construct(private readonly LeaveBalanceService $balances) {}

    /**
     * Close year for all employees: compute availed/pending, carry-forward, lapsed, encashable.
     * Idempotent — re-running updates open balances, closed balances are skipped.
     */
    public function closeYear(int $year, ?int $actorId = null): array
    {
        return DB::transaction(function () use ($year, $actorId) {
            $types = LeaveType::with('setting')->get();
            $employees = Employee::where('is_active', true)->orWhere('status','Active')->get(['employee_id','id']);
            $created = 0; $updated = 0;
            foreach ($employees as $emp) {
                foreach ($types as $type) {
                    $setting = LeaveTypeSetting::firstOrCreate(['leave_type_id'=>$type->id]);
                    $balance = $this->balances->balance($emp->employee_id, $type->id, $year);
                    $existing = HrmLeaveBalance::where('employee_id',$emp->employee_id)->where('leave_type_id',$type->id)->where('year',$year)->first();
                    if ($existing && $existing->is_closed) continue;

                    $entitlement = $balance['entitlement'];
                    $availed = $balance['approved'];
                    $pending = $balance['pending'];
                    $unused = max(0, $entitlement - $availed - $pending);

                    $carry = 0; $lapsed = 0; $encashable = 0;
                    if ($setting->is_carry_forward) {
                        $carry = min($unused, (float)($setting->max_carry_days ?? $unused));
                        $lapsed = max(0, $unused - $carry);
                    } else {
                        $lapsed = $unused;
                    }
                    // Encashable is subset of unused (or lapsed if not carry)
                    if ($setting->is_encashable) {
                        $encashable = min($carry > 0 ? $carry : $unused, (float)($setting->encash_max_days ?? 999));
                    }

                    $payload = [
                        'employee_id' => $emp->employee_id, 'leave_type_id' => $type->id, 'year' => $year,
                        'opening_balance' => 0, // will be filled when next year's opening is seeded
                        'entitlement' => $entitlement, 'carry_forward' => $carry, 'availed' => $availed,
                        'pending' => $pending, 'encashed' => 0, 'lapsed' => $lapsed,
                        'available' => $unused, 'is_closed' => false,
                    ];
                    if ($existing) { $existing->update($payload); $updated++; }
                    else { HrmLeaveBalance::create($payload); $created++; }
                }
            }
            // Seed next year's opening from carry
            foreach ($employees as $emp) {
                foreach ($types as $type) {
                    $prev = HrmLeaveBalance::where('employee_id',$emp->employee_id)->where('leave_type_id',$type->id)->where('year',$year)->first();
                    if (!$prev) continue;
                    $next = HrmLeaveBalance::firstOrCreate(
                        ['employee_id'=>$emp->employee_id,'leave_type_id'=>$type->id,'year'=>$year+1],
                        ['opening_balance'=>0,'entitlement'=>0,'carry_forward'=>0,'availed'=>0,'pending'=>0,'encashed'=>0,'lapsed'=>0,'available'=>0]
                    );
                    // opening = carry_forward from prev
                    if ($prev->carry_forward > 0 && $next->opening_balance == 0) {
                        $next->update(['opening_balance' => $prev->carry_forward]);
                    }
                }
            }
            return ['year'=>$year,'created'=>$created,'updated'=>$updated,'employees'=>$employees->count(),'types'=>$types->count()];
        });
    }

    public function encashableForSettlement(string $employeeId, ?int $asOfYear = null): array
    {
        $year = $asOfYear ?? (int)now()->year;
        $balances = HrmLeaveBalance::with('leaveType')->where('employee_id',$employeeId)->where('year',$year)->get();
        // Fallback: live balance if no snapshot
        if ($balances->isEmpty()) {
            $types = LeaveType::all();
            $out = [];
            foreach ($types as $type) {
                $setting = LeaveTypeSetting::where('leave_type_id',$type->id)->first();
                if (!$setting || !$setting->is_encashable) continue;
                $bal = $this->balances->balance($employeeId, $type->id, $year);
                $unused = $bal['available'];
                $encash = min($unused, (float)($setting->encash_max_days ?? $unused));
                if ($encash > 0) $out[] = ['leave_type_id'=>$type->id,'leave_type'=>$type->name,'encash_days'=>$encash,'component'=>$setting->encash_salary_component ?? 'basic'];
            }
            return $out;
        }
        return $balances->filter(fn($b)=> $b->leaveType?->setting?->is_encashable ?? false)->map(fn($b)=>[
            'leave_type_id'=>$b->leave_type_id,'leave_type'=>$b->leaveType->name ?? "Leave #{$b->leave_type_id}",
            'encash_days'=> min((float)$b->available, (float)($b->leaveType->setting->encash_max_days ?? $b->available)),
            'component'=> $b->leaveType->setting->encash_salary_component ?? 'basic',
            'year'=>$b->year,
        ])->values()->all();
    }

    public function yearSummary(int $year): array
    {
        $rows = HrmLeaveBalance::where('year',$year)->selectRaw('leave_type_id, COUNT(*) as emp_count, SUM(entitlement) as tot_ent, SUM(availed) as tot_availed, SUM(carry_forward) as tot_carry, SUM(lapsed) as tot_lapsed')->groupBy('leave_type_id')->with('leaveType:id,name')->get();
        return $rows->map(fn($r)=>['leave_type'=>$r->leaveType->name ?? $r->leave_type_id,'emp_count'=>$r->emp_count,'entitlement'=>$r->tot_ent,'availed'=>$r->tot_availed,'carry'=>$r->tot_carry,'lapsed'=>$r->tot_lapsed])->all();
    }
}
