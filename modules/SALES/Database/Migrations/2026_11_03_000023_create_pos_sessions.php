<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_sessions', function (Blueprint $t) {
            $t->id();
            $t->string('session_no')->unique(); // POS-SESSION-00001
            $t->foreignId('warehouse_id')->constrained('inventory_warehouses')->restrictOnDelete();
            $t->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->decimal('opening_cash', 15, 2)->default(0);
            $t->decimal('closing_cash', 15, 2)->nullable();
            $t->decimal('expected_cash', 15, 2)->nullable();
            $t->decimal('cash_difference', 15, 2)->nullable();
            $t->decimal('total_sales', 15, 2)->default(0);
            $t->unsignedInteger('transactions_count')->default(0);
            $t->enum('status', ['open', 'closed', 'void'])->default('open')->index();
            $t->timestamp('opened_at')->useCurrent();
            $t->timestamp('closed_at')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->index(['warehouse_id', 'status']);
        });

        Schema::create('pos_sales', function (Blueprint $t) {
            $t->id();
            $t->string('pos_no')->unique(); // POS-00001
            $t->foreignId('session_id')->nullable()->constrained('pos_sessions')->nullOnDelete();
            $t->foreignId('invoice_id')->nullable()->constrained('sales_invoices')->nullOnDelete(); // linked invoice when posted
            $t->foreignId('customer_id')->nullable()->constrained('partners')->nullOnDelete(); // walk-in nullable
            $t->string('customer_name')->default('Walk-in Customer');
            $t->foreignId('warehouse_id')->constrained('inventory_warehouses')->restrictOnDelete();
            $t->date('sale_date')->index();
            $t->decimal('subtotal', 15, 2)->default(0);
            $t->decimal('discount_amount', 15, 2)->default(0);
            $t->decimal('vat_amount', 15, 2)->default(0);
            $t->decimal('total_amount', 15, 2)->default(0);
            $t->decimal('paid_amount', 15, 2)->default(0);
            $t->decimal('change_amount', 15, 2)->default(0);
            $t->enum('payment_method', ['cash', 'bank', 'bkash', 'card', 'split'])->default('cash');
            $t->decimal('cash_amount', 15, 2)->default(0);
            $t->decimal('bank_amount', 15, 2)->default(0);
            $t->foreignId('bank_id')->nullable()->constrained('acc_banks')->nullOnDelete();
            $t->enum('status', ['draft', 'completed', 'void', 'returned'])->default('completed')->index();
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['sale_date', 'status']);
        });

        Schema::create('pos_sale_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('pos_sale_id')->constrained('pos_sales')->cascadeOnDelete();
            $t->foreignId('product_id')->constrained('inventory_products')->restrictOnDelete();
            $t->foreignId('variant_id')->nullable()->constrained('inventory_product_variants')->nullOnDelete();
            $t->string('product_name');
            $t->string('sku')->nullable();
            $t->decimal('qty', 12, 2);
            $t->decimal('unit_price', 15, 2);
            $t->decimal('discount_pct', 5, 2)->default(0);
            $t->decimal('line_total', 15, 2);
            $t->decimal('cost_price', 15, 2)->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_sale_items');
        Schema::dropIfExists('pos_sales');
        Schema::dropIfExists('pos_sessions');
    }
};
