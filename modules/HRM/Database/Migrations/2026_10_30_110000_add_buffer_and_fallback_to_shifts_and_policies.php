<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_policies', function (Blueprint $table): void {
            if (! Schema::hasColumn('employee_policies', 'buffer_minutes')) {
                $table->unsignedSmallInteger('buffer_minutes')->default(90)->after('auto_shift')->comment('Buffer window ± minutes for auto shift detection');
            }
            if (! Schema::hasColumn('employee_policies', 'allow_fallback')) {
                $table->boolean('allow_fallback')->default(true)->after('buffer_minutes')->comment('Allow fallback flexible shift when no match');
            }
        });

        Schema::table('roster_shifts', function (Blueprint $table): void {
            if (! Schema::hasColumn('roster_shifts', 'is_fallback')) {
                $table->boolean('is_fallback')->default(false)->after('max_duration_hours')->comment('Generic Ad-Hoc fallback shift');
            }
            if (! Schema::hasColumn('roster_shifts', 'buffer_before')) {
                $table->unsignedSmallInteger('buffer_before')->default(60)->after('is_fallback')->comment('Minutes before start to still match');
            }
            if (! Schema::hasColumn('roster_shifts', 'buffer_after')) {
                $table->unsignedSmallInteger('buffer_after')->default(90)->after('buffer_before')->comment('Minutes after end to still match');
            }
        });

        // The FLEXI / Ad-Hoc fallback shift that these columns exist to support is created by
        // Modules\HRM\Database\Seeders\RosterFallbackShiftSeeder, not here. Migrations change
        // schema; seeders create content. Report item 38 — a PURCHASE migration creating
        // ACCOUNTS' chart-of-accounts row 2115 is what silently emptied the general ledger
        // (P0-B), because content written by a migration is invisible to the seeders that own it
        // and can switch their guards off. tests/Feature/CORE/MigrationDataSeparationTest.php
        // fails the build if a migration starts provisioning content again.
        //
        // down() still deletes the row: it is dropping the columns the row is defined by.
    }

    public function down(): void
    {
        Schema::table('employee_policies', function (Blueprint $table): void {
            if (Schema::hasColumn('employee_policies', 'buffer_minutes')) {
                $table->dropColumn('buffer_minutes');
            }
            if (Schema::hasColumn('employee_policies', 'allow_fallback')) {
                $table->dropColumn('allow_fallback');
            }
        });
        Schema::table('roster_shifts', function (Blueprint $table): void {
            if (Schema::hasColumn('roster_shifts', 'is_fallback')) {
                $table->dropColumn('is_fallback');
            }
            if (Schema::hasColumn('roster_shifts', 'buffer_before')) {
                $table->dropColumn('buffer_before');
            }
            if (Schema::hasColumn('roster_shifts', 'buffer_after')) {
                $table->dropColumn('buffer_after');
            }
        });
        DB::table('roster_shifts')->where('shift_name', 'FLEXI / Ad-Hoc')->delete();
    }
};
