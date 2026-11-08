<?php

namespace Modules\HRM\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['nullable', 'string', 'max:50', Rule::unique('employees', 'employee_id')],
            'biometric_id' => ['nullable', 'string', 'max:50', Rule::unique('employees', 'biometric_id')],
            'user_id' => ['nullable', 'exists:users,id'],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'nid' => ['nullable', 'string', 'max:50'],
            'gender' => ['nullable', 'in:Male,Female,Other'],
            'date_of_birth' => ['nullable', 'date'],
            'joining_date' => ['nullable', 'date'],
            'organization_id' => ['nullable', 'exists:organizations,id'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'designation_id' => ['nullable', 'exists:designations,id'],
            'employment_type' => ['required', 'in:Permanent,Contract,Probation,Intern,Part-time'],
            'status' => ['required', 'in:Active,Inactive,On Leave,Suspended,Terminated,Resigned'],
            'blood_group' => ['nullable', 'string'],
            'marital_status' => ['nullable', 'string'],
            'photo' => ['nullable', 'image', 'max:2048'],
            'address' => ['nullable', 'string'],
            // Excel real-data fields
            'file_no' => ['nullable', 'string', 'max:50'],
            'card_issue_date' => ['nullable', 'date'],
            'job_type' => ['nullable', 'string', 'max:50'],
            'joining_salary' => ['nullable', 'numeric', 'min:0'],
            'allow_overtime' => ['nullable', 'boolean'],
            'allow_bonus' => ['nullable', 'boolean'],
            'payment_criteria' => ['nullable', 'string', 'max:50'],
            'service_year' => ['nullable', 'integer', 'min:0', 'max:50'],
            // Profile - full
            'profile.father_name' => ['nullable', 'string', 'max:255'],
            'profile.mother_name' => ['nullable', 'string', 'max:255'],
            'profile.present_address' => ['nullable', 'string'],
            'profile.permanent_address' => ['nullable', 'string'],
            'profile.emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'profile.emergency_contact_phone' => ['nullable', 'string', 'max:50'],
            'profile.emergency_contact_relation' => ['nullable', 'string', 'max:100'],
            'profile.nationality' => ['nullable', 'string', 'max:100'],
            'profile.religion' => ['nullable', 'string', 'max:100'],
            'profile.bio' => ['nullable', 'string'],
            // Policy
            'policy.shift_type' => ['nullable', 'in:Fixed,Flexible,Custom'],
            'policy.grace_time_in' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'policy.grace_time_out' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'policy.is_punishable' => ['nullable', 'boolean'],
            'policy.duty_hours' => ['nullable', 'integer', 'in:6,7,8'],
            'policy.auto_shift' => ['nullable', 'boolean'],
            'policy.buffer_minutes' => ['nullable', 'integer', 'min:0', 'max:300'],
            'policy.allow_fallback' => ['nullable', 'boolean'],
            'policy.fallback_off_day' => ['nullable', 'integer', 'in:0,1,2,3,4,5,6'],
            // Salary - full
            'salary.basic_salary' => ['nullable', 'numeric', 'min:0'],
            'salary.gross_salary' => ['nullable', 'numeric', 'min:0'],
            'salary.house_rent' => ['nullable', 'numeric', 'min:0'],
            'salary.medical_allowance' => ['nullable', 'numeric', 'min:0'],
            'salary.other_allowance' => ['nullable', 'numeric', 'min:0'],
            'salary.conveyance_allowance' => ['nullable', 'numeric', 'min:0'],
            'salary.payment_method' => ['nullable', 'in:cash,bank'],
            'salary.bank_name' => ['nullable', 'string', 'max:255'],
            'salary.bank_account_no' => ['nullable', 'string', 'max:255'],
            'salary.net_salary' => ['nullable', 'numeric', 'min:0'],
            'salary.pay_grade' => ['nullable', 'string', 'max:50'],
            'salary.effective_from' => ['nullable', 'date'],
            'salary.effective_to' => ['nullable', 'date', 'after_or_equal:salary.effective_from'],
            'salary.is_current' => ['nullable', 'boolean'],
            'salary.remarks' => ['nullable', 'string'],
            // Education - repeatable
            'educations' => ['nullable', 'array'],
            'educations.*.degree' => ['nullable', 'string', 'max:255'],
            'educations.*.institute' => ['nullable', 'string', 'max:255'],
            'educations.*.board' => ['nullable', 'string', 'max:255'],
            'educations.*.passing_year' => ['nullable', 'string', 'max:10'],
            'educations.*.grade' => ['nullable', 'string', 'max:50'],
            'educations.*.gpa' => ['nullable', 'string', 'max:10'],
            'educations.*.description' => ['nullable', 'string'],
            // Experience - repeatable
            'experiences' => ['nullable', 'array'],
            'experiences.*.company_name' => ['nullable', 'string', 'max:255'],
            'experiences.*.designation' => ['nullable', 'string', 'max:255'],
            'experiences.*.department' => ['nullable', 'string', 'max:255'],
            'experiences.*.from_date' => ['nullable', 'date'],
            'experiences.*.to_date' => ['nullable', 'date', 'after_or_equal:experiences.*.from_date'],
            'experiences.*.is_current' => ['nullable', 'boolean'],
            'experiences.*.responsibilities' => ['nullable', 'string'],
            'experiences.*.description' => ['nullable', 'string'],
            // Skills - repeatable
            'skills' => ['nullable', 'array'],
            'skills.*.skill_name' => ['nullable', 'string', 'max:255'],
            'skills.*.proficiency' => ['nullable', 'in:Beginner,Intermediate,Advanced,Expert'],
            'skills.*.years_of_experience' => ['nullable', 'integer', 'min:0', 'max:50'],
            'skills.*.description' => ['nullable', 'string'],
        ];
    }
}
