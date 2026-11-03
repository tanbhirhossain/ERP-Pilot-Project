<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // ── Journal posting idempotency + audit (1 sheet → 1 salary JV, N payment JVs) ──
        Schema::create('payroll_journal_posts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('salary_sheet_id')->nullable()->constrained('payroll_salary_sheets')->nullOnDelete();
            $t->foreignId('settlement_id')->nullable()->constrained('payroll_settlements')->nullOnDelete();
            $t->foreignId('bonus_run_id')->nullable()->constrained('festival_bonus_runs')->nullOnDelete();
            $t->string('posting_type', 30); // salary_accrual, salary_payment, bonus, settlement, pf, tax
            $t->string('reference_no')->nullable(); // PAY-2026-11, SET-0001 etc.
            $t->foreignId('journal_id')->nullable()->constrained('acc_journals')->nullOnDelete();
            $t->decimal('total_debit', 15, 2)->default(0);
            $t->decimal('total_credit', 15, 2)->default(0);
            $t->enum('status', ['draft','posted','void'])->default('posted');
            $t->json('cost_center_breakdown')->nullable(); // {dept: amount}
            $t->json('meta')->nullable(); // snapshot of lines
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['salary_sheet_id','posting_type'], 'uniq_sheet_posting'); // idempotent
            $t->index(['posting_type','status']);
            $t->index('journal_id');
        });

        // ── BEFTN / Bank Advice (one per bank + period) ──
        Schema::create('payroll_bank_advices', function (Blueprint $t): void {
            $t->id();
            $t->string('advice_no')->unique(); // ADV-PAY-202611-001
            $t->unsignedSmallInteger('pay_year');
            $t->unsignedTinyInteger('pay_month');
            $t->foreignId('salary_sheet_id')->constrained('payroll_salary_sheets')->cascadeOnDelete();
            $t->foreignId('bank_id')->nullable()->constrained('acc_banks')->nullOnDelete(); // which disbursement bank
            $t->string('bank_name')->nullable();
            $t->string('branch_name')->nullable();
            $t->string('account_no')->nullable(); // company source account
            $t->decimal('total_amount', 15, 2)->default(0);
            $t->unsignedInteger('employee_count')->default(0);
            $t->enum('status', ['draft','confirmed','paid','cancelled'])->default('draft')->index();
            $t->string('file_path')->nullable(); // stored Excel/CSV
            $t->string('file_format', 10)->default('xlsx'); // xlsx, csv, beftn
            $t->text('narration')->nullable();
            $t->foreignId('payroll_journal_post_id')->nullable()->constrained('payroll_journal_posts')->nullOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('confirmed_at')->nullable();
            $t->timestamps();
            $t->index(['pay_year','pay_month','status']);
            $t->unique(['salary_sheet_id','bank_id'], 'uniq_sheet_bank');
        });

        Schema::create('payroll_bank_advice_items', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('advice_id')->constrained('payroll_bank_advices')->cascadeOnDelete();
            $t->string('employee_id')->index();
            $t->string('employee_name');
            $t->string('department')->nullable();
            $t->string('designation')->nullable();
            $t->string('bank_account_no')->nullable(); // employee's account
            $t->string('bank_name')->nullable();
            $t->string('branch_name')->nullable();
            $t->string('routing_no', 20)->nullable(); // BEFTN routing
            $t->decimal('net_amount', 12, 2);
            $t->enum('status', ['pending','transferred','failed','cancelled'])->default('pending');
            $t->string('reference')->nullable();
            $t->timestamps();
            $t->index(['advice_id','employee_id']);
            $t->index(['bank_account_no']);
        });

        // ── Extend slips with payment journal link (audit) ──
        Schema::table('payroll_slips', function (Blueprint $t): void {
            $t->foreignId('payment_journal_id')->nullable()->after('paid_by')->constrained('acc_journals')->nullOnDelete();
            $t->string('cost_center')->nullable()->after('payment_journal_id'); // dept code
        });

        // ── Extend salary sheets with accrual journal link ──
        Schema::table('payroll_salary_sheets', function (Blueprint $t): void {
            $t->foreignId('accrual_journal_id')->nullable()->after('payroll_cutlist_id')->constrained('acc_journals')->nullOnDelete();
            $t->json('accrual_cost_centers')->nullable()->after('accrual_journal_id');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_salary_sheets', function (Blueprint $t): void {
            $t->dropForeign(['accrual_journal_id']);
            $t->dropColumn(['accrual_journal_id','accrual_cost_centers']);
        });
        Schema::table('payroll_slips', function (Blueprint $t): void {
            $t->dropForeign(['payment_journal_id']);
            $t->dropColumn(['payment_journal_id','cost_center']);
        });
        Schema::dropIfExists('payroll_bank_advice_items');
        Schema::dropIfExists('payroll_bank_advices');
        Schema::dropIfExists('payroll_journal_posts');
    }
};
