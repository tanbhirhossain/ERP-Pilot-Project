<?php

namespace Modules\HRM\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRosterDutyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'exists:employees,employee_id', Rule::unique('roster_duties', 'employee_id')->where(fn ($q) => $q->where('roster_date', $this->input('roster_date')))->ignore($this->duty)],
            'shift_id' => ['nullable', 'exists:roster_shifts,id'],
            'roster_date' => ['required', 'date'],
            'custom_start_datetime' => ['nullable', 'date'],
            'custom_end_datetime' => ['nullable', 'date', 'after:custom_start_datetime'],
            'status' => ['required', 'in:Scheduled,Working,Completed,Absent,Off,Swapped'],
            'remarks' => ['nullable', 'string'],
        ];
    }
}
