<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sales_invoices', function (Blueprint $t) {
            $t->id();
            $t->string('invoice_no')->unique(); // INV-00001
            $t->foreignId('order_id')->nullable()->constrained('sales_orders')->nullOnDelete();
            $t->foreignId('quotation_id')->nullable()->constrained('sales_quotations')->nullOnDelete();
            $t->foreignId('customer_id')->constrained('partners')->restrictOnDelete();
            $t->foreignId('warehouse_id')->constrained('inventory_warehouses')->restrictOnDelete();
            $t->foreignId('journal_id')->nullable()->constrained('acc_journals')->nullOnDelete()->comment('Posted to ACCOUNTS');
            $t->date('invoice_date')->index();
            $t->date('due_date')->nullable()->index();
            $t->string('currency', 3)->default('BDT');
            $t->decimal('subtotal', 15, 2)->default(0);
            $t->decimal('discount_amount', 15, 2)->default(0);
            $t->decimal('vat_amount', 15, 2)->default(0);
            $t->decimal('total_amount', 15, 2)->default(0);
            $t->decimal('paid_amount', 15, 2)->default(0);
            $t->decimal('balance', 15, 2)->storedAs('total_amount - paid_amount');
            $t->enum('status', ['draft', 'posted', 'paid', 'partially_paid', 'overdue', 'cancelled', 'void'])->default('draft')->index();
            $t->enum('payment_status', ['unpaid', 'partial', 'paid'])->default('unpaid');
            $t->string('payment_method')->nullable(); // cash|bank|credit
            $t->foreignId('bank_id')->nullable()->constrained('acc_banks')->nullOnDelete();
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('posted_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['customer_id', 'status']);
            $t->index(['invoice_date', 'status']);
        });

        Schema::create('sales_invoice_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('invoice_id')->constrained('sales_invoices')->cascadeOnDelete();
            $t->foreignId('order_item_id')->nullable()->constrained('sales_order_items')->nullOnDelete();
            $t->foreignId('product_id')->constrained('inventory_products')->restrictOnDelete();
            $t->foreignId('variant_id')->nullable()->constrained('inventory_product_variants')->nullOnDelete();
            $t->string('product_name');
            $t->string('sku')->nullable();
            $t->foreignId('uom_id')->nullable()->constrained('inventory_uoms')->nullOnDelete();
            $t->decimal('qty', 12, 2);
            $t->decimal('unit_price', 15, 2);
            $t->decimal('discount_pct', 5, 2)->default(0);
            $t->decimal('discount_amount', 15, 2)->default(0);
            $t->decimal('vat_rate', 5, 2)->default(0);
            $t->decimal('vat_amount', 15, 2)->default(0);
            $t->decimal('line_total', 15, 2);
            $t->decimal('cost_price', 15, 2)->nullable()->comment('Snapshot for COGS');
            $t->timestamps();
        });

        Schema::create('sales_payments', function (Blueprint $t) {
            $t->id();
            $t->string('payment_no')->unique(); // PAY-00001
            $t->foreignId('invoice_id')->constrained('sales_invoices')->cascadeOnDelete();
            $t->foreignId('customer_id')->constrained('partners')->restrictOnDelete();
            $t->foreignId('journal_id')->nullable()->constrained('acc_journals')->nullOnDelete();
            $t->date('payment_date')->index();
            $t->decimal('amount', 15, 2);
            $t->string('method')->default('cash'); // cash|bank|bkash|card
            $t->foreignId('bank_id')->nullable()->constrained('acc_banks')->nullOnDelete();
            $t->string('reference_no')->nullable();
            $t->text('notes')->nullable();
            $t->enum('status', ['draft', 'posted', 'void'])->default('posted');
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['customer_id', 'payment_date']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('sales_payments');
        Schema::dropIfExists('sales_invoice_items');
        Schema::dropIfExists('sales_invoices');
    }
};
