<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_orders', function (Blueprint $t) {
            $t->id();
            $t->string('order_no')->unique(); // SO-00001
            $t->foreignId('quotation_id')->nullable()->constrained('sales_quotations')->nullOnDelete();
            $t->foreignId('customer_id')->constrained('partners')->restrictOnDelete();
            $t->foreignId('warehouse_id')->constrained('inventory_warehouses')->restrictOnDelete(); // fulfillment warehouse
            $t->foreignId('price_list_id')->nullable()->constrained('price_lists')->nullOnDelete();
            $t->date('order_date')->index();
            $t->date('delivery_date')->nullable();
            $t->string('currency', 3)->default('BDT');
            $t->decimal('subtotal', 15, 2)->default(0);
            $t->decimal('discount_amount', 15, 2)->default(0);
            $t->decimal('vat_amount', 15, 2)->default(0);
            $t->decimal('total_amount', 15, 2)->default(0);
            $t->decimal('invoiced_amount', 15, 2)->default(0);
            $t->enum('status', ['draft', 'confirmed', 'partially_invoiced', 'invoiced', 'cancelled', 'closed'])->default('draft')->index();
            $t->enum('payment_status', ['unpaid', 'partial', 'paid'])->default('unpaid');
            $t->text('shipping_address')->nullable();
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('confirmed_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['customer_id', 'status']);
            $t->index(['warehouse_id', 'status']);
        });

        Schema::create('sales_order_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained('sales_orders')->cascadeOnDelete();
            $t->foreignId('product_id')->constrained('inventory_products')->restrictOnDelete();
            $t->foreignId('variant_id')->nullable()->constrained('inventory_product_variants')->nullOnDelete();
            $t->string('product_name');
            $t->string('sku')->nullable();
            $t->foreignId('uom_id')->nullable()->constrained('inventory_uoms')->nullOnDelete();
            $t->decimal('qty', 12, 2);
            $t->decimal('qty_invoiced', 12, 2)->default(0);
            $t->decimal('qty_delivered', 12, 2)->default(0);
            $t->decimal('unit_price', 15, 2);
            $t->decimal('discount_pct', 5, 2)->default(0);
            $t->decimal('discount_amount', 15, 2)->default(0);
            $t->decimal('vat_rate', 5, 2)->default(0);
            $t->decimal('vat_amount', 15, 2)->default(0);
            $t->decimal('line_total', 15, 2);
            $t->timestamps();
            $t->index(['order_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_order_items');
        Schema::dropIfExists('sales_orders');
    }
};
