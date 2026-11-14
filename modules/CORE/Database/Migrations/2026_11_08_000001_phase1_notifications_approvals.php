<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 — Enterprise Core schema.
 *
 *  - notifications: Laravel database-notification channel (the bell/inbox).
 *  - approval_policies / approval_requests: a generic, configurable approval
 *    matrix with segregation-of-duties, applied to money documents.
 *  - opening_balance_batches: idempotency + audit for the go-live take-on wizard.
 *
 * Schema only. Default policies and the opening-balance equity account are
 * seeded by Phase1Seeder, never by a migration (report item 38).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('type');
                $table->morphs('notifiable');
                $table->text('data');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
                $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
            });
        }

        Schema::create('approval_policies', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            // purchase_order | vendor_bill | journal | sales_invoice | payment
            $table->string('document_type', 64);
            $table->decimal('min_amount', 15, 2)->default(0);
            $table->string('approver_permission', 128);
            // SoD: the requester may never approve their own request.
            $table->boolean('require_segregation')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('priority')->default(0);
            $table->text('description')->nullable();
            $table->timestamps();
            $table->index(['document_type', 'is_active', 'min_amount']);
        });

        Schema::create('approval_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('policy_id')->constrained('approval_policies')->cascadeOnDelete();
            $table->string('document_type', 64);
            $table->unsignedBigInteger('document_id');
            $table->string('document_no', 64)->nullable();
            $table->decimal('amount', 15, 2)->default(0);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            // pending | approved | rejected | cancelled
            $table->string('status', 24)->default('pending')->index();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();
            $table->index(['document_type', 'document_id', 'status']);
        });

        Schema::create('opening_balance_batches', function (Blueprint $table): void {
            $table->id();
            // coa | ar | ap | stock
            $table->string('type', 24);
            $table->string('reference', 64)->unique();
            $table->date('as_of_date');
            $table->foreignId('journal_id')->nullable()->constrained('acc_journals')->nullOnDelete();
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['type', 'as_of_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opening_balance_batches');
        Schema::dropIfExists('approval_requests');
        Schema::dropIfExists('approval_policies');
        Schema::dropIfExists('notifications');
    }
};
