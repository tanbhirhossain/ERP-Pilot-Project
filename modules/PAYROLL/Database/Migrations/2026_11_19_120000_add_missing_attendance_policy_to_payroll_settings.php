<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What payroll does when an employee has no attendance record at all.
 *
 * The absence rule had this hard-coded: "if the employee never punched, pay them in full, because a
 * missing device export is not misconduct". That is the right default and the wrong permanence —
 * HR has to be able to change it when, for example, the line staff are rostered but the terminal in
 * their unit is not yet online, and the payroll officer decides that a rostered no-show is absence
 * regardless of what the device did.
 *
 * `waive`   — pay in full. An employee with no attendance record is an HR/device problem, not a
 *             pay problem, and docking them silently is how a payroll loses its staff's trust.
 * `apply`   — treat them like everyone else: a rostered day not worked is deducted.
 *
 * Defaults to `waive` so adding the column changes nothing about the money that is already right.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('payroll_settings', 'missing_attendance_policy')) {
            Schema::table('payroll_settings', function (Blueprint $table): void {
                $table->string('missing_attendance_policy', 16)->default('waive')->after('unpaid_leave_day_divisor');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('payroll_settings', 'missing_attendance_policy')) {
            Schema::table('payroll_settings', function (Blueprint $table): void {
                $table->dropColumn('missing_attendance_policy');
            });
        }
    }
};
