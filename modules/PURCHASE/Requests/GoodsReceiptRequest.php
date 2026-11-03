<?php

namespace Modules\PURCHASE\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GoodsReceiptRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can('purchase.receipts.manage') ?? false; }
    public function rules(): array
    {
        return [
            'order_id' => ['nullable','exists:purchase_orders,id'],
            'vendor_id' => ['required','exists:partners,id'],
            'warehouse_id' => ['required','exists:inventory_warehouses,id'],
            'receipt_date' => ['required','date'],
            'notes' => ['nullable','string','max:2000'],
            'items' => ['required','array','min:1','max:100'],
            'items.*.product_id' => ['required','exists:inventory_products,id'],
            'items.*.variant_id' => ['nullable','exists:inventory_product_variants,id'],
            'items.*.order_item_id' => ['nullable','exists:purchase_order_items,id'],
            'items.*.qty' => ['required','numeric','min:0.01'],
            'items.*.unit_price' => ['required','numeric','min:0'],
            'items.*.discount_pct' => ['nullable','numeric','min:0','max:100'],
            'items.*.vat_rate' => ['nullable','numeric','min:0','max:100'],
        ];
    }
}