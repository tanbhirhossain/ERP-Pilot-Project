<?php

namespace Modules\ACCOUNTS\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('accounts.coa.manage') ?? false;
    }

    public function rules(): array
    {
        $id = $this->route('account')?->id;

        return [
            'code' => ['required', 'string', 'max:20', $id ? "unique:acc_accounts,code,{$id}" : 'unique:acc_accounts,code'],
            'name' => ['required', 'string', 'max:255'],
            'name_bn' => ['nullable', 'string', 'max:255'],
            'account_type' => ['required', 'in:asset,liability,equity,income,expense'],
            'nature' => ['required', 'in:debit,credit'],
            'parent_id' => ['nullable', 'exists:acc_accounts,id'],
            'is_group' => ['sometimes', 'boolean'],
            'is_system' => ['sometimes', 'boolean'],
            'currency' => ['nullable', 'string', 'size:3'],
            'opening_balance' => ['nullable', 'numeric', 'min:0'],
            'opening_side' => ['nullable', 'in:debit,credit'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
