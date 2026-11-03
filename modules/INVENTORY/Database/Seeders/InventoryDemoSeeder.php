<?php

namespace Modules\INVENTORY\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\INVENTORY\Models\Brand;
use Modules\INVENTORY\Models\Category;
use Modules\INVENTORY\Models\Product;
use Modules\INVENTORY\Models\Uom;
use Modules\INVENTORY\Models\Warehouse;

class InventoryDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (Product::count() > 20) return;

        // UOMs
        $pcs = Uom::firstOrCreate(['code' => 'PCS'], ['name' => 'Piece', 'symbol' => 'pcs', 'is_active' => true]);
        $kg = Uom::firstOrCreate(['code' => 'KG'], ['name' => 'Kilogram', 'symbol' => 'kg', 'is_active' => true]);
        Uom::firstOrCreate(['code' => 'MTR'], ['name' => 'Meter', 'symbol' => 'm', 'is_active' => true]);

        // Categories
        $catFabric = Category::firstOrCreate(['code' => 'FAB'], ['name' => 'Fabric', 'is_active' => true]);
        Category::firstOrCreate(['code' => 'ACC'], ['name' => 'Accessories', 'parent_id' => $catFabric->id, 'is_active' => true]);
        Category::firstOrCreate(['code' => 'CHEM'], ['name' => 'Chemicals', 'is_active' => true]);

        // Brands
        Brand::firstOrCreate(['code' => 'GEN'], ['name' => 'Generic', 'is_active' => true]);
        Brand::firstOrCreate(['code' => 'BEX'], ['name' => 'Beximco Textiles', 'is_active' => true]);

        // Warehouses
        $whMain = Warehouse::firstOrCreate(['code' => 'WH-001'], ['name' => 'Main Warehouse', 'type' => 'main', 'city' => 'Dhaka', 'is_default' => true, 'is_active' => true]);
        Warehouse::firstOrCreate(['code' => 'WH-002'], ['name' => 'Factory Warehouse', 'type' => 'sub', 'city' => 'Gazipur', 'is_active' => true]);

        // Products
        Product::factory()->count(15)->create(['uom_id' => $pcs->id, 'category_id' => $catFabric->id]);

        // Create a known product for testing POS/valuation
        $test = Product::firstOrCreate(['sku' => 'SKU-TEST001'], [
            'name' => 'Cotton Fabric — Premium White',
            'barcode' => '8801000000011',
            'category_id' => $catFabric->id,
            'uom_id' => $pcs->id,
            'type' => 'stockable',
            'cost_price' => 120.00,
            'selling_price' => 150.00,
            'mrp' => 160.00,
            'reorder_level' => 10,
            'is_active' => true,
            'status' => 'active',
        ]);

        // Opening stock for ALL products (P0 fix: avoid "Insufficient stock" for 15 SKUs)
        $allProducts = Product::all();
        $stockBatches = [];
        foreach ($allProducts as $prod) {
            if ($prod->stocks()->count() === 0) {
                $cost = (float) ($prod->cost_price ?: rand(50, 300));
                $qty = rand(50, 200);
                $stockBatches[] = ['product_id' => $prod->id, 'warehouse_id' => $whMain->id, 'qty' => $qty, 'unit_cost' => $cost];
            }
        }
        if (! empty($stockBatches)) {
            app(\Modules\INVENTORY\Actions\ReceiveStockAction::class)->execute($stockBatches, 'opening', 'OPEN-001', 'Demo opening stock — all SKUs');
        }
    }
}
