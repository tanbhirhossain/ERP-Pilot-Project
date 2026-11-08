<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_policies', function (Blueprint $table): void {
            if (! Schema::hasColumn('employee_policies', 'duty_hours')) {
                $table->unsignedTinyInteger('duty_hours')->default(8)->after('shift_type')->comment('Expected daily duty hours 6/7/8');
            }
            if (! Schema::hasColumn('employee_policies', 'auto_shift')) {
                $table->boolean('auto_shift')->default(true)->after('duty_hours')->comment('If roster missing, auto-detect shift by duty_hours');
            }
            if (! Schema::hasColumn('employee_policies', 'late_grace')) {
                // alias for grace_time_in if needed
            }
        });
        Schema::table('employees', function (Blueprint $table): void {
            if (! Schema::hasColumn('employees', 'duty_hours')) {
                $table->unsignedTinyInteger('duty_hours')->default(8)->after('employment_type')->comment('Fallback duty hours if no policy');
            }
        });
    }

    public function down(): void
    {
        Schema::table('employee_policies', function (Blueprint $table): void {
            if (Schema::hasColumn('employee_policies', 'duty_hours')) {
                $table->dropColumn('duty_hours');
            }
            if (Schema::hasColumn('employee_policies', 'auto_shift')) {
                $table->dropColumn('auto_shift');
            }
        });
        Schema::table('employees', function (Blueprint $table): void {
            if (Schema::hasColumn('employees', 'duty_hours')) {
                $table->dropColumn('duty_hours');
            }
        });
    }
};
