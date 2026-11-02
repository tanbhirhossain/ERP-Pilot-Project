<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_loans', function (Blueprint $table): void {
            $table->id();
            $table->string('employee_id');
            $table->decimal('principal_amount', 12, 2);
            $table->decimal('interest_rate', 5, 2)->default(0);
            $table->unsignedSmallInteger('installment_count');
            $table->decimal('installment_amount', 12, 2)->default(0);
            $table->date('first_due_date');
            $table->enum('status', ['pending', 'approved', 'rejected', 'active', 'completed', 'cancelled'])->default('pending');
            $table->json('approval_steps')->nullable();
            $table->unsignedSmallInteger('current_approval_step')->default(0);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('purpose')->nullable();
            $table->timestamps();
            $table->foreign('employee_id')->references('employee_id')->on('employees')->restrictOnDelete();
            $table->index(['employee_id', 'status']);
        });

        Schema::create('payroll_loan_approvals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payroll_loan_id')->constrained('payroll_loans')->cascadeOnDelete();
            $table->unsignedSmallInteger('step_number');
            $table->string('step_name', 100);
            $table->enum('decision', ['approved', 'rejected']);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('comments')->nullable();
            $table->timestamp('decided_at');
            $table->timestamps();
            $table->index(['payroll_loan_id', 'step_number']);
        });

        Schema::create('payroll_loan_installments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payroll_loan_id')->constrained('payroll_loans')->cascadeOnDelete();
            $table->unsignedSmallInteger('installment_number');
            $table->date('due_date');
            $table->decimal('amount', 12, 2);
            $table->enum('status', ['scheduled', 'attached', 'paid', 'skipped'])->default('scheduled');
            $table->unsignedBigInteger('payroll_slip_id')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->unique(['payroll_loan_id', 'installment_number']);
            $table->index(['payroll_loan_id', 'status', 'due_date']);
            $table->index('payroll_slip_id');
        });

        Schema::create('pf_policies', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->default('Default PF Policy');
            $table->decimal('employee_contribution_percent', 5, 2)->default(0);
            $table->decimal('employer_contribution_percent', 5, 2)->default(0);
            $table->unsignedSmallInteger('minimum_service_months')->default(0);
            $table->string('contribution_base', 40)->default('basic');
            $table->boolean('is_active')->default(true);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->timestamps();
            $table->index(['is_active', 'effective_from', 'effective_to']);
        });

        Schema::create('pf_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('employee_id')->unique();
            $table->foreignId('pf_policy_id')->nullable()->constrained('pf_policies')->nullOnDelete();
            $table->date('eligible_from')->nullable();
            $table->decimal('employee_balance', 14, 2)->default(0);
            $table->decimal('employer_balance', 14, 2)->default(0);
            $table->enum('status', ['active', 'suspended', 'closed'])->default('active');
            $table->timestamps();
            $table->foreign('employee_id')->references('employee_id')->on('employees')->restrictOnDelete();
            $table->index(['status', 'eligible_from']);
        });

        Schema::create('pf_ledgers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pf_account_id')->constrained('pf_accounts')->cascadeOnDelete();
            $table->enum('entry_type', ['employee_contribution', 'employer_contribution', 'withdrawal', 'adjustment']);
            $table->decimal('amount', 14, 2);
            $table->date('entry_date');
            $table->string('reference_type', 80)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['pf_account_id', 'entry_date']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pf_ledgers');
        Schema::dropIfExists('pf_accounts');
        Schema::dropIfExists('pf_policies');
        Schema::dropIfExists('payroll_loan_installments');
        Schema::dropIfExists('payroll_loan_approvals');
        Schema::dropIfExists('payroll_loans');
    }
};
