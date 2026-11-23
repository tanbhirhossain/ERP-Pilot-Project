<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vendor payments can be voided.
 *
 * A wrong payment — wrong bill, wrong amount, wrong method — previously had no way back: the
 * payment row, its journal and its AP settlement all stood, and the only correction was a
 * compensating manual journal that left the bill's own paid_amount wrong. `status` plus the void
 * audit columns give `VendorBillService::voidPayment()` somewhere to record the reversal.
 *
 * Schema only. No row is written here: content belongs in seeders.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_payments', function (Blueprint $t): void {
            if (! Schema::hasColumn('purchase_payments', 'status')) {
                $t->string('status', 20)->default('posted')->after('notes')
                    ->comment('posted | void');
            }
            if (! Schema::hasColumn('purchase_payments', 'voided_by')) {
                $t->unsignedBigInteger('voided_by')->nullable()->after('status');
            }
            if (! Schema::hasColumn('purchase_payments', 'voided_at')) {
                $t->timestamp('voided_at')->nullable()->after('voided_by');
            }
            if (! Schema::hasColumn('purchase_payments', 'void_reason')) {
                $t->text('void_reason')->nullable()->after('voided_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('purchase_payments', function (Blueprint $t): void {
            foreach (['void_reason', 'voided_at', 'voided_by', 'status'] as $col) {
                if (Schema::hasColumn('purchase_payments', $col)) {
                    $t->dropColumn($col);
                }
            }
        });
    }
};
