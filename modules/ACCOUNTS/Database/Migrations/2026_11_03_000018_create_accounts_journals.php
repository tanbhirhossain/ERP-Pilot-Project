<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('acc_journals', function (Blueprint $t) {
            $t->id();
            $t->string('journal_no')->unique(); // JV-00001
            $t->string('reference_no')->nullable()->index();
            $t->date('journal_date')->index();
            $t->enum('type', ['JV', 'PV', 'RV', 'CV', 'BV', 'SV', 'PV'])->default('JV')->comment('Journal/Payment/Receipt/Cash/Bank/Sales/Purchase Voucher');
            $t->foreignId('fiscal_year_id')->nullable()->constrained('acc_fiscal_years')->nullOnDelete();
            $t->foreignId('fiscal_period_id')->nullable()->constrained('acc_fiscal_periods')->nullOnDelete();
            $t->string('narration')->nullable();
            $t->decimal('total_debit', 15, 2)->default(0);
            $t->decimal('total_credit', 15, 2)->default(0);
            $t->enum('status', ['draft', 'posted', 'void', 'reversed'])->default('draft')->index();
            $t->foreignId('partner_id')->nullable()->comment('Vendor/Customer link')->index();
            $t->string('partner_type')->nullable();
            $t->string('source_type')->nullable()->comment('Polymorphic source: sales, purchase, inventory');
            $t->unsignedBigInteger('source_id')->nullable();
            $t->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('posted_at')->nullable();
            $t->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('voided_at')->nullable();
            $t->text('void_reason')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['journal_date', 'status']);
            $t->index(['source_type', 'source_id']);
            $t->index(['type', 'status']);
        });

        Schema::create('acc_journal_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('journal_id')->constrained('acc_journals')->cascadeOnDelete();
            $t->foreignId('account_id')->constrained('acc_accounts')->restrictOnDelete();
            $t->decimal('debit', 15, 2)->default(0);
            $t->decimal('credit', 15, 2)->default(0);
            $t->string('description')->nullable();
            $t->foreignId('partner_id')->nullable()->index();
            $t->string('partner_type')->nullable();
            $t->string('cost_center')->nullable();
            $t->unsignedInteger('line_no')->default(1);
            $t->timestamps();
            $t->index(['account_id', 'journal_id']);
        });

        Schema::create('acc_account_ledger', function (Blueprint $t) {
            $t->id();
            $t->foreignId('account_id')->constrained('acc_accounts')->cascadeOnDelete();
            $t->foreignId('journal_id')->constrained('acc_journals')->cascadeOnDelete();
            $t->foreignId('journal_line_id')->constrained('acc_journal_lines')->cascadeOnDelete();
            $t->date('posting_date')->index();
            $t->decimal('debit', 15, 2)->default(0);
            $t->decimal('credit', 15, 2)->default(0);
            $t->decimal('balance_after', 15, 2)->default(0)->comment('Running balance snapshot');
            $t->string('reference_no')->nullable();
            $t->timestamps();
            $t->index(['account_id', 'posting_date']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('acc_account_ledger');
        Schema::dropIfExists('acc_journal_lines');
        Schema::dropIfExists('acc_journals');
    }
};
