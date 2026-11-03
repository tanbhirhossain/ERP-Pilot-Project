<?php

namespace Modules\INVENTORY\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTransferRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'from_warehouse_id' => ['required', 'exists:inventory_warehouses,id', 'different:to_warehouse_id'],
            'to_warehouse_id' => ['required', 'exists:inventory_warehouses,id'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_id' => ['required', 'exists:inventory_products,id'],
            'items.*.variant_id' => ['nullable', 'exists:inventory_product_variants,id'],
            'items.*.qty_requested' => ['required', 'numeric', 'min:0.0001'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
