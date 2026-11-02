<?php

namespace Modules\HRM\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkRosterAssignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_ids' => ['required', 'array', 'min:1'],
            'employee_ids.*' => ['exists:employees,employee_id'],
            'shift_id' => ['required', 'exists:roster_shifts,id'],
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date', 'after_or_equal:from_date'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if ($validator->errors()->has('from_date') || $validator->errors()->has('to_date')) {
                return;
            }

            $from = $this->input('from_date');
            $to = $this->input('to_date');

            if (! $from || ! $to) {
                return;
            }

            $days = (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days;
            if ($days > 60) {
                $validator->errors()->add('to_date', 'The roster range cannot exceed 60 days.');
            }
        });
    }
}
