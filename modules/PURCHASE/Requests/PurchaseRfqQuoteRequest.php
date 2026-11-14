<?php

namespace Modules\PURCHASE\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * F18 — one vendor's answer, entered by whoever opened the envelope.
 *
 * `items` is the whole quote rather than a patch: a re-keyed price list replaces the previous one, so a
 * line that was dropped from the paper is dropped from the record too, instead of lingering as a stale
 * price that the comparison still sorts by.
 */
class PurchaseRfqQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('purchase.rfq.manage') ?? false;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'vendor_id' => ['required', 'integer', 'exists:partners,id'],
            'quote_ref' => ['nullable', 'string', 'max:80'],
            'quoted_on' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:quoted_on'],
            'lead_time_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.rfq_line_id' => ['required', 'integer', 'exists:purchase_rfq_lines,id'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'items.*.qty' => ['nullable', 'numeric', 'min:0.001', 'max:9999999'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
        ];
    }
}
