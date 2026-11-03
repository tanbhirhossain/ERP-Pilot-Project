<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sales_quotations', function (Blueprint $t) {
            $t->id();
            $t->string('quotation_no')->unique(); // QT-00001
            $t->foreignId('customer_id')->constrained('partners')->restrictOnDelete(); // PARTNER customers
            $t->foreignId('price_list_id')->nullable()->constrained('price_lists')->nullOnDelete();
            $t->date('quotation_date')->index();
            $t->date('expiry_date')->nullable()->index();
            $t->string('currency', 3)->default('BDT');
            $t->decimal('subtotal', 15, 2)->default(0);
            $t->decimal('discount_amount', 15, 2)->default(0);
            $t->decimal('vat_amount', 15, 2)->default(0);
            $t->decimal('total_amount', 15, 2)->default(0);
            $t->enum('status', ['draft', 'sent', 'accepted', 'rejected', 'expired', 'converted'])->default('draft')->index();
            $t->text('notes')->nullable();
            $t->text('terms')->nullable();
            $t->foreignId('converted_order_id')->nullable()->constrained('sales_orders')->nullOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['customer_id', 'status']);
        });

        Schema::create('sales_quotation_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('quotation_id')->constrained('sales_quotations')->cascadeOnDelete();
            $t->foreignId('product_id')->constrained('inventory_products')->restrictOnDelete();
            $t->foreignId('variant_id')->nullable()->constrained('inventory_product_variants')->nullOnDelete();
            $t->string('product_name'); // snapshot
            $t->string('sku')->nullable();
            $t->foreignId('uom_id')->nullable()->constrained('inventory_uoms')->nullOnDelete();
            $t->decimal('qty', 12, 2);
            $t->decimal('unit_price', 15, 2);
            $t->decimal('discount_pct', 5, 2)->default(0);
            $t->decimal('discount_amount', 15, 2)->default(0);
            $t->decimal('vat_rate', 5, 2)->default(0);
            $t->decimal('vat_amount', 15, 2)->default(0);
            $t->decimal('line_total', 15, 2);
            $t->text('description')->nullable();
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamps();
            $t->index(['quotation_id', 'product_id']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('sales_quotation_items');
        Schema::dropIfExists('sales_quotations');
    }
};
