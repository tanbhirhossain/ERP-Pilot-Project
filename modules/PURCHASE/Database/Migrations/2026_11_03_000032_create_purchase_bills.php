<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_bills', function (Blueprint $t) {
            $t->id();
            $t->string('bill_no')->unique();
            $t->foreignId('order_id')->nullable()->constrained('purchase_orders')->nullOnDelete();
            $t->foreignId('receipt_id')->nullable()->constrained('purchase_receipts')->nullOnDelete();
            $t->foreignId('vendor_id')->constrained('partners')->cascadeOnUpdate();
            $t->foreignId('warehouse_id')->constrained('inventory_warehouses')->cascadeOnUpdate();
            $t->foreignId('journal_id')->nullable()->constrained('acc_journals')->nullOnDelete();
            $t->date('bill_date')->index();
            $t->date('due_date')->nullable();
            $t->string('vendor_reference')->nullable();
            $t->string('currency', 3)->default('BDT');
            $t->decimal('subtotal', 15, 2)->default(0);
            $t->decimal('discount_amount', 15, 2)->default(0);
            $t->decimal('vat_amount', 15, 2)->default(0);
            $t->decimal('total_amount', 15, 2)->default(0);
            $t->decimal('paid_amount', 15, 2)->default(0);
            $t->enum('status', ['draft', 'posted', 'cancelled'])->default('draft')->index();
            $t->enum('payment_status', ['unpaid', 'partial', 'paid'])->default('unpaid');
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('posted_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('purchase_bill_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('bill_id')->constrained('purchase_bills')->cascadeOnDelete();
            $t->foreignId('receipt_item_id')->nullable()->constrained('purchase_receipt_items')->nullOnDelete();
            $t->foreignId('product_id')->constrained('inventory_products')->cascadeOnUpdate();
            $t->foreignId('variant_id')->nullable()->constrained('inventory_product_variants')->nullOnDelete();
            $t->string('product_name');
            $t->string('sku')->nullable();
            $t->decimal('qty', 15, 2);
            $t->decimal('unit_price', 15, 2);
            $t->decimal('discount_pct', 5, 2)->default(0);
            $t->decimal('discount_amount', 15, 2)->default(0);
            $t->decimal('vat_rate', 5, 2)->default(0);
            $t->decimal('vat_amount', 15, 2)->default(0);
            $t->decimal('line_total', 15, 2);
            $t->decimal('cost_price', 15, 2)->nullable();
            $t->timestamps();
        });
        Schema::create('purchase_payments', function (Blueprint $t) {
            $t->id();
            $t->string('payment_no')->unique();
            $t->foreignId('bill_id')->constrained('purchase_bills')->cascadeOnDelete();
            $t->foreignId('vendor_id')->constrained('partners')->cascadeOnUpdate();
            $t->foreignId('journal_id')->nullable()->constrained('acc_journals')->nullOnDelete();
            $t->date('payment_date')->index();
            $t->decimal('amount', 15, 2);
            $t->string('method')->default('cash'); // cash|bank|bkash
            $t->foreignId('bank_id')->nullable()->constrained('acc_banks')->nullOnDelete();
            $t->string('reference_no')->nullable();
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_payments');
        Schema::dropIfExists('purchase_bill_items');
        Schema::dropIfExists('purchase_bills');
    }
};
