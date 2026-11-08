<?php

namespace Modules\INVENTORY\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'warehouse_id' => ['required', 'exists:inventory_warehouses,id'],
            'type' => ['required', Rule::in(['increase', 'decrease', 'damage', 'expired', 'found', 'correction'])],
            'reason' => ['required', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_id' => ['required', 'exists:inventory_products,id'],
            'items.*.variant_id' => ['nullable', 'exists:inventory_product_variants,id'],
            'items.*.location_id' => ['nullable', 'exists:inventory_locations,id'],
            'items.*.qty' => ['required', 'numeric', 'min:0.0001'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'items.*.reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
