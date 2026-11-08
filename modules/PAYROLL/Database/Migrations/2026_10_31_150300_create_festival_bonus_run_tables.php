<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_loan_installments', function (Blueprint $table): void {
            $table->foreign('payroll_slip_id')->references('id')->on('payroll_slips')->nullOnDelete();
        });

        Schema::create('festival_bonus_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('festival_bonus_policy_id')->constrained('festival_bonus_policies')->restrictOnDelete();
            $table->unsignedSmallInteger('pay_year');
            $table->unsignedTinyInteger('pay_month');
            $table->enum('status', ['draft', 'generated', 'released'])->default('draft');
            $table->unsignedInteger('employee_count')->default(0);
            $table->decimal('total_amount', 16, 2)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['pay_year', 'pay_month', 'status']);
        });

        Schema::create('festival_bonus_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('festival_bonus_run_id')->constrained('festival_bonus_runs')->cascadeOnDelete();
            $table->string('employee_id');
            $table->foreignId('payroll_slip_id')->nullable()->constrained('payroll_slips')->nullOnDelete();
            $table->decimal('amount', 12, 2);
            $table->json('breakdown_json');
            $table->timestamps();
            $table->foreign('employee_id')->references('employee_id')->on('employees')->restrictOnDelete();
            $table->unique(['festival_bonus_run_id', 'employee_id']);
            $table->index(['payroll_slip_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::table('payroll_loan_installments', function (Blueprint $table): void {
            $table->dropForeign(['payroll_slip_id']);
        });
        Schema::dropIfExists('festival_bonus_items');
        Schema::dropIfExists('festival_bonus_runs');
    }
};
