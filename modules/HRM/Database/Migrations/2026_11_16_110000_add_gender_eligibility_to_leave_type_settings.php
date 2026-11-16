<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who a leave type is for.
 *
 * Maternity leave (180 days) and paternity leave (7 days) were granted to every employee on the
 * payroll, because nothing in the system knew the difference between them. The balance register added
 * both to every member of staff's available-leave total — 187 days of leave nobody could take, sitting
 * on a register that reports what people may actually book.
 *
 * The value is *nullable on purpose*. `null` means "no restriction", so adding this column changes
 * nothing for the six leave types already in use; it only starts mattering once somebody sets it. A
 * default of 'all' would have read the same in the UI and then been indistinguishable from "explicitly
 * everyone" the first time a real restriction needed expressing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('leave_type_settings', 'gender_eligibility')) {
            Schema::table('leave_type_settings', function (Blueprint $table): void {
                // null = anyone; otherwise the employee's gender must match.
                $table->string('gender_eligibility', 16)->nullable()->after('proration_mode');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('leave_type_settings', 'gender_eligibility')) {
            Schema::table('leave_type_settings', function (Blueprint $table): void {
                $table->dropColumn('gender_eligibility');
            });
        }
    }
};
