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
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'exists:inventory_products,id'],
            'items.*.qty' => ['required', 'numeric', 'min:0.01'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.discount_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
