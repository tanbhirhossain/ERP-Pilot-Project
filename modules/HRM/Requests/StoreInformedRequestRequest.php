<?php

namespace Modules\HRM\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreInformedRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'exists:employees,employee_id'],
            'informed_date' => ['required', 'date'],
            'informed_type' => ['required', 'in:'.implode(',', ['Late Coming', 'Early Out', 'Absent Informed', 'Field Work', 'On Duty', 'Other'])],
            'expected_time' => ['nullable', 'date_format:H:i'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
