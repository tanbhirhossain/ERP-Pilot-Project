<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_receipts', function (Blueprint $t): void {
            if (! Schema::hasColumn('purchase_receipts', 'grni_journal_id')) {
                $t->foreignId('grni_journal_id')->nullable()->after('journal_id')->constrained('acc_journals')->nullOnDelete();
            }
        });
        // The GRNI account this column's journal posts to (accounts.defaults.grni_code, 2115)
        // is created by Modules\ACCOUNTS\Database\Seeders\AccountsDemoSeeder, not here.
        //
        // It used to be created above, and that single row is what emptied the general ledger:
        // AccountsDemoSeeder began with `if (Account::exists()) return;`, so the account this
        // migration wrote made the seeder that owned the whole chart of accounts skip itself.
        // Every other code was then missing, every posting path took its null branch, and
        // documents were marked posted with journal_id = null — no error, no log line (P0-B).
        //
        // Migrations change schema; seeders create content. Report item 38; the rule is pinned by
        // tests/Feature/CORE/MigrationDataSeparationTest.php. Posting a receipt without the
        // account now refuses loudly through ChartOfAccounts::requireId() instead.
    }

    public function down(): void
    {
        Schema::table('purchase_receipts', function (Blueprint $t): void {
            $t->dropForeign(['grni_journal_id']);
            $t->dropColumn('grni_journal_id');
        });
    }
};
