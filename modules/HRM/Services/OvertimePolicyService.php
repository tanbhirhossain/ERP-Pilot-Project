<?php

namespace Modules\HRM\Services;

use Modules\HRM\Models\OvertimePolicy;

class OvertimePolicyService
{
    public function create(array $data): OvertimePolicy
    {
        return OvertimePolicy::create($data + ['created_by' => auth()->id()]);
    }

    public function update(OvertimePolicy $p, array $data): OvertimePolicy
    {
        $p->update($data);

        return $p->fresh();
    }

    public function toggle(OvertimePolicy $p): OvertimePolicy
    {
        $p->update(['is_active' => ! $p->is_active]);

        return $p->fresh();
    }

    public function activeFor(string $employmentType = 'All'): ?OvertimePolicy
    {
        return OvertimePolicy::where('is_active', true)->where(function ($q) use ($employmentType) {
            $q->where('employment_type', 'All')->orWhere('employment_type', $employmentType);
        })->latest('id')->first();
    }

    public function seedDefault(): void
    {
        if (OvertimePolicy::where('name', 'Standard OT Policy 2026')->exists()) {
            return;
        }
        OvertimePolicy::create(['name' => 'Standard OT Policy 2026', 'employment_type' => 'All', 'max_ot_hours_daily' => 3, 'max_ot_hours_monthly' => 36, 'ot_rate_multiplier' => 1.00, 'holiday_ot_rate' => 2.00, 'extra_duty_rate' => 1.00, 'requires_approval' => true, 'approval_levels' => 1, 'created_by' => auth()->id()]);
    }
}
