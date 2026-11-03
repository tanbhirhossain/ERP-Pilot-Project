<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('purchase_receipts', function (Blueprint $t): void {
            if (!Schema::hasColumn('purchase_receipts','grni_journal_id')) {
                $t->foreignId('grni_journal_id')->nullable()->after('journal_id')->constrained('acc_journals')->nullOnDelete();
            }
        });
        // Ensure GRNI account 2115 exists (if demo seeder not run)
        if (\Illuminate\Support\Facades\Schema::hasTable('acc_accounts')) {
            if (!\Modules\ACCOUNTS\Models\Account::where('code','2115')->exists()) {
                $liab = \Modules\ACCOUNTS\Models\Account::where('code','2000')->first();
                \Modules\ACCOUNTS\Models\Account::create([
                    'code'=>'2115','name'=>'GRNI — Goods Received Not Invoiced','account_type'=>'liability','nature'=>'credit',
                    'parent_id'=>$liab?->id,'is_group'=>false,'is_posting'=>true,'is_active'=>true,'is_system'=>true,
                    'description'=>'Auto-created for Purchase GRNI accrual',
                ]);
            }
        }
    }
    public function down(): void
    {
        Schema::table('purchase_receipts', function (Blueprint $t): void {
            $t->dropForeign(['grni_journal_id']);
            $t->dropColumn('grni_journal_id');
        });
    }
};
