<?php

namespace Modules\PARTNER\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePriceListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:20', 'unique:price_lists,code'],
            'currency' => ['required', 'string', 'size:3'],
            'is_default' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['nullable', 'array', 'max:500'],
            'items.*.sku' => ['nullable', 'string', 'max:50'],
            'items.*.product_id' => ['nullable', 'integer'],
            'items.*.product_name' => ['nullable', 'string', 'max:255'],
            'items.*.unit_price' => ['required_with:items', 'numeric', 'min:0', 'max:9999999999.99'],
            'items.*.min_qty' => ['nullable', 'integer', 'min:1'],
            'items.*.discount_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
