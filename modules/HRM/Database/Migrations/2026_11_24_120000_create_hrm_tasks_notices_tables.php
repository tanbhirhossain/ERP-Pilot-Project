<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 — work assignment + notices (mobile-first domains; the web desks
 * arrive later and will sit on the same tables).
 *
 * hrm_tasks: assigner → assignee work items with a verify/close tail.
 * hrm_notices: targeted announcements (all | department | employees).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hrm_tasks', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('employee_id');
            $table->foreignId('assigned_by')->constrained('users')->cascadeOnDelete();
            $table->enum('priority', ['Low', 'Medium', 'High', 'Urgent'])->default('Medium');
            $table->enum('status', ['Open', 'In Progress', 'Done', 'Verified', 'Closed', 'Cancelled'])->default('Open');
            $table->date('due_date')->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->foreign('employee_id')->references('employee_id')->on('employees')->onDelete('cascade');
            $table->index(['employee_id', 'status']);
            $table->index(['assigned_by', 'status']);
        });

        Schema::create('hrm_notices', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->text('body');
            $table->foreignId('posted_by')->constrained('users')->cascadeOnDelete();
            $table->enum('audience_type', ['all', 'department', 'employees']);
            $table->json('audience_ids')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('expire_at')->nullable();
            $table->timestamps();

            $table->index(['audience_type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hrm_notices');
        Schema::dropIfExists('hrm_tasks');
    }
};
