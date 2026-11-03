<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // ── Attendance Regularization (employee dispute → manager/HR approve) ─────
        Schema::create('hrm_attendance_regularizations', function (Blueprint $t): void {
            $t->id();
            $t->string('regularization_no')->unique(); // REG-00001
            $t->string('employee_id')->index();
            $t->date('duty_date')->index();
            $t->enum('regularization_type', ['Missing In','Missing Out','Wrong Shift','Late Correction','Early Out Correction','On Duty','Work From Home','Remote Punch'])->index();
            $t->foreignId('assigned_shift_id')->nullable()->constrained('roster_shifts')->nullOnDelete();
            $t->time('requested_in_time')->nullable();
            $t->time('requested_out_time')->nullable();
            $t->text('reason');
            $t->enum('status', ['Pending','Approved','Rejected','Cancelled'])->default('Pending')->index();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->text('review_remarks')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();
            $t->foreign('employee_id')->references('employee_id')->on('employees')->cascadeOnDelete();
            $t->unique(['employee_id','duty_date','regularization_type'], 'uniq_emp_date_type');
            $t->index(['employee_id','status']);
        });

        // ── Shift Allowance (night / weekend / roster premium) ───────────────────
        Schema::create('hrm_shift_allowances', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->foreignId('shift_id')->constrained('roster_shifts')->cascadeOnDelete();
            $t->decimal('amount', 12, 2);
            $t->enum('amount_type', ['Fixed','Percentage of Basic'])->default('Fixed');
            $t->enum('applicable_day', ['All','Weekend','Holiday','Night'])->default('All');
            $t->date('effective_from')->nullable();
            $t->date('effective_to')->nullable();
            $t->boolean('is_active')->default(true)->index();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        // ── Overtime Policy (caps + rates + approval) ─────────────────────────────
        Schema::create('hrm_overtime_policies', function (Blueprint $t): void {
            $t->id();
            $t->string('name'); // e.g., Factory OT Policy 2026
            $t->string('employment_type')->default('All'); // Permanent, Contract, All
            $t->decimal('max_ot_hours_daily', 5, 2)->default(4.00);
            $t->decimal('max_ot_hours_monthly', 5, 2)->default(40.00);
            $t->decimal('ot_rate_multiplier', 5, 2)->default(1.00); // 1x, 1.5x, 2x
            $t->decimal('holiday_ot_rate', 5, 2)->default(2.00);
            $t->decimal('extra_duty_rate', 5, 2)->default(1.00);
            $t->boolean('requires_approval')->default(true);
            $t->unsignedTinyInteger('approval_levels')->default(1);
            $t->boolean('is_active')->default(true)->index();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        // ── Work Calendar overrides (location/dept-specific weekends) ──────────────
        Schema::create('hrm_work_calendars', function (Blueprint $t): void {
            $t->id();
            $t->string('name'); // e.g., Factory Calendar 2026
            $t->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $t->json('weekend_days')->nullable(); // [5,6] = Fri,Sat
            $t->json('working_hours')->nullable(); // {start:08:00,end:17:00}
            $t->date('effective_from')->index();
            $t->date('effective_to')->nullable();
            $t->boolean('is_active')->default(true)->index();
            $t->timestamps();
        });

        // ── Link overtime policy to daily reconciliation (snapshot) ────────────────
        Schema::table('attendance_dailies', function (Blueprint $t): void {
            $t->foreignId('regularization_id')->nullable()->after('status')->constrained('hrm_attendance_regularizations')->nullOnDelete();
            $t->boolean('is_regularized')->default(false)->after('regularization_id');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_dailies', function (Blueprint $t): void {
            $t->dropForeign(['regularization_id']);
            $t->dropColumn(['regularization_id','is_regularized']);
        });
        Schema::dropIfExists('hrm_work_calendars');
        Schema::dropIfExists('hrm_overtime_policies');
        Schema::dropIfExists('hrm_shift_allowances');
        Schema::dropIfExists('hrm_attendance_regularizations');
    }
};
