<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('employee_supervisors', function (Blueprint $table): void {
            if (!Schema::hasColumn('employee_supervisors', 'supervisor_employee_id')) {
                $table->string('supervisor_employee_id')->nullable()->after('supervisor_id');
                $table->foreign('supervisor_employee_id')->references('employee_id')->on('employees')->onDelete('cascade');
            }
            if (!Schema::hasColumn('employee_supervisors', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('supervisor_type');
            }
            if (!Schema::hasColumn('employee_supervisors', 'is_primary')) {
                $table->boolean('is_primary')->default(false)->after('is_active');
            }
            if (!Schema::hasColumn('employee_supervisors', 'effective_from')) {
                $table->date('effective_from')->nullable()->after('is_primary');
            }
            if (!Schema::hasColumn('employee_supervisors', 'effective_to')) {
                $table->date('effective_to')->nullable()->after('effective_from');
            }
            if (!Schema::hasColumn('employee_supervisors', 'remarks')) {
                $table->text('remarks')->nullable()->after('effective_to');
            }
            // Keep supervisor_id for User, add index for fast lookup
            try { $table->index(['employee_id', 'supervisor_id']); } catch (\Throwable $e) {}
            try { $table->index(['employee_id', 'supervisor_employee_id']); } catch (\Throwable $e) {}
        });
    }
    public function down(): void {
        Schema::table('employee_supervisors', function (Blueprint $table): void {
            try { $table->dropForeign(['supervisor_employee_id']); } catch (\Throwable $e) {}
            $table->dropColumn(['supervisor_employee_id','is_active','is_primary','effective_from','effective_to','remarks']);
        });
    }
};
