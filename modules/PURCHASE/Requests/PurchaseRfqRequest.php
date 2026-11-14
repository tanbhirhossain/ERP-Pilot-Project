<?php

namespace Modules\PURCHASE\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * F18 — an RFQ round: the subject and the lines being put to the market.
 */
class PurchaseRfqRequest extends FormRequest
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
            'requisition_id' => ['nullable', 'integer', 'exists:purchase_requisitions,id'],
            'subject' => ['required', 'string', 'max:180'],
            'due_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.requisition_item_id' => ['nullable', 'integer', 'exists:purchase_requisition_items,id'],
            'lines.*.description' => ['required', 'string', 'max:180'],
            'lines.*.qty' => ['required', 'numeric', 'min:0.001', 'max:9999999'],
            'lines.*.uom_id' => ['nullable', 'integer', 'exists:inventory_uoms,id'],
        ];
    }
}
