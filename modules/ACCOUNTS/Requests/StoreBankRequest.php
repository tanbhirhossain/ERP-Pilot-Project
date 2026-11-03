<?php

namespace Modules\ACCOUNTS\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreBankRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can('accounts.banks.manage') ?? false; }
    public function rules(): array
    {
        $id = $this->route('bank')?->id;
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:20', $id ? "unique:acc_banks,code,{$id}" : 'unique:acc_banks,code'],
            'bank_name' => ['required', 'string', 'max:255'],
            'branch_name' => ['nullable', 'string', 'max:255'],
            'account_no' => ['required', 'string', 'max:100', $id ? "unique:acc_banks,account_no,{$id}" : 'unique:acc_banks,account_no'],
            'account_holder' => ['nullable', 'string', 'max:255'],
            'swift_code' => ['nullable', 'string', 'max:20'],
            'account_id' => ['required', 'exists:acc_accounts,id'],
            'currency' => ['nullable', 'string', 'size:3'],
            'opening_balance' => ['nullable', 'numeric'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
