<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_settings', function (Blueprint $table): void {
            if (! Schema::hasColumn('payroll_settings', 'punishment_days_per_salary_day')) {
                $table->unsignedTinyInteger('punishment_days_per_salary_day')->default(3);
            }
            if (! Schema::hasColumn('payroll_settings', 'overtime_multiplier')) {
                $table->decimal('overtime_multiplier', 5, 2)->default(1.00);
            }
            if (! Schema::hasColumn('payroll_settings', 'extra_duty_multiplier')) {
                $table->decimal('extra_duty_multiplier', 5, 2)->default(1.00);
            }
        });
        Schema::table('payroll_cutlist_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('payroll_cutlist_items', 'employee_name')) {
                $table->string('employee_name')->nullable();
            }
            if (! Schema::hasColumn('payroll_cutlist_items', 'early_out_count')) {
                $table->unsignedSmallInteger('early_out_count')->default(0);
            }
            if (! Schema::hasColumn('payroll_cutlist_items', 'punishment_days')) {
                $table->unsignedSmallInteger('punishment_days')->default(0);
            }
            if (! Schema::hasColumn('payroll_cutlist_items', 'punishable')) {
                $table->boolean('punishable')->default(false);
            }
            if (! Schema::hasColumn('payroll_cutlist_items', 'approved_overtime_minutes')) {
                $table->unsignedSmallInteger('approved_overtime_minutes')->default(0);
            }
            if (! Schema::hasColumn('payroll_cutlist_items', 'approved_extra_duty_minutes')) {
                $table->unsignedSmallInteger('approved_extra_duty_minutes')->default(0);
            }
            if (! Schema::hasColumn('payroll_cutlist_items', 'overtime_pay')) {
                $table->decimal('overtime_pay', 12, 2)->default(0);
            }
            if (! Schema::hasColumn('payroll_cutlist_items', 'extra_duty_pay')) {
                $table->decimal('extra_duty_pay', 12, 2)->default(0);
            }
        });
        Schema::table('payroll_salary_sheets', function (Blueprint $table): void {
            if (! Schema::hasColumn('payroll_salary_sheets', 'include_punishments')) {
                $table->boolean('include_punishments')->default(false);
            }
        });
    }

    public function down(): void
    {
        // Forward-only compatibility migration: the original create-table migrations own rollback.
    }
};
