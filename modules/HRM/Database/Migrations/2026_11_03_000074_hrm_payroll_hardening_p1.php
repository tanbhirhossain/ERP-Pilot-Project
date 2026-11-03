<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // ── HRM: Leave carry-forward / encashment policy ──
        Schema::table('leave_type_settings', function (Blueprint $t): void {
            if (!Schema::hasColumn('leave_type_settings', 'is_carry_forward')) $t->boolean('is_carry_forward')->default(false)->after('proration_mode');
            if (!Schema::hasColumn('leave_type_settings', 'max_carry_days')) $t->decimal('max_carry_days', 5, 2)->nullable()->after('is_carry_forward');
            if (!Schema::hasColumn('leave_type_settings', 'carry_expiry_months')) $t->unsignedTinyInteger('carry_expiry_months')->default(3)->after('max_carry_days');
            if (!Schema::hasColumn('leave_type_settings', 'is_encashable')) $t->boolean('is_encashable')->default(false)->after('carry_expiry_months');
            if (!Schema::hasColumn('leave_type_settings', 'encash_max_days')) $t->decimal('encash_max_days', 5, 2)->nullable()->after('is_encashable');
            if (!Schema::hasColumn('leave_type_settings', 'encash_salary_component')) $t->string('encash_salary_component', 20)->default('basic')->after('encash_max_days');
        });

        // ── HRM: Yearly leave balance snapshot (carry/close audit) ──
        Schema::create('hrm_leave_balances', function (Blueprint $t): void {
            $t->id();
            $t->string('employee_id')->index();
            $t->foreignId('leave_type_id')->constrained('leave_types')->cascadeOnDelete();
            $t->unsignedSmallInteger('year');
            $t->decimal('opening_balance', 6, 2)->default(0); // carried from prev
            $t->decimal('entitlement', 6, 2)->default(0); // annual/daily pro-rata
            $t->decimal('carry_forward', 6, 2)->default(0); // actually carried
            $t->decimal('availed', 6, 2)->default(0); // approved taken
            $t->decimal('pending', 6, 2)->default(0);
            $t->decimal('encashed', 6, 2)->default(0);
            $t->decimal('lapsed', 6, 2)->default(0); // expired carry not used
            $t->decimal('available', 6, 2)->default(0);
            $t->boolean('is_closed')->default(false)->index();
            $t->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('closed_at')->nullable();
            $t->timestamps();
            $t->unique(['employee_id','leave_type_id','year']);
            $t->index(['year','is_closed']);
        });

        // ── PAYROLL: Settlement auto-prefill (HRM-sourced) ──
        Schema::table('payroll_settlements', function (Blueprint $t): void {
            if (!Schema::hasColumn('payroll_settlements', 'encashable_days')) $t->decimal('encashable_days', 6, 2)->nullable()->after('leave_encashment');
            if (!Schema::hasColumn('payroll_settlements', 'asset_recovery_total')) $t->decimal('asset_recovery_total', 10, 2)->default(0)->after('deductions');
            if (!Schema::hasColumn('payroll_settlements', 'reopen_count')) $t->unsignedTinyInteger('reopen_count')->default(0)->after('approved_at');
        });

        // ── PAYROLL: Salary sheet reopen audit ──
        Schema::create('payroll_sheet_reopens', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('salary_sheet_id')->constrained('payroll_salary_sheets')->cascadeOnDelete();
            $t->string('reason', 500);
            $t->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('reversal_journal_id')->nullable()->constrained('acc_journals')->nullOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->timestamps();
            $t->index(['salary_sheet_id']);
        });

        // ── Generic: patch attendance_dailies already done in 000072, ensure is_regularized exists (idempotent) ──
        if (Schema::hasTable('attendance_dailies') && !Schema::hasColumn('attendance_dailies','is_regularized')) {
            Schema::table('attendance_dailies', function (Blueprint $t): void {
                $t->boolean('is_regularized')->default(false)->after('status');
                $t->foreignId('regularization_id')->nullable()->after('is_regularized')->constrained('hrm_attendance_regularizations')->nullOnDelete();
            });
        }
    }
    public function down(): void
    {
        Schema::dropIfExists('payroll_sheet_reopens');
        Schema::dropIfExists('hrm_leave_balances');
        Schema::table('payroll_settlements', function (Blueprint $t): void {
            $t->dropColumn(['encashable_days','asset_recovery_total','reopen_count']);
        });
        Schema::table('leave_type_settings', function (Blueprint $t): void {
            $t->dropColumn(['is_carry_forward','max_carry_days','carry_expiry_months','is_encashable','encash_max_days','encash_salary_component']);
        });
    }
};
