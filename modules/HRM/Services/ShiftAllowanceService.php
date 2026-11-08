<?php

namespace Modules\HRM\Services;

use Illuminate\Support\Facades\Log;
use Modules\HRM\Models\Roster\RosterDuty;
use Modules\HRM\Models\ShiftAllowance;

class ShiftAllowanceService
{
    public function create(array $data): ShiftAllowance
    {
        return ShiftAllowance::create($data + ['created_by' => auth()->id()]);
    }

    public function update(ShiftAllowance $sa, array $data): ShiftAllowance
    {
        $sa->update($data);

        return $sa->fresh();
    }

    public function forPayroll(string $employeeId, string $dutyDate): array
    {
        $duty = RosterDuty::where('employee_id', $employeeId)->where('duty_date', $dutyDate)->first();
        if (! $duty || ! $duty->shift_id) {
            Log::warning('ShiftAllowance skipped: no duty/shift', ['employee_id' => $employeeId, 'duty_date' => $dutyDate]);

            return [];
        }

        return ShiftAllowance::where('shift_id', $duty->shift_id)->where('is_active', true)
            ->where(function ($q) use ($dutyDate) {
                $q->whereNull('effective_from')->orWhere('effective_from', '<=', $dutyDate);
            })
            ->where(function ($q) use ($dutyDate) {
                $q->whereNull('effective_to')->orWhere('effective_to', '>=', $dutyDate);
            })
            ->get()->toArray();
    }
}
