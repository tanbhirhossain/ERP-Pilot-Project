<?php

namespace Modules\HRM\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRosterOvertimeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'exists:employees,employee_id'],
            'overtime_date' => ['required', 'date'],
            'overtime_type' => ['required', 'in:Overtime,Extra Duty,Holiday Duty'],
            'shift_id' => ['nullable', 'exists:roster_shifts,id'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'hours' => ['nullable', 'numeric', 'min:0', 'max:24'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
