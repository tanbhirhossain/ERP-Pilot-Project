<?php

namespace Modules\PURCHASE\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\INVENTORY\Models\Product;
use Modules\INVENTORY\Models\Warehouse;
use Modules\PARTNER\Models\Partner;
use Modules\PURCHASE\Models\PurchaseOrder;

class PurchaseDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (PurchaseOrder::exists()) {
            return;
        }
        $vendor = Partner::where('type', 'vendor')->orWhere('is_vendor', true)->first() ?? Partner::first();
        $warehouse = Warehouse::first();
        $product = Product::first();
        if (! $vendor || ! $warehouse || ! $product) {
            return;
        }

        $po = PurchaseOrder::create([
            'order_no' => 'PO-00001', 'vendor_id' => $vendor->id, 'warehouse_id' => $warehouse->id,
            'order_date' => now()->toDateString(), 'expected_date' => now()->addDays(7)->toDateString(), 'currency' => 'BDT',
            'subtotal' => 10000, 'discount_amount' => 0, 'vat_amount' => 0, 'total_amount' => 10000, 'status' => 'confirmed', 'created_by' => 1, 'confirmed_by' => 1, 'confirmed_at' => now(),
        ]);
        $po->items()->create([
            'product_id' => $product->id, 'product_name' => $product->name, 'sku' => $product->sku, 'uom_id' => $product->uom_id,
            'qty' => 10, 'unit_price' => 1000, 'discount_pct' => 0, 'discount_amount' => 0, 'vat_rate' => 0, 'vat_amount' => 0, 'line_total' => 10000,
        ]);
    }
}
