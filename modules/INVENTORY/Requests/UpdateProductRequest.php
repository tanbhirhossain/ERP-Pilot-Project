<?php

namespace Modules\INVENTORY\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $id = $this->route('product')?->id ?? $this->route('id');
        return [
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:30', Rule::unique('inventory_products', 'sku')->ignore($id)],
            'barcode' => ['nullable', 'string', 'max:50', Rule::unique('inventory_products', 'barcode')->ignore($id)],
            'category_id' => ['nullable', 'exists:inventory_categories,id'],
            'brand_id' => ['nullable', 'exists:inventory_brands,id'],
            'uom_id' => ['nullable', 'exists:inventory_uoms,id'],
            'type' => ['required', Rule::in(['stockable', 'consumable', 'service', 'bundle'])],
            'description' => ['nullable', 'string', 'max:5000'],
            'cost_price' => ['required', 'numeric', 'min:0'],
            'selling_price' => ['required', 'numeric', 'min:0'],
            'mrp' => ['nullable', 'numeric', 'min:0'],
            'vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'reorder_level' => ['nullable', 'numeric', 'min:0'],
            'reorder_qty' => ['nullable', 'numeric', 'min:0'],
            'valuation_method' => ['required', Rule::in(['weighted', 'fifo'])],
            'is_active' => ['nullable', 'boolean'],
            'status' => ['required', Rule::in(['draft', 'active', 'discontinued'])],
            'image' => ['nullable', 'file', 'max:5120', 'mimes:jpg,jpeg,png,webp'],
            'suppliers' => ['nullable', 'array', 'max:10'],
            'suppliers.*.partner_id' => ['required_with:suppliers', 'exists:partners,id'],
        ];
    }
}
