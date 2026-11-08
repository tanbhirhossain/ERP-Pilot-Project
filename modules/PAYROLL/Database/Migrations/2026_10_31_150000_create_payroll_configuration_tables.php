<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('currency', 3)->default('BDT');
            $table->unsignedTinyInteger('punishment_days_per_salary_day')->default(3);
            $table->decimal('overtime_multiplier', 5, 2)->default(1.00);
            $table->decimal('extra_duty_multiplier', 5, 2)->default(1.00);
            $table->decimal('missing_punch_penalty_amount', 12, 2)->default(0);
            $table->decimal('unpaid_leave_day_divisor', 6, 2)->default(30);
            $table->json('loan_approval_steps')->nullable();
            $table->timestamps();
        });

        Schema::create('salary_increments', function (Blueprint $table): void {
            $table->id();
            $table->string('employee_id');
            $table->enum('increment_type', ['fixed', 'percentage']);
            $table->decimal('increment_value', 12, 2);
            $table->decimal('previous_basic', 12, 2);
            $table->decimal('new_basic', 12, 2);
            $table->decimal('previous_gross', 12, 2);
            $table->decimal('new_gross', 12, 2);
            $table->date('effective_date');
            $table->enum('status', ['pending', 'approved', 'rejected', 'applied'])->default('pending');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->foreign('employee_id')->references('employee_id')->on('employees')->restrictOnDelete();
            $table->index(['employee_id', 'effective_date']);
            $table->index(['status', 'effective_date']);
        });

        Schema::create('salary_benefit_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code', 40)->unique();
            $table->enum('calculation_type', ['fixed', 'percentage'])->default('fixed');
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('percentage_base', 40)->default('basic');
            $table->boolean('auto_apply')->default(false);
            $table->boolean('taxable')->default(true);
            $table->boolean('pf_eligible')->default(false);
            $table->boolean('is_active')->default(true);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->timestamps();
            $table->index(['is_active', 'effective_from', 'effective_to']);
        });

        Schema::create('salary_deduction_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code', 40)->unique();
            $table->enum('calculation_type', ['fixed', 'percentage'])->default('fixed');
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('percentage_base', 40)->default('gross');
            $table->boolean('auto_apply')->default(false);
            $table->boolean('is_active')->default(true);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->timestamps();
            $table->index(['is_active', 'effective_from', 'effective_to']);
        });

        Schema::create('employee_recurring_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->string('employee_id');
            $table->enum('adjustment_type', ['benefit', 'deduction']);
            $table->foreignId('benefit_template_id')->nullable()->constrained('salary_benefit_templates')->nullOnDelete();
            $table->foreignId('deduction_template_id')->nullable()->constrained('salary_deduction_templates')->nullOnDelete();
            $table->string('name');
            $table->enum('calculation_type', ['fixed', 'percentage'])->default('fixed');
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('percentage_base', 40)->default('basic');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->foreign('employee_id')->references('employee_id')->on('employees')->restrictOnDelete();
            $table->index(['employee_id', 'adjustment_type', 'is_active', 'effective_from']);
        });

        Schema::create('tax_configurations', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->default('Default');
            $table->enum('mode', ['fixed', 'progressive'])->default('progressive');
            $table->decimal('fixed_monthly_tax', 12, 2)->default(0);
            $table->decimal('annual_exemption', 12, 2)->default(0);
            $table->decimal('taxable_salary_percent', 5, 2)->default(100);
            $table->boolean('is_active')->default(true);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->timestamps();
            $table->index(['is_active', 'effective_from', 'effective_to']);
        });

        Schema::create('tax_slabs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tax_configuration_id')->constrained('tax_configurations')->cascadeOnDelete();
            $table->decimal('lower_limit', 14, 2)->default(0);
            $table->decimal('upper_limit', 14, 2)->nullable();
            $table->decimal('rate_percent', 7, 4)->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['tax_configuration_id', 'sort_order']);
        });

        Schema::create('employee_tax_profiles', function (Blueprint $table): void {
            $table->id();
            $table->string('employee_id')->unique();
            $table->enum('override_mode', ['global_config', 'force_fixed', 'exempt'])->default('global_config');
            $table->decimal('fixed_tax_amount', 12, 2)->default(0);
            $table->enum('fixed_tax_frequency', ['monthly', 'annual'])->default('monthly');
            $table->text('exemption_reason')->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->foreign('employee_id')->references('employee_id')->on('employees')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_tax_profiles');
        Schema::dropIfExists('tax_slabs');
        Schema::dropIfExists('tax_configurations');
        Schema::dropIfExists('employee_recurring_adjustments');
        Schema::dropIfExists('salary_deduction_templates');
        Schema::dropIfExists('salary_benefit_templates');
        Schema::dropIfExists('salary_increments');
        Schema::dropIfExists('payroll_settings');
    }
};
