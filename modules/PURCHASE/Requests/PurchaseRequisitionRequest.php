<?php

namespace Modules\PURCHASE\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * F18 — the requisition form.
 *
 * Two differences from `PurchaseOrderRequest`, both deliberate:
 *   - `product_id` is **nullable** and `description` is required, because a request is allowed to be for
 *     something the shop has never stocked. The product is what the award later has to pin down, not what
 *     the requester has to know.
 *   - `requested_by` is not accepted at all. Whoever is typing is the requester; letting a form name
 *     someone else would put another person's name on the line the approver trusts.
 */
class PurchaseRequisitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('purchase.requisitions.manage') ?? false;
    }

    protected function prepareForValidation(): void
    {
        // A blank line is what an unfinished row on the form posts as; dropping it here keeps the
        // "at least one line" rule honest instead of counting empty rows.
        $items = collect($this->input('items', []))
            ->filter(fn ($row): bool => is_array($row) && trim((string) ($row['description'] ?? '')) !== '')
            ->values()
            ->all();

        $this->merge(['items' => $items]);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:180'],
            'department' => ['nullable', 'string', 'max:120'],
            'warehouse_id' => ['required', 'integer', 'exists:inventory_warehouses,id'],
            'need_by' => ['nullable', 'date'],
            'justification' => ['nullable', 'string', 'max:2000'],
            'account_id' => ['nullable', 'integer', 'exists:acc_accounts,id'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['nullable', 'integer', 'exists:inventory_products,id'],
            'items.*.description' => ['required', 'string', 'max:180'],
            'items.*.uom_id' => ['nullable', 'integer', 'exists:inventory_uoms,id'],
            'items.*.qty' => ['required', 'numeric', 'min:0.001', 'max:9999999'],
            'items.*.expected_unit_price' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.required' => 'Add at least one line — a requisition with nothing in it is a memo.',
            'items.*.description.required' => 'Every line needs a description, even when it names a product.',
        ];
    }
}
