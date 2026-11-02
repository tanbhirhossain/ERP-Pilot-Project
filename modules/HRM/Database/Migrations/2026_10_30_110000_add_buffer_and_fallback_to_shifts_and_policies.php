<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('employee_policies', function (Blueprint $table): void {
            if (!Schema::hasColumn('employee_policies', 'buffer_minutes')) {
                $table->unsignedSmallInteger('buffer_minutes')->default(90)->after('auto_shift')->comment('Buffer window ± minutes for auto shift detection');
            }
            if (!Schema::hasColumn('employee_policies', 'allow_fallback')) {
                $table->boolean('allow_fallback')->default(true)->after('buffer_minutes')->comment('Allow fallback flexible shift when no match');
            }
        });

        Schema::table('roster_shifts', function (Blueprint $table): void {
            if (!Schema::hasColumn('roster_shifts', 'is_fallback')) {
                $table->boolean('is_fallback')->default(false)->after('max_duration_hours')->comment('Generic Ad-Hoc fallback shift');
            }
            if (!Schema::hasColumn('roster_shifts', 'buffer_before')) {
                $table->unsignedSmallInteger('buffer_before')->default(60)->after('is_fallback')->comment('Minutes before start to still match');
            }
            if (!Schema::hasColumn('roster_shifts', 'buffer_after')) {
                $table->unsignedSmallInteger('buffer_after')->default(90)->after('buffer_before')->comment('Minutes after end to still match');
            }
        });

        // Create fallback shift if not exists
        $exists = \Illuminate\Support\Facades\DB::table('roster_shifts')->where('shift_name', 'FLEXI / Ad-Hoc')->exists();
        if (!$exists) {
            \Illuminate\Support\Facades\DB::table('roster_shifts')->insert([
                'shift_name' => 'FLEXI / Ad-Hoc',
                'start_time' => '00:00:00',
                'end_time' => '23:59:00',
                'punch_in_window_start' => null,
                'punch_in_window_end' => null,
                'crosses_midnight' => false,
                'max_duration_hours' => 24,
                'is_fallback' => true,
                'buffer_before' => 0,
                'buffer_after' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('employee_policies', function (Blueprint $table): void {
            if (Schema::hasColumn('employee_policies', 'buffer_minutes')) $table->dropColumn('buffer_minutes');
            if (Schema::hasColumn('employee_policies', 'allow_fallback')) $table->dropColumn('allow_fallback');
        });
        Schema::table('roster_shifts', function (Blueprint $table): void {
            if (Schema::hasColumn('roster_shifts', 'is_fallback')) $table->dropColumn('is_fallback');
            if (Schema::hasColumn('roster_shifts', 'buffer_before')) $table->dropColumn('buffer_before');
            if (Schema::hasColumn('roster_shifts', 'buffer_after')) $table->dropColumn('buffer_after');
        });
        \Illuminate\Support\Facades\DB::table('roster_shifts')->where('shift_name', 'FLEXI / Ad-Hoc')->delete();
    }
};
