<?php

namespace Modules\HRM\Services;

use Illuminate\Support\Facades\DB;
use Modules\HRM\Models\ChecklistTemplate;
use Modules\HRM\Models\EmployeeChecklist;
use Modules\HRM\Models\EmployeeChecklistProgress;

class ChecklistService
{
    public function createTemplate(array $data): ChecklistTemplate
    {
        return DB::transaction(function () use ($data) {
            $tpl = ChecklistTemplate::create([
                'name'=>$data['name'],'category'=>$data['category'],'employment_type'=>$data['employment_type']??null,
                'department_id'=>$data['department_id']??null,'description'=>$data['description']??null,'created_by'=>auth()->id(),
            ]);
            foreach (($data['items'] ?? []) as $i => $it) {
                $tpl->items()->create(['title'=>$it['title'],'description'=>$it['description']??null,'responsible_role'=>$it['responsible_role']??'HR','sort_order'=>$it['sort_order']??$i,'is_required'=>$it['is_required']??true]);
            }
            return $tpl->load('items');
        });
    }

    public function assign(string $employeeId, int $templateId, ?int $movementId=null, ?int $resignationId=null): EmployeeChecklist
    {
        return DB::transaction(function () use ($employeeId,$templateId,$movementId,$resignationId) {
            $tpl = ChecklistTemplate::with('items')->findOrFail($templateId);
            if (EmployeeChecklist::where('employee_id',$employeeId)->where('template_id',$templateId)->where('status','!=','Cancelled')->exists()) {
                throw new \DomainException("Employee {$employeeId} already has this checklist assigned.");
            }
            $ec = EmployeeChecklist::create(['employee_id'=>$employeeId,'template_id'=>$templateId,'movement_id'=>$movementId,'resignation_id'=>$resignationId,'created_by'=>auth()->id()]);
            foreach ($tpl->items as $item) $ec->progress()->create(['checklist_item_id'=>$item->id]);
            $ec->recalcProgress();
            return $ec->load(['template','progress.item']);
        });
    }

    public function toggleProgress(int $progressId, string $status, ?string $remarks=null): EmployeeChecklistProgress
    {
        return DB::transaction(function () use ($progressId,$status,$remarks) {
            if (!in_array($status,['Pending','Done','Skipped','N/A'],true)) throw new \InvalidArgumentException('Invalid status');
            $p = EmployeeChecklistProgress::findOrFail($progressId);
            $p->update(['status'=>$status,'remarks'=>$remarks,'completed_by'=>auth()->id(),'completed_at'=>$status==='Done'?now():null]);
            $p->checklist->recalcProgress();
            return $p->fresh();
        });
    }

    public function seedDefaults(): void
    {
        $exists = ChecklistTemplate::where('name','Standard Onboarding — Permanent')->exists();
        if ($exists) return;
        $on = $this->createTemplate(['name'=>'Standard Onboarding — Permanent','category'=>'Onboarding','description'=>'Day 1 → Day 7 for all permanent hires','items'=>[
            ['title'=>'HR: Offer & Appointment Letter','responsible_role'=>'HR','sort_order'=>1],
            ['title'=>'IT: Email + ERP account','responsible_role'=>'IT','sort_order'=>2],
            ['title'=>'Admin: ID Card + Access Card','responsible_role'=>'Admin','sort_order'=>3],
            ['title'=>'Manager: Role induction','responsible_role'=>'Manager','sort_order'=>4],
            ['title'=>'Payroll: Salary profile + bank/BKash','responsible_role'=>'Payroll','sort_order'=>5],
            ['title'=>'Safety: EHS briefing','responsible_role'=>'HR','sort_order'=>6],
        ]]);
        $this->createTemplate(['name'=>'Standard Offboarding — Resignation','category'=>'Offboarding','description'=>'Resignation → last working day clearance','items'=>[
            ['title'=>'Manager: Handover document','responsible_role'=>'Manager','sort_order'=>1],
            ['title'=>'IT: Revoke access, collect laptop/mobile','responsible_role'=>'IT','sort_order'=>2],
            ['title'=>'Admin: Collect ID/Uniform/Locker','responsible_role'=>'Admin','sort_order'=>3],
            ['title'=>'Finance: Asset recovery verification','responsible_role'=>'Finance','sort_order'=>4],
            ['title'=>'HR: Exit interview','responsible_role'=>'HR','sort_order'=>5],
            ['title'=>'Payroll: Settlement calculation','responsible_role'=>'Payroll','sort_order'=>6],
        ]]);
    }
}
