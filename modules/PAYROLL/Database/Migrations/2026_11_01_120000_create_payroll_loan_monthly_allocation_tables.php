<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_loan_monthly_batches', function (Blueprint $table): void {
            $table->id();
            $table->unsignedSmallInteger('pay_year');
            $table->unsignedTinyInteger('pay_month');
            $table->enum('scope', ['selected', 'all', 'automatic'])->default('selected');
            $table->json('employee_ids')->nullable();
            $table->enum('status', ['open', 'locked'])->default('open');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('locked_at')->nullable();
            $table->timestamps();
            $table->unique(['pay_year', 'pay_month']);
        });

        Schema::create('payroll_loan_monthly_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('batch_id')->constrained('payroll_loan_monthly_batches')->cascadeOnDelete();
            $table->foreignId('payroll_loan_id')->constrained('payroll_loans')->restrictOnDelete();
            $table->foreignId('payroll_loan_installment_id')->constrained('payroll_loan_installments')->restrictOnDelete();
            $table->string('employee_id');
            $table->decimal('amount', 12, 2);
            $table->enum('status', ['allocated', 'attached', 'paid'])->default('allocated');
            $table->foreignId('payroll_slip_id')->nullable()->constrained('payroll_slips')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('allocated_at');
            $table->timestamp('attached_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->unique(['batch_id', 'payroll_loan_id'], 'loan_month_alloc_unique_loan');
            $table->unique('payroll_loan_installment_id', 'loan_month_alloc_unique_installment');
            $table->index(['employee_id', 'status']);
            $table->index(['batch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_loan_monthly_allocations');
        Schema::dropIfExists('payroll_loan_monthly_batches');
    }
};
