<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F14 — Budgets & variance.
 *
 * Two tables:
 *   acc_budgets       — a plan for one fiscal year, optionally scoped to a department/cost centre.
 *                       Starts `draft`, becomes `approved` once signed off (the approval is the
 *                       trigger the variance report treats as "the committed plan").
 *   acc_budget_lines  — one account × one period (YYYY-MM) × an amount, in the account's natural
 *                       direction (planned spend for a debit account, planned income for a credit
 *                       one). Unique (budget, account, period) is the idempotency key the line
 *                       upsert relies on.
 *
 * Actuals are not stored: they are read live from `acc_account_ledger`, so a budget can never drift
 * out of step with the books — the variance report is always reconciled to the posted ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acc_budgets', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code', 40)->unique();
            $table->foreignId('fiscal_year_id')->constrained('acc_fiscal_years')->restrictOnDelete();
            $table->string('department', 120)->nullable();
            $table->string('currency', 3)->default('BDT');
            $table->enum('status', ['draft', 'approved', 'locked'])->default('draft')->index();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->unique(['fiscal_year_id', 'name']);
        });

        Schema::create('acc_budget_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('budget_id')->constrained('acc_budgets')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('acc_accounts')->restrictOnDelete();
            $table->string('period', 7)->comment('YYYY-MM calendar month within the budget fiscal year');
            $table->decimal('amount', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['budget_id', 'account_id', 'period']);
            $table->index(['account_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_budget_lines');
        Schema::dropIfExists('acc_budgets');
    }
};
