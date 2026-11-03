<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('purchase_orders', function (Blueprint $t) {
            $t->id();
            $t->string('order_no')->unique();
            $t->foreignId('vendor_id')->constrained('partners')->cascadeOnUpdate();
            $t->foreignId('warehouse_id')->constrained('inventory_warehouses')->cascadeOnUpdate();
            $t->date('order_date')->index();
            $t->date('expected_date')->nullable();
            $t->string('currency', 3)->default('BDT');
            $t->decimal('subtotal', 15, 2)->default(0);
            $t->decimal('discount_amount', 15, 2)->default(0);
            $t->decimal('vat_amount', 15, 2)->default(0);
            $t->decimal('total_amount', 15, 2)->default(0);
            $t->decimal('received_amount', 15, 2)->default(0);
            $t->enum('status', ['draft','sent','confirmed','partially_received','received','cancelled'])->default('draft')->index();
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('confirmed_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('purchase_order_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $t->foreignId('product_id')->constrained('inventory_products')->cascadeOnUpdate();
            $t->foreignId('variant_id')->nullable()->constrained('inventory_product_variants')->nullOnDelete();
            $t->string('product_name');
            $t->string('sku')->nullable();
            $t->foreignId('uom_id')->nullable()->constrained('inventory_uoms')->nullOnDelete();
            $t->decimal('qty', 15, 2);
            $t->decimal('qty_received', 15, 2)->default(0);
            $t->decimal('qty_billed', 15, 2)->default(0);
            $t->decimal('unit_price', 15, 2);
            $t->decimal('discount_pct', 5, 2)->default(0);
            $t->decimal('discount_amount', 15, 2)->default(0);
            $t->decimal('vat_rate', 5, 2)->default(0);
            $t->decimal('vat_amount', 15, 2)->default(0);
            $t->decimal('line_total', 15, 2);
            $t->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
    }
};
