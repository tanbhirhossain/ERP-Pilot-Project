<?php

namespace Modules\SALES\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\RequiredIf;

class PosSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('pos.terminal.sell') ?? false;
    }

    public function rules(): array
    {
        $rules = static::rowRules();

        // One rule cannot live in the shared set below: it has to read a *sibling field of the row
        // being validated*, and `rowRules()` is static so it has no request to read. The offline sync
        // applies the same rule per row from the row's own array — see `PosOfflineSyncService`.
        $rules['customer_id'][] = Rule::requiredIf(fn (): bool => $this->input('payment_method') === 'due');

        return $rules;
    }

    /**
     * The rules for one ticket, with no dependence on which request carries them.
     *
     * Extracted rather than copied because the offline sync validates the same rows: a second literal
     * list of these rules is a second definition of what a legal sale is, and the two drift the first
     * time someone relaxes a bound or adds a field to the till. This is the only list.
     *
     * @return array<string, list<string|RequiredIf>>
     */
    public static function rowRules(): array
    {
        return [
            'warehouse_id' => ['required', 'exists:inventory_warehouses,id'],
            // A credit sale has to be owed by somebody: the day-end posting raises a receivable per
            // customer, and a debt against "Walk-in Customer" can never be aged, chased or collected.
            // Validated here as well as in the service, so the till gets a field error rather than a
            // 500 after the stock has already moved.
            'customer_id' => ['nullable', 'exists:partners,id'],
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
            /*
             * F22: the device reference. Optional on the live path and required on the sync path
             * (`PosOfflineSyncRequest`), because the till mints it when it *submits* — so pressing
             * Sell twice over a slow network is one sale, not two. That is not a nicety: before this,
             * the only thing standing between an impatient cashier and a double charge was the button's
             * own disabled state, which a 3-second response reliably outruns.
             */
            'client_ref' => ['nullable', 'uuid'],
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
