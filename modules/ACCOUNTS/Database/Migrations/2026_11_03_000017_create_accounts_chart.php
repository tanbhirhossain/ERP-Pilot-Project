<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('acc_accounts', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique(); // 1001, 1100, 4000 hierarchical
            $t->string('name');
            $t->string('name_bn')->nullable();
            $t->string('slug')->unique();
            $t->enum('account_type', ['asset', 'liability', 'equity', 'income', 'expense'])->index();
            $t->enum('nature', ['debit', 'credit'])->comment('Normal balance');
            $t->foreignId('parent_id')->nullable()->constrained('acc_accounts')->nullOnDelete();
            $t->unsignedInteger('level')->default(1);
            $t->string('path')->nullable()->comment('Materialized path 1/5/12');
            $t->boolean('is_group')->default(false)->comment('Group header, no postings');
            $t->boolean('is_posting')->default(true);
            $t->boolean('is_system')->default(false)->comment('Protected system accounts');
            $t->boolean('is_bank')->default(false);
            $t->boolean('is_cash')->default(false);
            $t->boolean('is_reconcile')->default(false);
            $t->string('currency', 3)->default('BDT');
            $t->decimal('opening_balance', 15, 2)->default(0);
            $t->enum('opening_side', ['debit', 'credit'])->default('debit');
            $t->text('description')->nullable();
            $t->boolean('is_active')->default(true);
            $t->unsignedInteger('sort_order')->default(0);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['account_type', 'is_active']);
            $t->index(['parent_id', 'level']);
            $t->index('is_group');
        });
    }
    public function down(): void { Schema::dropIfExists('acc_accounts'); }
};
