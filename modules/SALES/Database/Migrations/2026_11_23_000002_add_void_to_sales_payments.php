<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer collections can be voided with an audit trail.
 *
 * `sales_payments.status` already carried a `void` state, but nothing could ever set it — there
 * was no service, no route, and nowhere to record who voided or why. The void audit columns
 * complete it for `InvoiceService::voidPayment()`.
 *
 * Schema only. No row is written here: content belongs in seeders.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_payments', function (Blueprint $t): void {
            if (! Schema::hasColumn('sales_payments', 'voided_by')) {
                $t->unsignedBigInteger('voided_by')->nullable()->after('status');
            }
            if (! Schema::hasColumn('sales_payments', 'voided_at')) {
                $t->timestamp('voided_at')->nullable()->after('voided_by');
            }
            if (! Schema::hasColumn('sales_payments', 'void_reason')) {
                $t->text('void_reason')->nullable()->after('voided_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales_payments', function (Blueprint $t): void {
            foreach (['void_reason', 'voided_at', 'voided_by'] as $col) {
                if (Schema::hasColumn('sales_payments', $col)) {
                    $t->dropColumn($col);
                }
            }
        });
    }
};
