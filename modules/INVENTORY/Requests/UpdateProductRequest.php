<?php

namespace Modules\INVENTORY\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\INVENTORY\Requests\Concerns\PrunesEmptyRepeaterRows;

class UpdateProductRequest extends FormRequest
{
    use PrunesEmptyRepeaterRows;

    public function authorize(): bool
    {
        return true;
    }

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
            'short_description' => ['nullable', 'string', 'max:500'],
            'hs_code' => ['nullable', 'string', 'max:30'],
            'weight' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'is_serialized' => ['nullable', 'boolean'],
            'is_batch_tracked' => ['nullable', 'boolean'],
            'shelf_life_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'is_featured' => ['nullable', 'boolean'],
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
            'suppliers.*.supplier_sku' => ['nullable', 'string', 'max:50'],
            'suppliers.*.last_purchase_price' => ['nullable', 'numeric', 'min:0'],
            'suppliers.*.is_primary' => ['nullable', 'boolean'],
            // The Edit page has shown an editable Variants tab all along, but with no rules here
            // validated() stripped it and syncRelations() never ran — edits were silently discarded.
            'variants' => ['nullable', 'array', 'max:20'],
            'variants.*.id' => ['nullable', 'integer'],
            'variants.*.name' => ['required_with:variants', 'string', 'max:255'],
            'variants.*.sku' => ['nullable', 'string', 'max:30'],
            'variants.*.barcode' => ['nullable', 'string', 'max:50'],
            'variants.*.cost_price' => ['nullable', 'numeric', 'min:0'],
            'variants.*.selling_price' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'category_id' => $this->input('category_id') ?: null,
            'brand_id' => $this->input('brand_id') ?: null,
            'uom_id' => $this->input('uom_id') ?: null,
        ]);

        $this->pruneEmptyRows(['variants', 'suppliers', 'bom']);
    }
}
