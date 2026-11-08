<?php

namespace Modules\SALES\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\INVENTORY\Models\Product;
use Modules\INVENTORY\Models\Warehouse;
use Modules\PARTNER\Models\Partner;
use Modules\SALES\Models\PosSale;
use Modules\SALES\Models\Quotation;

class SalesDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (Quotation::exists()) {
            return;
        }
        $customer = Partner::where('type', 'customer')->orWhere('is_customer', true)->first() ?? Partner::first();
        $warehouse = Warehouse::first();
        $product = Product::first();
        if (! $customer || ! $warehouse || ! $product) {
            return;
        }

        $q = Quotation::create([
            'quotation_no' => 'QT-00001', 'customer_id' => $customer->id, 'quotation_date' => now()->toDateString(),
            'expiry_date' => now()->addDays(7)->toDateString(), 'currency' => 'BDT', 'subtotal' => 5000, 'discount_amount' => 0, 'vat_amount' => 0, 'total_amount' => 5000,
            'status' => 'accepted', 'notes' => 'Demo quotation', 'created_by' => 1,
        ]);
        $q->items()->create([
            'product_id' => $product->id, 'product_name' => $product->name, 'sku' => $product->sku, 'qty' => 2, 'unit_price' => 2500,
            'discount_pct' => 0, 'discount_amount' => 0, 'vat_rate' => 0, 'vat_amount' => 0, 'line_total' => 5000, 'sort_order' => 0,
        ]);
        // Demo POS sale
        if (! PosSale::exists()) {
            PosSale::create([
                'pos_no' => 'POS-00001', 'customer_name' => 'Walk-in Customer', 'warehouse_id' => $warehouse->id, 'sale_date' => now()->toDateString(),
                'subtotal' => 3000, 'total_amount' => 3000, 'paid_amount' => 3000, 'payment_method' => 'cash', 'cash_amount' => 3000, 'status' => 'completed', 'created_by' => 1,
            ]);
        }
    }
}
