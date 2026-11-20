<?php

namespace Modules\INVENTORY\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\INVENTORY\Actions\ReceiveStockAction;
use Modules\INVENTORY\Models\Brand;
use Modules\INVENTORY\Models\Category;
use Modules\INVENTORY\Models\Product;
use Modules\INVENTORY\Models\Uom;
use Modules\INVENTORY\Models\Warehouse;

class InventoryDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (Product::count() > 20) {
            return;
        }

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
        //
        // These were `Product::factory()->count(15)` — Faker's Latin lorem ipsis, which is what every
        // Laravel demo ships and what makes a demo look like a demo. "Adipisci Enim Id" is not a
        // product, so a screenshot of it proves nothing to anyone evaluating the software: the
        // reader spends their attention decoding the name instead of judging the screen, and the
        // prices were random numbers unrelated to the merchandise.
        //
        // Real items with plausible costs let a buyer test the thing that actually matters — the
        // margin. A cotton panjabi bought at 1,180 and sold at 1,690 is a story the reader can
        // check; the same row labelled "Quidem Aut Veritatis" is noise.
        $catalogue = [
            ['Cotton Panjabi — Off White', 'PNJ-001', 1180.00, 1690.00, 'pcs'],
            ['Cotton Panjabi — Navy', 'PNJ-002', 1180.00, 1690.00, 'pcs'],
            ['Printed Cotton Saree — Tangail', 'SAR-001', 2450.00, 3600.00, 'pcs'],
            ['Jamdani Saree — Half Silk', 'SAR-002', 4800.00, 7200.00, 'pcs'],
            ['Mens Formal Shirt — Sky Blue', 'SHT-001', 880.00, 1290.00, 'pcs'],
            ['Mens Formal Shirt — White', 'SHT-002', 880.00, 1290.00, 'pcs'],
            ['Denim Pant — Slim Fit', 'PNT-001', 1650.00, 2450.00, 'pcs'],
            ['Cotton Churidar — Black', 'CHR-001', 720.00, 1090.00, 'pcs'],
            ['Three Piece Salwar Kameez — Embroidered', 'TPC-001', 3200.00, 4750.00, 'pcs'],
            ['Kids T-Shirt — Assorted Print', 'KTS-001', 240.00, 390.00, 'pcs'],
            ['Terry Towel — Bath Size', 'TWL-001', 340.00, 520.00, 'pcs'],
            ['Bed Sheet Set — Queen', 'BDS-001', 1250.00, 1850.00, 'pcs'],
            ['Cotton Fabric — Premium White', 'FAB-001', 210.00, 310.00, 'mtr'],
            ['Denim Fabric — 12 oz', 'FAB-002', 385.00, 540.00, 'mtr'],
            ['Muslin Fabric — Fine Weave', 'FAB-003', 460.00, 650.00, 'mtr'],
        ];

        $uomByCode = ['pcs' => $pcs->id, 'mtr' => Uom::firstOrCreate(['code' => 'MTR'], ['name' => 'Meter', 'symbol' => 'm', 'is_active' => true])->id];

        foreach ($catalogue as [$name, $code, $cost, $price, $uom]) {
            Product::firstOrCreate(['sku' => $code], [
                'name' => $name,
                'barcode' => '8801'.str_pad((string) (crc32($code) % 10000000000), 10, '0', STR_PAD_LEFT),
                'category_id' => $catFabric->id,
                'uom_id' => $uomByCode[$uom],
                'type' => 'stockable',
                'cost_price' => $cost,
                'selling_price' => $price,
                'mrp' => round($price * 1.08, 2),
                'reorder_level' => 20,
                'is_active' => true,
                'status' => 'active',
            ]);
        }

        /*
         * The demo dataset is supposed to carry at least one FIFO-valued product: weight-average is
         * the default everywhere, so without one there is no path through the code that maintains
         * `inventory_fifo_layers`, and that table stayed empty.
         *
         * This product is that one — and its name was "Cotton Fabric — Premium White", identical to
         * FAB-001, which is why the POS grid showed the same item twice under two SKUs. It is a
         * different thing: a cotton-poplin roll kept under FIFO, so the two names now say so.
         */
        $test = Product::firstOrCreate(['sku' => 'SKU-TEST001'], [
            'name' => 'Cotton Poplin — FIFO Roll',
            'valuation_method' => 'fifo',
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
            app(ReceiveStockAction::class)->execute($stockBatches, 'opening', 'OPEN-001', 'Demo opening stock — all SKUs');
        }
    }
}
