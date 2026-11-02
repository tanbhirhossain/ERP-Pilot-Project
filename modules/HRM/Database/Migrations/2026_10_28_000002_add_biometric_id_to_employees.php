<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('employees', function (Blueprint $table): void {
            if (!Schema::hasColumn('employees', 'biometric_id')) {
                $table->string('biometric_id')->nullable()->after('employee_id')->comment('ZK UserID / biometric enrollment ID - direct mapping to device device_user_id');
                $table->unique('biometric_id', 'employees_biometric_id_unique');
                $table->index('biometric_id', 'employees_biometric_id_index');
            }
        });

        // Backfill from existing device_users mapping (Excel stale IDs) - so admin can see current value in employee form and fix it
        // This keeps backward compatibility; new correct IDs from device sync will override via UI or map command
        try {
            if (Schema::hasTable('device_users') && Schema::hasTable('employees')) {
                $rows = \Illuminate\Support\Facades\DB::table('device_users')
                    ->whereNotNull('employee_id')
                    ->whereNotNull('device_user_id')
                    ->select('employee_id','device_user_id')
                    ->get();
                foreach ($rows as $r) {
                    // Only backfill if employee exists and biometric_id is null (don't overwrite manual fixes)
                    \Illuminate\Support\Facades\DB::table('employees')
                        ->where('employee_id', $r->employee_id)
                        ->whereNull('biometric_id')
                        ->update(['biometric_id' => $r->device_user_id]);
                }
                \Illuminate\Support\Facades\Log::info('Backfilled employees.biometric_id from device_users', ['count' => $rows->count()]);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('biometric_id backfill skipped', ['error' => $e->getMessage()]);
        }
    }

    public function down(): void {
        Schema::table('employees', function (Blueprint $table): void {
            if (Schema::hasColumn('employees', 'biometric_id')) {
                try { $table->dropUnique('employees_biometric_id_unique'); } catch (\Throwable $e) {}
                try { $table->dropIndex('employees_biometric_id_index'); } catch (\Throwable $e) {}
                $table->dropColumn('biometric_id');
            }
        });
    }
};
