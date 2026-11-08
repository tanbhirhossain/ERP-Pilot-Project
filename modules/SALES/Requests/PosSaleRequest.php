<?php

namespace Modules\SALES\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PosSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('pos.terminal.sell') ?? false;
    }

    public function rules(): array
    {
        return [
            'warehouse_id' => ['required', 'exists:inventory_warehouses,id'],
            // A credit sale has to be owed by somebody: the day-end posting raises a receivable per
            // customer, and a debt against "Walk-in Customer" can never be aged, chased or collected.
            // Validated here as well as in the service, so the till gets a field error rather than a
            // 500 after the stock has already moved.
            'customer_id' => [
                'nullable', 'exists:partners,id',
                Rule::requiredIf(fn (): bool => $this->input('payment_method') === 'due'),
            ],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'payment_method' => ['required', 'in:cash,bank,bkash,card,split,due'],
            'bank_id' => ['nullable', 'exists:acc_banks,id'],
            'cash_amount' => ['nullable', 'numeric', 'min:0'],
            'bank_amount' => ['nullable', 'numeric', 'min:0'],
            // Part payment on a credit sale, and the note handed over for change. Both are clamped
            // against the sale total in the service: the till must never be able to write a paid
            // amount larger than the sale, or a negative due.
            'paid_amount' => ['nullable', 'numeric', 'min:0'],
            'tendered_amount' => ['nullable', 'numeric', 'min:0'],
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
