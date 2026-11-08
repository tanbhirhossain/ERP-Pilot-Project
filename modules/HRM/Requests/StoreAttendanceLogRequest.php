<?php

namespace Modules\HRM\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAttendanceLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['device_user_id' => ['required'], 'employee_id' => ['nullable', 'exists:employees,employee_id'], 'punch_time' => ['required', 'date'], 'device_id' => ['nullable', 'exists:biometric_devices,id'], 'punch_type' => ['nullable', 'in:IN,OUT,AUTO']];
    }
}
