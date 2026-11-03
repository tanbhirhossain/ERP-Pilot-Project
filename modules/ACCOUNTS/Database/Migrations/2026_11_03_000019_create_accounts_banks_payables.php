<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('acc_banks', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('code')->unique(); // BANK-001
            $t->string('bank_name');
            $t->string('branch_name')->nullable();
            $t->string('account_no')->unique();
            $t->string('account_holder')->nullable();
            $t->string('swift_code')->nullable();
            $t->foreignId('account_id')->constrained('acc_accounts')->restrictOnDelete()->comment('Linked COA account');
            $t->string('currency', 3)->default('BDT');
            $t->decimal('opening_balance', 15, 2)->default(0);
            $t->decimal('current_balance', 15, 2)->default(0);
            $t->boolean('is_default')->default(false);
            $t->boolean('is_reconcile')->default(true);
            $t->boolean('is_active')->default(true);
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('acc_bank_transactions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('bank_id')->constrained('acc_banks')->cascadeOnDelete();
            $t->foreignId('journal_id')->nullable()->constrained('acc_journals')->nullOnDelete();
            $t->enum('type', ['deposit', 'withdrawal', 'transfer_in', 'transfer_out', 'fee', 'interest'])->default('deposit');
            $t->decimal('amount', 15, 2);
            $t->string('reference_no')->nullable();
            $t->date('transaction_date')->index();
            $t->enum('status', ['pending', 'cleared', 'bounced', 'void'])->default('pending');
            $t->text('description')->nullable();
            $t->timestamps();
            $t->index(['bank_id', 'transaction_date']);
        });

        Schema::create('acc_payables', function (Blueprint $t) {
            $t->id();
            $t->foreignId('partner_id')->index()->comment('Vendor');
            $t->foreignId('journal_id')->constrained('acc_journals')->cascadeOnDelete();
            $t->string('bill_no')->nullable()->index();
            $t->date('bill_date')->nullable();
            $t->date('due_date')->nullable()->index();
            $t->decimal('amount', 15, 2);
            $t->decimal('paid_amount', 15, 2)->default(0);
            $t->decimal('balance', 15, 2)->storedAs('amount - paid_amount');
            $t->enum('status', ['open', 'partial', 'paid', 'overdue', 'void'])->default('open')->index();
            $t->timestamps();
            $t->index(['partner_id', 'status']);
        });

        Schema::create('acc_receivables', function (Blueprint $t) {
            $t->id();
            $t->foreignId('partner_id')->index()->comment('Customer');
            $t->foreignId('journal_id')->constrained('acc_journals')->cascadeOnDelete();
            $t->string('invoice_no')->nullable()->index();
            $t->date('invoice_date')->nullable();
            $t->date('due_date')->nullable()->index();
            $t->decimal('amount', 15, 2);
            $t->decimal('received_amount', 15, 2)->default(0);
            $t->decimal('balance', 15, 2)->storedAs('amount - received_amount');
            $t->enum('status', ['open', 'partial', 'paid', 'overdue', 'void'])->default('open')->index();
            $t->timestamps();
            $t->index(['partner_id', 'status']);
        });

        Schema::create('acc_closing_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('fiscal_year_id')->constrained('acc_fiscal_years')->cascadeOnDelete();
            $t->foreignId('journal_id')->constrained('acc_journals')->cascadeOnDelete();
            $t->enum('type', ['income_close', 'expense_close', 'retained_earnings'])->default('income_close');
            $t->decimal('amount', 15, 2);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('acc_closing_entries');
        Schema::dropIfExists('acc_receivables');
        Schema::dropIfExists('acc_payables');
        Schema::dropIfExists('acc_bank_transactions');
        Schema::dropIfExists('acc_banks');
    }
};
