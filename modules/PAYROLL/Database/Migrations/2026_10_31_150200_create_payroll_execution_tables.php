<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_cutlists', function (Blueprint $table): void {
            $table->id();
            $table->unsignedSmallInteger('pay_year');
            $table->unsignedTinyInteger('pay_month');
            $table->enum('status', ['draft', 'generated', 'locked'])->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();
            $table->unique(['pay_year', 'pay_month']);
        });

        Schema::create('payroll_cutlist_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payroll_cutlist_id')->constrained('payroll_cutlists')->cascadeOnDelete();
            $table->string('employee_id');
            $table->decimal('unpaid_leave_days', 6, 2)->default(0);
            $table->string('employee_name')->nullable();
            $table->unsignedSmallInteger('late_count')->default(0);
            $table->unsignedSmallInteger('early_out_count')->default(0);
            $table->unsignedSmallInteger('punishment_days')->default(0);
            $table->boolean('punishable')->default(false);
            $table->unsignedSmallInteger('missing_punch_count')->default(0);
            $table->unsignedSmallInteger('approved_overtime_minutes')->default(0);
            $table->unsignedSmallInteger('approved_extra_duty_minutes')->default(0);
            $table->decimal('unpaid_leave_deduction', 12, 2)->default(0);
            $table->decimal('late_penalty_deduction', 12, 2)->default(0);
            $table->decimal('missing_punch_deduction', 12, 2)->default(0);
            $table->decimal('overtime_pay', 12, 2)->default(0);
            $table->decimal('extra_duty_pay', 12, 2)->default(0);
            $table->json('source_breakdown')->nullable();
            $table->timestamps();
            $table->foreign('employee_id')->references('employee_id')->on('employees')->restrictOnDelete();
            $table->unique(['payroll_cutlist_id', 'employee_id']);
            $table->index(['employee_id', 'payroll_cutlist_id']);
        });

        Schema::create('payroll_salary_sheets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedSmallInteger('pay_year');
            $table->unsignedTinyInteger('pay_month');
            $table->enum('status', ['queued', 'processing', 'ready', 'released', 'failed'])->default('queued');
            $table->boolean('include_punishments')->default(false);
            $table->unsignedInteger('employee_count')->default(0);
            $table->unsignedInteger('processed_count')->default(0);
            $table->decimal('gross_total', 16, 2)->default(0);
            $table->decimal('deduction_total', 16, 2)->default(0);
            $table->decimal('net_total', 16, 2)->default(0);
            $table->foreignId('payroll_cutlist_id')->nullable()->constrained('payroll_cutlists')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamps();
            $table->unique(['pay_year', 'pay_month']);
            $table->index(['status', 'pay_year', 'pay_month']);
        });

        Schema::create('payroll_slips', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('salary_sheet_id')->constrained('payroll_salary_sheets')->cascadeOnDelete();
            $table->string('employee_id');
            $table->string('employee_name');
            $table->string('department')->nullable();
            $table->string('designation')->nullable();
            $table->unsignedSmallInteger('pay_year');
            $table->unsignedTinyInteger('pay_month');
            $table->decimal('basic_salary', 12, 2)->default(0);
            $table->decimal('gross_salary', 12, 2)->default(0);
            $table->decimal('total_benefits', 12, 2)->default(0);
            $table->decimal('total_deductions', 12, 2)->default(0);
            $table->decimal('net_salary', 12, 2)->default(0);
            $table->enum('payment_status', ['unpaid', 'paid', 'hold'])->default('unpaid');
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('breakdown_json');
            $table->timestamps();
            $table->foreign('employee_id')->references('employee_id')->on('employees')->restrictOnDelete();
            $table->unique(['salary_sheet_id', 'employee_id']);
            $table->index(['salary_sheet_id', 'employee_id']);
            $table->index(['employee_id', 'pay_year', 'pay_month']);
            $table->index(['payment_status', 'pay_year', 'pay_month']);
        });

        Schema::create('festival_bonus_policies', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->enum('calculation_type', ['fixed', 'percentage'])->default('percentage');
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('calculation_base', 40)->default('basic');
            $table->unsignedSmallInteger('minimum_service_months')->default(0);
            $table->unsignedTinyInteger('bonuses_per_year')->default(2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('gratuity_policies', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->default('Default Gratuity Policy');
            $table->unsignedSmallInteger('minimum_service_years')->default(5);
            $table->decimal('days_per_service_year', 7, 2)->default(30);
            $table->string('calculation_base', 40)->default('basic');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('payroll_settlements', function (Blueprint $table): void {
            $table->id();
            $table->string('employee_id');
            $table->date('settlement_date');
            $table->date('last_working_date');
            $table->decimal('gross_due', 14, 2)->default(0);
            $table->decimal('gratuity_amount', 14, 2)->default(0);
            $table->decimal('leave_encashment', 14, 2)->default(0);
            $table->decimal('other_benefits', 14, 2)->default(0);
            $table->decimal('deductions', 14, 2)->default(0);
            $table->decimal('net_settlement', 14, 2)->default(0);
            $table->enum('status', ['draft', 'pending', 'approved', 'paid', 'rejected'])->default('draft');
            $table->json('breakdown_json')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->foreign('employee_id')->references('employee_id')->on('employees')->restrictOnDelete();
            $table->index(['employee_id', 'settlement_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_settlements');
        Schema::dropIfExists('gratuity_policies');
        Schema::dropIfExists('festival_bonus_policies');
        Schema::dropIfExists('payroll_slips');
        Schema::dropIfExists('payroll_salary_sheets');
        Schema::dropIfExists('payroll_cutlist_items');
        Schema::dropIfExists('payroll_cutlists');
    }
};
