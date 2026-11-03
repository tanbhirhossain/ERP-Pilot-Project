<?php

namespace Modules\SALES\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PosSaleRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can('pos.terminal.sell') ?? false; }
    public function rules(): array
    {
        return [
            'warehouse_id' => ['required', 'exists:inventory_warehouses,id'],
            'customer_id' => ['nullable', 'exists:partners,id'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'payment_method' => ['required', 'in:cash,bank,bkash,card,split'],
            'bank_id' => ['nullable', 'exists:acc_banks,id'],
            'cash_amount' => ['nullable', 'numeric', 'min:0'],
            'bank_amount' => ['nullable', 'numeric', 'min:0'],
            'session_id' => ['nullable', 'exists:pos_sessions,id'],
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
