<?php

namespace Modules\PURCHASE\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VendorBillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('purchase.bills.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'vendor_id' => ['required', 'exists:partners,id'],
            'warehouse_id' => ['required', 'exists:inventory_warehouses,id'],
            'order_id' => ['nullable', 'exists:purchase_orders,id'],
            'receipt_id' => ['nullable', 'exists:purchase_receipts,id'],
            'bill_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date'],
            'vendor_reference' => ['nullable', 'string', 'max:100'],
            'currency' => ['nullable', 'string', 'size:3'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'vat_recoverable' => ['nullable', 'boolean'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'exists:inventory_products,id'],
            'items.*.qty' => ['required', 'numeric', 'min:0.01'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.discount_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.line_type' => ['nullable', 'in:goods,fixed_asset'],
            'items.*.asset_category_id' => ['nullable', 'required_if:items.*.line_type,fixed_asset', 'exists:acc_asset_categories,id'],
            'items.*.asset_name' => ['nullable', 'string', 'max:150'],
            'items.*.asset_method' => ['nullable', 'in:straight_line,declining_balance'],
            'items.*.asset_useful_life_years' => ['nullable', 'integer', 'min:1', 'max:100'],
            'items.*.asset_salvage_value' => ['nullable', 'numeric', 'min:0'],
            'items.*.asset_db_factor' => ['nullable', 'numeric', 'min:0.01', 'max:10'],
            'items.*.asset_in_service_date' => ['nullable', 'date'],
            'items.*.asset_location' => ['nullable', 'string', 'max:160'],
        ];
    }
}
