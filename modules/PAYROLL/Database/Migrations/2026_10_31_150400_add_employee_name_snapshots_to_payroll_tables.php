<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'salary_increments',
            'employee_recurring_adjustments',
            'employee_tax_profiles',
            'payroll_loans',
            'pf_accounts',
            'payroll_settlements',
            'festival_bonus_items',
        ] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->string('employee_name')->nullable()->after('employee_id');
            });
        }
    }

    public function down(): void
    {
        foreach ([
            'festival_bonus_items',
            'payroll_settlements',
            'pf_accounts',
            'payroll_loans',
            'employee_tax_profiles',
            'employee_recurring_adjustments',
            'salary_increments',
        ] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn('employee_name');
            });
        }
    }
};
