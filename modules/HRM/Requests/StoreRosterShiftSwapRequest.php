<?php

namespace Modules\HRM\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRosterShiftSwapRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'roster_duty_id' => ['nullable', 'exists:roster_duties,id'],
            'requester_employee_id' => ['required', 'exists:employees,employee_id'],
            'target_employee_id' => ['nullable', 'exists:employees,employee_id', 'different:requester_employee_id'],
            'requested_shift_id' => ['nullable', 'exists:roster_shifts,id'],
            'target_shift_id' => ['nullable', 'exists:roster_shifts,id'],
            'swap_date' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
