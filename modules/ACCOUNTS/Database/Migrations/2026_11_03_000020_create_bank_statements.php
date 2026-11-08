<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acc_bank_statements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('bank_id')->constrained('acc_banks')->cascadeOnDelete();
            $t->date('txn_date')->index();
            $t->string('description')->nullable();
            $t->string('reference_no')->nullable()->index();
            $t->decimal('amount', 15, 2)->comment('positive = deposit/credit, negative = withdrawal/debit');
            $t->decimal('balance', 15, 2)->nullable();
            $t->foreignId('matched_bank_txn_id')->nullable()->constrained('acc_bank_transactions')->nullOnDelete();
            $t->boolean('is_reconciled')->default(false);
            $t->timestamp('reconciled_at')->nullable();
            $t->string('import_batch')->nullable()->index();
            $t->timestamps();
            $t->index(['bank_id', 'is_reconciled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_bank_statements');
    }
};
