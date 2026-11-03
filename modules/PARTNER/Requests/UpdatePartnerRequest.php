<?php

namespace Modules\PARTNER\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePartnerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $partnerId = $this->route('partner')?->id ?? $this->route('vendor')?->id ?? $this->route('customer')?->id;

        return [
            'type' => ['required', Rule::in(['vendor', 'customer', 'both'])],
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'partner_code' => ['nullable', 'string', 'max:20', Rule::unique('partners', 'partner_code')->ignore($partnerId)],
            'group_id' => ['nullable', 'exists:partner_groups,id'],
            'price_list_id' => ['nullable', 'exists:price_lists,id'],

            'trade_license' => ['nullable', 'string', 'max:100'],
            'tin' => ['nullable', 'string', 'max:50'],
            'bin' => ['nullable', 'string', 'max:50'],
            'vat_reg_no' => ['nullable', 'string', 'max:50'],
            'nid' => ['nullable', 'string', 'max:30'],

            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'url', 'max:255'],

            'credit_limit' => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'payment_terms' => ['nullable', 'string', 'max:255'],
            'opening_balance' => ['nullable', 'numeric', 'min:-9999999999999.99', 'max:9999999999999.99'],
            'currency' => ['nullable', 'string', 'size:3'],

            'is_active' => ['nullable', 'boolean'],
            'assigned_to' => ['nullable', 'exists:users,id'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'internal_notes' => ['nullable', 'string', 'max:5000'],

            'contacts' => ['nullable', 'array', 'max:10'],
            'contacts.*.name' => ['required_with:contacts', 'string', 'max:255'],
            'contacts.*.designation' => ['nullable', 'string', 'max:100'],
            'contacts.*.phone' => ['nullable', 'string', 'max:30'],
            'contacts.*.email' => ['nullable', 'email', 'max:255'],
            'contacts.*.is_primary' => ['nullable', 'boolean'],
            'contacts.*.can_receive_statement' => ['nullable', 'boolean'],

            'addresses' => ['nullable', 'array', 'max:10'],
            'addresses.*.type' => ['required_with:addresses', Rule::in(['billing', 'shipping', 'factory', 'warehouse', 'branch', 'other'])],
            'addresses.*.label' => ['nullable', 'string', 'max:100'],
            'addresses.*.address_line' => ['required_with:addresses', 'string', 'max:500'],
            'addresses.*.area' => ['nullable', 'string', 'max:100'],
            'addresses.*.city' => ['nullable', 'string', 'max:100'],
            'addresses.*.district' => ['nullable', 'string', 'max:100'],
            'addresses.*.division' => ['nullable', 'string', 'max:100'],
            'addresses.*.country' => ['nullable', 'string', 'max:100'],
            'addresses.*.postal_code' => ['nullable', 'string', 'max:20'],
            'addresses.*.is_default' => ['nullable', 'boolean'],

            'banks' => ['nullable', 'array', 'max:5'],
            'banks.*.bank_name' => ['required_with:banks', 'string', 'max:255'],
            'banks.*.branch' => ['nullable', 'string', 'max:255'],
            'banks.*.account_name' => ['nullable', 'string', 'max:255'],
            'banks.*.account_no' => ['required_with:banks', 'string', 'max:50'],
            'banks.*.routing_no' => ['nullable', 'string', 'max:20'],
            'banks.*.swift_code' => ['nullable', 'string', 'max:20'],
            'banks.*.is_primary' => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'group_id' => $this->input('group_id') ?: null,
            'price_list_id' => $this->input('price_list_id') ?: null,
            'assigned_to' => $this->input('assigned_to') ?: null,
        ]);
    }
}
