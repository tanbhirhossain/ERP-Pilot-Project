<?php

namespace Modules\SALES\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDeliveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->user()?->can('sales.deliveries.manage') ?? true;
    }

    public function rules(): array
    {
        return [
            'order_id' => 'nullable|exists:sales_orders,id',
            'invoice_id' => 'nullable|exists:sales_invoices,id',
            'customer_id' => 'required|exists:partners,id',
            'warehouse_id' => 'required|exists:inventory_warehouses,id',
            'delivery_date' => 'required|date',
            'challan_no' => 'nullable|string|max:100',
            'vehicle_no' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:2000',
            'items' => 'required|array|min:1|max:100',
            'items.*.order_item_id' => 'nullable|exists:sales_order_items,id',
            'items.*.product_id' => 'required|exists:inventory_products,id',
            'items.*.qty' => 'required|numeric|min:0.01',
            'items.*.lot_no' => 'nullable|string|max:100',
            'items.*.serial_no' => 'nullable|string|max:100',
        ];
    }
}
