<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the bill's VAT goes to the input-VAT account or into the cost.
 *
 * Recoverable VAT (the default) is claimed: the journal debits VAT input and the asset or
 * purchase cost stays net, per IAS 16. When the VAT cannot be recovered — an unregistered
 * supplier, a blocked input — there is no VAT leg and the gross lands in the cost instead.
 * One flag for the whole bill: splitting recoverability line-by-line would need per-line VAT
 * legs, which the posting does not have.
 *
 * Schema only. No row is written here: content belongs in seeders.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_bills', function (Blueprint $t): void {
            if (! Schema::hasColumn('purchase_bills', 'vat_recoverable')) {
                $t->boolean('vat_recoverable')->default(true)->after('vat_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('purchase_bills', function (Blueprint $t): void {
            if (Schema::hasColumn('purchase_bills', 'vat_recoverable')) {
                $t->dropColumn('vat_recoverable');
            }
        });
    }
};
