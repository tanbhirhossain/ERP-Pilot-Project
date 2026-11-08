<?php

namespace Modules\HRM\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEmployeeSupervisorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'exists:employees,employee_id'],
            'supervisor_kind' => ['required', 'in:user,employee'],
            'supervisor_id' => ['nullable', 'required_if:supervisor_kind,user', 'exists:users,id'],
            'supervisor_employee_id' => ['nullable', 'required_if:supervisor_kind,employee', 'exists:employees,employee_id', 'different:employee_id'],
            'supervisor_type' => ['required', 'in:Supervisor,Manager,Incharge,Reporting Manager,Department Head'],
            'is_active' => ['sometimes', 'boolean'],
            'is_primary' => ['sometimes', 'boolean'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('supervisor_kind') === 'user') {
            $this->merge(['supervisor_employee_id' => null]);
        }
        if ($this->input('supervisor_kind') === 'employee') {
            $this->merge(['supervisor_id' => null]);
        }
    }
}
