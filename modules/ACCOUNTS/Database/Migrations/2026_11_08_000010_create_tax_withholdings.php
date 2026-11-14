<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F17 — TDS / VDS withholding (Bangladesh).
 *
 * Three tables:
 *   tax_withholding_rules  — configurable rates (TDS under the Income Tax Act,
 *                            VDS under the VAT Act), with a threshold below which
 *                            no deduction is required.
 *   tax_deposits           — treasury challans depositing withheld tax to NBR.
 *   tax_withholdings       — one row per deduction. `direction` separates tax we
 *                            withheld on a vendor payment (inward — a liability
 *                            we deposit) from tax a customer withheld on our sale
 *                            (outward — a credit we claim in Mushak 9.1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_withholding_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->enum('type', ['tds', 'vds']);
            $table->string('section', 120)->nullable();
            $table->decimal('rate', 5, 2);
            $table->decimal('threshold', 15, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->date('effective_from')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['type', 'is_active']);
        });

        Schema::create('tax_deposits', function (Blueprint $table): void {
            $table->id();
            $table->string('deposit_no')->unique();
            $table->enum('type', ['tds', 'vds', 'vat']);
            $table->date('period');
            $table->decimal('amount', 15, 2);
            $table->date('deposit_date');
            $table->foreignId('bank_id')->nullable()->constrained('acc_banks')->nullOnDelete();
            $table->string('challan_no', 120)->nullable();
            $table->string('treasury_code', 60)->nullable();
            $table->foreignId('journal_id')->nullable()->constrained('acc_journals')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['type', 'period']);
        });

        Schema::create('tax_withholdings', function (Blueprint $table): void {
            $table->id();
            $table->enum('type', ['tds', 'vds']);
            $table->enum('direction', ['inward', 'outward'])->default('inward');
            $table->foreignId('rule_id')->nullable()->constrained('tax_withholding_rules')->nullOnDelete();
            $table->foreignId('partner_id')->constrained('partners')->cascadeOnDelete();
            $table->foreignId('bill_id')->nullable()->constrained('purchase_bills')->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('sales_invoices')->nullOnDelete();
            $table->date('period');
            $table->decimal('base_amount', 15, 2);
            $table->decimal('rate', 5, 2);
            $table->decimal('amount', 15, 2);
            $table->enum('status', ['pending', 'deposited', 'certified'])->default('pending');
            $table->foreignId('deposit_id')->nullable()->constrained('tax_deposits')->nullOnDelete();
            $table->string('certificate_no', 120)->nullable();
            $table->timestamp('deposited_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['type', 'direction', 'period']);
            $table->index('partner_id');
            $table->index('bill_id');
            $table->index('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_withholdings');
        Schema::dropIfExists('tax_deposits');
        Schema::dropIfExists('tax_withholding_rules');
    }
};
