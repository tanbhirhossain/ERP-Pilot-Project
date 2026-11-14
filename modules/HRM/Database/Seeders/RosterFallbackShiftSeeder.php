<?php

namespace Modules\HRM\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The FLEXI / Ad-Hoc fallback shift: the row `AttendanceProcessingService` classifies a punch
 * against when the employee has no roster duty for that date.
 *
 * Extracted from `modules/HRM/Database/Migrations/2026_10_30_110000_add_buffer_and_fallback_to_shifts_and_policies.php`
 * (report §7 item 8, recorded as item 38), which added the `is_fallback` / `buffer_before` /
 * `buffer_after` columns and then created this row in the same `up()`. A migration is the right
 * place for the columns and the wrong place for the row: content written by a migration is
 * invisible to the seeders that own it, and this project has already paid for that once —
 * a PURCHASE migration creating ACCOUNTS' chart-of-accounts row 2115 made a seeder's
 * `if (Model::exists()) return;` guard true, which is what silently emptied the general ledger
 * (P0-B). `MigrationDataSeparationTest` now fails the build if any migration does it again.
 *
 * The values are unchanged from the migration, so a freshly seeded database holds exactly what
 * it held before: one fallback shift, 00:00:00–23:59:00, no buffers.
 *
 * Note for whoever owns demo content: `RosterShiftSeeder` in this same directory contains an
 * identical fallback block. Both are idempotent on `is_fallback`, so they cannot conflict.
 *
 * History: `RosterShiftSeeder` (six real shifts) and `LeaveTypeSeeder` stayed unwired when this
 * row was moved out of a migration (item 38, finding 39) — real shifts change how every punch is
 * classified, which deserved its own decision. On 2026-11-10 the shift + leave-type catalogues were
 * registered in `DatabaseSeeder` on purpose, so a plain `php artisan db:seed` now produces the
 * standard shift list and the statutory leave types everywhere. Consequence to remember: anything
 * that used to rely on "no real shifts exist yet" must look up its own rows by name — `DemoDataSeeder
 * ::roster()` now checks for its 'General Day' shift explicitly for exactly that reason.
 */
class RosterFallbackShiftSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('roster_shifts') || ! Schema::hasColumn('roster_shifts', 'is_fallback')) {
            return;
        }

        if (DB::table('roster_shifts')->where('is_fallback', true)->exists()) {
            return;
        }

        DB::table('roster_shifts')->insert([
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
