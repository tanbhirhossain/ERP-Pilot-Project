<?php

namespace Modules\ACCOUNTS\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreFiscalYearRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can('accounts.fiscal.manage') ?? false; }
    public function rules(): array
    {
        $id = $this->route('fiscalYear')?->id ?? $this->route('fiscal_year')?->id;
        return [
            'name' => ['required', 'string', 'max:100', $id ? "unique:acc_fiscal_years,name,{$id}" : 'unique:acc_fiscal_years,name'],
            'code' => ['required', 'string', 'max:20', $id ? "unique:acc_fiscal_years,code,{$id}" : 'unique:acc_fiscal_years,code'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
            'status' => ['sometimes', 'in:draft,open,closed,locked'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
