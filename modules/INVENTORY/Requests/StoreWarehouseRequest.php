<?php

namespace Modules\INVENTORY\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWarehouseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:20', 'unique:inventory_warehouses,code'],
            'short_code' => ['nullable', 'string', 'max:20'],
            'type' => ['required', Rule::in(['main', 'sub', 'transit', 'virtual'])],
            'address_line' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:100'],
            'district' => ['nullable', 'string', 'max:100'],
            'division' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'manager_user_id' => ['nullable', 'exists:users,id'],
            'is_default' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'allow_negative_stock' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'locations' => ['nullable', 'array', 'max:20'],
            'locations.*.name' => ['required_with:locations', 'string', 'max:255'],
            'locations.*.code' => ['required_with:locations', 'string', 'max:30'],
            'locations.*.type' => ['nullable', Rule::in(['receiving', 'storage', 'packing', 'shipping', 'qc', 'return', 'other'])],
        ];
    }
}
