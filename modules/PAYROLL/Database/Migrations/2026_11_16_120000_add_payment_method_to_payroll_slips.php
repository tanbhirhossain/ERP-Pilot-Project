<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records how each slip was actually paid.
 *
 * Payroll had one payment path and it ended at a bank. A hospital pays attendants and support staff
 * in cash at the counter, and there was nowhere to say so: the slip carried paid_at and paid_by but
 * not the method, and the ledger credited the first active bank for every payment regardless. Adding
 * the column means the cash sheet, the slip and the journal can be checked against each other instead
 * of each holding a different opinion.
 *
 * Nullable on purpose: every slip paid before this migration has no recorded method, and claiming one
 * for them would be inventing history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_slips', function (Blueprint $table) {
            $table->string('payment_method', 20)->nullable()->after('payment_status');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_slips', function (Blueprint $table) {
            $table->dropColumn('payment_method');
        });
    }
};