<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_attendance_duty_decisions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('roster_overtime_id');
            $table->string('employee_id');
            $table->date('duty_date');
            $table->string('duty_type', 30);
            $table->decimal('attendance_source_hours', 6, 2);
            $table->decimal('requested_hours', 6, 2);
            $table->decimal('approved_hours', 6, 2)->nullable();
            $table->enum('decision', ['approved', 'rejected']);
            $table->text('comments')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at');
            $table->timestamps();
            $table->unique('roster_overtime_id');
            $table->index(['employee_id', 'duty_date', 'decision']);
        });

        Schema::create('payroll_employee_tax_profile_versions', function (Blueprint $table): void {
            $table->id();
            $table->string('employee_id');
            $table->enum('override_mode', ['global_config', 'force_fixed', 'exempt']);
            $table->decimal('fixed_tax_amount', 12, 2)->default(0);
            $table->enum('fixed_tax_frequency', ['monthly', 'annual'])->default('monthly');
            $table->text('exemption_reason')->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->text('change_reason');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['employee_id', 'effective_from', 'effective_to'], 'tax_profile_versions_effective_idx');
        });

        Schema::create('payroll_tax_audits', function (Blueprint $table): void {
            $table->id();
            $table->string('event_type', 50);
            $table->unsignedBigInteger('subject_id');
            $table->string('employee_id')->nullable();
            $table->text('change_reason')->nullable();
            $table->json('before_json')->nullable();
            $table->json('after_json');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['event_type', 'subject_id']);
            $table->index(['employee_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_tax_audits');
        Schema::dropIfExists('payroll_employee_tax_profile_versions');
        Schema::dropIfExists('payroll_attendance_duty_decisions');
    }
};
