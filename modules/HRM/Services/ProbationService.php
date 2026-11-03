<?php

namespace Modules\HRM\Services;

use Illuminate\Support\Facades\DB;
use Modules\HRM\Models\EmployeeProbation;
use Modules\HRM\Models\Employee\Employee;

class ProbationService
{
    public function create(string $employeeId, array $data): EmployeeProbation
    {
        return DB::transaction(function () use ($employeeId, $data) {
            $emp = Employee::where('employee_id',$employeeId)->firstOrFail();
            if (EmployeeProbation::where('employee_id',$employeeId)->whereIn('status',['Active','Extended'])->exists()) {
                throw new \DomainException("Employee {$employeeId} already has an active probation.");
            }
            return EmployeeProbation::create([
                'employee_id'=>$employeeId,
                'probation_start'=>$data['probation_start'] ?? $emp->joining_date?->toDateString() ?? now()->toDateString(),
                'probation_end'=>$data['probation_end'],
                'remarks'=>$data['remarks'] ?? null,
                'status'=>'Active','created_by'=>auth()->id(),
            ]);
        });
    }

    public function extend(EmployeeProbation $probation, string $newEnd, ?string $remarks=null): EmployeeProbation
    {
        return DB::transaction(function () use ($probation, $newEnd, $remarks) {
            if (!$probation->isActive()) throw new \DomainException('Only Active/Extended probation can be extended.');
            $probation->update([
                'extended_till'=>$newEnd,
                'status'=>'Extended',
                'remarks'=>$remarks ?? $probation->remarks,
            ]);
            return $probation->fresh();
        });
    }

    public function confirm(EmployeeProbation $probation, ?string $remarks=null): EmployeeProbation
    {
        return DB::transaction(function () use ($probation, $remarks) {
            if (!$probation->isActive()) throw new \DomainException('Only Active/Extended can be confirmed.');
            $probation->update(['status'=>'Confirmed','confirmed_at'=>now()->toDateString(),'confirmed_by'=>auth()->id(),'remarks'=>$remarks ?? $probation->remarks]);
            // Promote employee employment_type if needed
            $emp = Employee::where('employee_id',$probation->employee_id)->first();
            if ($emp && $emp->employment_type === 'Probation') $emp->update(['employment_type'=>'Permanent']);
            return $probation->fresh();
        });
    }

    public function fail(EmployeeProbation $probation, string $remarks): EmployeeProbation
    {
        return DB::transaction(function () use ($probation, $remarks) {
            if (!$probation->isActive()) throw new \DomainException('Only Active/Extended can be failed.');
            $probation->update(['status'=>'Failed','remarks'=>$remarks,'confirmed_by'=>auth()->id(),'confirmed_at'=>now()->toDateString()]);
            return $probation->fresh();
        });
    }

    public function dueCounts(): array
    {
        return [
            'active'=>EmployeeProbation::whereIn('status',['Active','Extended'])->count(),
            'overdue'=>EmployeeProbation::whereIn('status',['Active','Extended'])->get()->filter(fn($p)=>$p->isOverdue())->count(),
            'due_7d'=>EmployeeProbation::whereIn('status',['Active','Extended'])->whereBetween('probation_end',[now()->toDateString(), now()->addDays(7)->toDateString()])->count(),
        ];
    }
}
