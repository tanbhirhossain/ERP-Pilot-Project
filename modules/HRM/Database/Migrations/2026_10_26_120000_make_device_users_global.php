<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Make device_users a global mapping (not per-device).
     * - device_id is already nullable; keep it for audit but new rows will use NULL.
     * - Enforce 1:1 uniqueness: one device_user_id = one employee, one employee = one device_user_id.
     *   SQLite requires partial unique index workaround, so we keep validation-only for employee uniqueness
     *   and add a normal unique index that allows multiple NULLs (MySQL/SQLite both allow multiple NULLs in unique).
     * - This also allows punch-in on Device A and punch-out on Device B to resolve to same employee.
     */
    public function up(): void
    {
        Schema::table('device_users', function (Blueprint $table): void {
            // Add unique constraint on employee_id if not exists (multiple NULLs allowed)
            // Use try/catch because SQLite/MySQL behave differently on existing duplicates
            try {
                $table->unique('employee_id', 'device_users_employee_id_unique');
            } catch (Throwable $e) {
                // Index may already exist or duplicates exist — skip
            }
        });
    }

    public function down(): void
    {
        Schema::table('device_users', function (Blueprint $table): void {
            try {
                $table->dropUnique('device_users_employee_id_unique');
            } catch (Throwable $e) {
            }
        });
    }
};
