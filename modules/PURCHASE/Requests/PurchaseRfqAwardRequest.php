<?php

namespace Modules\PURCHASE\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * F18 — the award: a per-line choice of which quote won it.
 *
 * Deliberately *not* "award the cheapest": the screen pre-fills the recommendation and the request carries
 * what a human left there, so a split award, a preferred vendor, or a longer lead time accepted for a
 * lower price are all expressible — and all recorded line by line in `purchase_rfq_awards`.
 */
class PurchaseRfqAwardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('purchase.rfq.award') ?? false;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'warehouse_id' => ['nullable', 'integer', 'exists:inventory_warehouses,id'],
            'picks' => ['required', 'array', 'min:1', 'max:100'],
            'picks.*.rfq_line_id' => ['required', 'integer', 'distinct', 'exists:purchase_rfq_lines,id'],
            'picks.*.quote_id' => ['required', 'integer', 'exists:purchase_rfq_quotes,id'],
        ];
    }
}
