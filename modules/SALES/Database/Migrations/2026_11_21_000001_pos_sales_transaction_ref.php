<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * POS sale transaction reference.
 *
 * A cashier who takes a bKash, bank or card payment has a receipt number, a transaction ID or a
 * slip reference in hand — and the till had nowhere to put it. The sale recorded the method and
 * the amount, but the reference that reconciles the money against the bank statement or the
 * bKash payout was simply not recorded, so the day's takings could not be verified against
 * anything the gateway actually produced.
 *
 * `transaction_ref` carries that reference on every `pos_sales` row. It is free text on
 * purpose: bKash sends a numeric transaction id, a bank slip carries its own numbering, and a
 * card slip may carry a merchant reference — one shape would force the others to be bent.
 * The till requires it for the electronic methods (bKash, bank, card, split), where a sale
 * without a reference cannot be reconciled, and leaves it open for cash and credit sales.
 *
 * Schema only. No row is written here: content belongs in seeders, which is item 38's rule and
 * is enforced by `tests/Feature/CORE/MigrationDataSeparationTest.php`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_sales', function (Blueprint $t): void {
            if (! Schema::hasColumn('pos_sales', 'transaction_ref')) {
                $t->string('transaction_ref', 100)->nullable()->after('bank_id')
                    ->comment('bKash / bank / card transaction or slip reference');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pos_sales', function (Blueprint $t): void {
            if (Schema::hasColumn('pos_sales', 'transaction_ref')) {
                $t->dropColumn('transaction_ref');
            }
        });
    }
};
