<?php

namespace Modules\SALES\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSalesReturnRequest extends FormRequest
{
    public function authorize(): bool { return auth()->user()?->can('sales.returns.manage') ?? true; }
    public function rules(): array
    {
        return [
            'invoice_id' => 'required|exists:sales_invoices,id',
            'return_date' => 'required|date',
            'reason' => 'nullable|string|max:500',
            'notes' => 'nullable|string|max:2000',
            'refund_method' => 'nullable|in:credit,cash,bank',
            'items' => 'required|array|min:1|max:100',
            'items.*.invoice_item_id' => 'required|exists:sales_invoice_items,id',
            'items.*.qty' => 'required|numeric|min:0.01',
            'items.*.condition' => 'required|in:resalable,damaged',
            'items.*.lot_no' => 'nullable|string|max:100',
        ];
    }
}
