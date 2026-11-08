<?php

namespace Modules\HRM\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreLeaveTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', 'unique:leave_types,name'.($this->route('leave_type') ? ','.$this->route('leave_type')->id : '')],
            'code' => ['nullable', 'string', 'max:20'],
            'days_per_year' => ['required', 'integer', 'min:0', 'max:365'],
            'is_paid' => ['required', 'boolean'],
            'color' => ['nullable', 'string', 'max:20'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['required', 'boolean'],
            'day_count_mode' => ['nullable', 'in:calendar_days,working_days'],
            'accrual_mode' => ['nullable', 'in:annual,monthly'],
            'proration_mode' => ['nullable', 'in:none,monthly,daily'],
            'working_days_csv' => ['nullable', 'string', 'max:30'],
            'approval_steps_text' => ['nullable', 'string', 'max:300'],
        ];
    }
}
