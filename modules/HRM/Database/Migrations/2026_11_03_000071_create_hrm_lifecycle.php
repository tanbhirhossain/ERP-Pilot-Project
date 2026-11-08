<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Probation / Confirmation ──────────────────────────
        Schema::create('hrm_employee_probations', function (Blueprint $t): void {
            $t->id();
            $t->string('employee_id')->index();
            $t->date('probation_start')->index();
            $t->date('probation_end')->index(); // original
            $t->date('extended_till')->nullable();
            $t->enum('status', ['Active', 'Extended', 'Confirmed', 'Failed', 'Terminated'])->default('Active')->index();
            $t->date('confirmed_at')->nullable();
            $t->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->text('remarks')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();
            $t->foreign('employee_id')->references('employee_id')->on('employees')->cascadeOnDelete();
            $t->index(['employee_id', 'status']);
        });

        // ── Career Movements (transfer / promotion / demotion / dept change) ─
        Schema::create('hrm_employee_movements', function (Blueprint $t): void {
            $t->id();
            $t->string('movement_no')->unique(); // MOV-00001
            $t->string('employee_id')->index();
            $t->enum('movement_type', ['Transfer', 'Promotion', 'Demotion', 'Department Change', 'Designation Change', 'Grade Change', 'Location Transfer'])->index();
            $t->date('effective_date')->index();
            $t->foreignId('from_department_id')->nullable()->constrained('departments')->nullOnDelete();
            $t->foreignId('to_department_id')->nullable()->constrained('departments')->nullOnDelete();
            $t->foreignId('from_designation_id')->nullable()->constrained('designations')->nullOnDelete();
            $t->foreignId('to_designation_id')->nullable()->constrained('designations')->nullOnDelete();
            $t->string('from_grade')->nullable();
            $t->string('to_grade')->nullable();
            $t->decimal('from_salary', 12, 2)->nullable();
            $t->decimal('to_salary', 12, 2)->nullable();
            $t->decimal('increment_amount', 12, 2)->default(0);
            $t->text('reason')->nullable();
            $t->enum('status', ['Draft', 'Pending', 'Approved', 'Rejected', 'Cancelled'])->default('Draft')->index();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();
            $t->foreign('employee_id')->references('employee_id')->on('employees')->cascadeOnDelete();
        });

        // ── Checklist Templates (onboarding / offboarding) ─────
        Schema::create('hrm_checklist_templates', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->enum('category', ['Onboarding', 'Offboarding'])->index();
            $t->string('employment_type')->nullable(); // Permanent / Contract / Probation / all
            $t->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $t->boolean('is_active')->default(true)->index();
            $t->text('description')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('hrm_checklist_items', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('template_id')->constrained('hrm_checklist_templates')->cascadeOnDelete();
            $t->string('title');
            $t->text('description')->nullable();
            $t->string('responsible_role')->default('HR'); // HR, IT, Admin, Finance, Manager
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->boolean('is_required')->default(true);
            $t->timestamps();
        });

        // ── Employee checklist assignments (instance per employee) ─
        Schema::create('hrm_employee_checklists', function (Blueprint $t): void {
            $t->id();
            $t->string('employee_id')->index();
            $t->foreignId('template_id')->constrained('hrm_checklist_templates')->cascadeOnDelete();
            $t->foreignId('movement_id')->nullable()->constrained('hrm_employee_movements')->nullOnDelete();
            $t->unsignedBigInteger('resignation_id')->nullable(); // FK to hrm_employee_resignations if exists
            $t->enum('status', ['Pending', 'In Progress', 'Completed', 'Cancelled'])->default('Pending')->index();
            $t->decimal('progress_pct', 5, 2)->default(0);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->foreign('employee_id')->references('employee_id')->on('employees')->cascadeOnDelete();
            $t->index(['employee_id', 'status']);
        });

        Schema::create('hrm_employee_checklist_progress', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('employee_checklist_id')->constrained('hrm_employee_checklists')->cascadeOnDelete();
            $t->foreignId('checklist_item_id')->constrained('hrm_checklist_items')->cascadeOnDelete();
            $t->enum('status', ['Pending', 'Done', 'Skipped', 'N/A'])->default('Pending')->index();
            $t->text('remarks')->nullable();
            $t->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
            $t->unique(['employee_checklist_id', 'checklist_item_id']);
        });

        // ── Offboarding clearance gate (extends resignation) ────
        Schema::create('hrm_clearance_tasks', function (Blueprint $t): void {
            $t->id();
            $t->string('employee_id')->index();
            $t->unsignedBigInteger('resignation_id')->nullable();
            $t->string('department')->default('HR'); // HR, IT, Admin, Finance, Inventory, Payroll
            $t->string('task_name');
            $t->enum('status', ['Pending', 'Cleared', 'Rejected', 'Waived'])->default('Pending')->index();
            $t->text('remarks')->nullable();
            $t->foreignId('cleared_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('cleared_at')->nullable();
            $t->timestamps();
            $t->foreign('employee_id')->references('employee_id')->on('employees')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hrm_clearance_tasks');
        Schema::dropIfExists('hrm_employee_checklist_progress');
        Schema::dropIfExists('hrm_employee_checklists');
        Schema::dropIfExists('hrm_checklist_items');
        Schema::dropIfExists('hrm_checklist_templates');
        Schema::dropIfExists('hrm_employee_movements');
        Schema::dropIfExists('hrm_employee_probations');
    }
};
