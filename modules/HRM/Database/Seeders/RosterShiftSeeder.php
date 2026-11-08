<?php

namespace Modules\HRM\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RosterShiftSeeder extends Seeder
{
    public function run(): void
    {
        $shifts = [
            // Requested 6 — all 8h, 24h max window; Night crosses midnight = true
            ['shift_name' => 'Evening 03PM-11PM',   'start_time' => '15:00:00', 'end_time' => '23:00:00', 'crosses_midnight' => false, 'max_duration_hours' => 24, 'punch_in_window_start' => '14:00:00', 'punch_in_window_end' => '16:00:00', 'buffer_before' => 60, 'buffer_after' => 90, 'is_fallback' => false],
            ['shift_name' => 'Morning 08AM-04PM',   'start_time' => '08:00:00', 'end_time' => '16:00:00', 'crosses_midnight' => false, 'max_duration_hours' => 24, 'punch_in_window_start' => '07:00:00', 'punch_in_window_end' => '09:30:00', 'buffer_before' => 60, 'buffer_after' => 90, 'is_fallback' => false],
            ['shift_name' => 'Night 11PM-07AM',     'start_time' => '23:00:00', 'end_time' => '07:00:00', 'crosses_midnight' => true,  'max_duration_hours' => 24, 'punch_in_window_start' => '22:00:00', 'punch_in_window_end' => '23:59:00', 'buffer_before' => 60, 'buffer_after' => 90, 'is_fallback' => false],
            ['shift_name' => 'Late-Morning 11AM-07PM', 'start_time' => '11:00:00', 'end_time' => '19:00:00', 'crosses_midnight' => false, 'max_duration_hours' => 24, 'punch_in_window_start' => '10:00:00', 'punch_in_window_end' => '12:00:00', 'buffer_before' => 60, 'buffer_after' => 90, 'is_fallback' => false],
            ['shift_name' => 'Day 10AM-06PM',       'start_time' => '10:00:00', 'end_time' => '18:00:00', 'crosses_midnight' => false, 'max_duration_hours' => 24, 'punch_in_window_start' => '09:00:00', 'punch_in_window_end' => '11:00:00', 'buffer_before' => 60, 'buffer_after' => 90, 'is_fallback' => false],
            ['shift_name' => 'General 09AM-05PM',  'start_time' => '09:00:00', 'end_time' => '17:00:00', 'crosses_midnight' => false, 'max_duration_hours' => 24, 'punch_in_window_start' => '08:00:00', 'punch_in_window_end' => '10:00:00', 'buffer_before' => 60, 'buffer_after' => 90, 'is_fallback' => false],
        ];

        foreach ($shifts as $s) {
            // Upsert by shift_name to keep id stable
            $existing = DB::table('roster_shifts')->where('shift_name', $s['shift_name'])->first();
            if ($existing) {
                DB::table('roster_shifts')->where('id', $existing->id)->update(array_merge($s, ['updated_at' => now()]));
            } else {
                DB::table('roster_shifts')->insert(array_merge($s, ['created_at' => now(), 'updated_at' => now()]));
            }
        }

        // Ensure fallback exists (Ad-Hoc)
        $hasFallback = DB::table('roster_shifts')->where('is_fallback', true)->exists();
        if (! $hasFallback) {
            DB::table('roster_shifts')->insert([
                'shift_name' => 'FLEXI / Ad-Hoc', 'start_time' => '00:00:00', 'end_time' => '23:59:00',
                'crosses_midnight' => false, 'max_duration_hours' => 24, 'is_fallback' => true,
                'buffer_before' => 0, 'buffer_after' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
}
