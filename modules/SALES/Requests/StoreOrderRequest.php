<?php

namespace Modules\SALES\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can('sales.orders.manage') ?? false; }
    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'exists:partners,id'],
            'warehouse_id' => ['required', 'exists:inventory_warehouses,id'],
            'quotation_id' => ['nullable', 'exists:sales_quotations,id'],
            'order_date' => ['required', 'date'],
            'delivery_date' => ['nullable', 'date', 'after_or_equal:order_date'],
            'currency' => ['nullable', 'string', 'size:3'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'shipping_address' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_id' => ['required', 'exists:inventory_products,id'],
            'items.*.variant_id' => ['nullable', 'exists:inventory_product_variants,id'],
            'items.*.qty' => ['required', 'numeric', 'min:0.01'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.discount_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
