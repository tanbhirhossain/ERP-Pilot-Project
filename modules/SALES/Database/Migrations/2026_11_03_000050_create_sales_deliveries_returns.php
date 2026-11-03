<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sales_deliveries', function (Blueprint $t) {
            $t->id();
            $t->string('delivery_no')->unique(); // DN-00001
            $t->foreignId('order_id')->nullable()->constrained('sales_orders')->nullOnDelete();
            $t->foreignId('invoice_id')->nullable()->constrained('sales_invoices')->nullOnDelete();
            $t->foreignId('customer_id')->constrained('partners')->restrictOnDelete();
            $t->foreignId('warehouse_id')->constrained('inventory_warehouses')->restrictOnDelete();
            $t->date('delivery_date')->index();
            $t->enum('status', ['draft', 'shipped', 'delivered', 'cancelled'])->default('draft')->index();
            $t->text('notes')->nullable();
            $t->text('shipping_address')->nullable();
            $t->string('challan_no')->nullable();
            $t->string('vehicle_no')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('shipped_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('shipped_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['customer_id', 'status']);
        });

        Schema::create('sales_delivery_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('delivery_id')->constrained('sales_deliveries')->cascadeOnDelete();
            $t->foreignId('order_item_id')->nullable()->constrained('sales_order_items')->nullOnDelete();
            $t->foreignId('product_id')->constrained('inventory_products')->restrictOnDelete();
            $t->foreignId('variant_id')->nullable()->constrained('inventory_product_variants')->nullOnDelete();
            $t->string('product_name');
            $t->string('sku')->nullable();
            $t->decimal('qty', 12, 2);
            $t->decimal('qty_delivered', 12, 2)->default(0);
            $t->string('lot_no')->nullable();
            $t->string('serial_no')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->index(['delivery_id', 'product_id']);
        });

        Schema::create('sales_returns', function (Blueprint $t) {
            $t->id();
            $t->string('return_no')->unique(); // SR-00001
            $t->foreignId('invoice_id')->constrained('sales_invoices')->restrictOnDelete();
            $t->foreignId('customer_id')->constrained('partners')->restrictOnDelete();
            $t->foreignId('warehouse_id')->constrained('inventory_warehouses')->restrictOnDelete();
            $t->foreignId('journal_id')->nullable()->constrained('acc_journals')->nullOnDelete()->comment('Credit note');
            $t->foreignId('restock_move_id')->nullable();
            $t->date('return_date')->index();
            $t->enum('status', ['draft', 'approved', 'restocked', 'refunded', 'cancelled'])->default('draft')->index();
            $t->enum('refund_method', ['credit', 'cash', 'bank'])->default('credit');
            $t->string('reason')->nullable();
            $t->decimal('subtotal', 15, 2)->default(0);
            $t->decimal('vat_amount', 15, 2)->default(0);
            $t->decimal('total_amount', 15, 2)->default(0);
            $t->boolean('is_restocked')->default(false);
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['customer_id', 'status']);
        });

        Schema::create('sales_return_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('return_id')->constrained('sales_returns')->cascadeOnDelete();
            $t->foreignId('invoice_item_id')->constrained('sales_invoice_items')->restrictOnDelete();
            $t->foreignId('product_id')->constrained('inventory_products')->restrictOnDelete();
            $t->decimal('qty', 12, 2);
            $t->decimal('unit_price', 15, 2);
            $t->decimal('line_total', 15, 2);
            $t->decimal('vat_rate', 5, 2)->default(0);
            $t->decimal('vat_amount', 15, 2)->default(0);
            $t->enum('condition', ['resalable', 'damaged'])->default('resalable');
            $t->string('lot_no')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('sales_return_items');
        Schema::dropIfExists('sales_returns');
        Schema::dropIfExists('sales_delivery_items');
        Schema::dropIfExists('sales_deliveries');
    }
};
