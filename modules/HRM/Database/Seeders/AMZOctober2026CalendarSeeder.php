<?php

namespace Modules\HRM\Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Modules\HRM\Models\Department;
use Modules\HRM\Models\Employee\Employee;
use Modules\HRM\Models\Employee\EmployeeFallbackOffDay;
use Modules\HRM\Models\Employee\EmployeePolicy;
use Modules\HRM\Models\Holiday;
use Modules\HRM\Models\Leave\LeaveRequest;
use Modules\HRM\Models\Leave\LeaveType;
use Modules\HRM\Models\Roster\RosterDuty;
use Modules\HRM\Models\Roster\RosterShift;

/**
 * The October 2026 attendance-demo calendar, requested 2026-11-10:
 *
 *  - roster duty 15:00–23:00 for L402003 every day 2026-10-01..2026-10-31, Fridays Off (status 'Off'
 *    duty rows + the Friday fallback off-day the OffDayResolver reads when rostering lacks an Off row);
 *  - Approved Sick Leave: L402003 on 2026-10-22, L1009001 on 2026-10-17;
 *  - public holidays "Hindu Durga puja" on 2026-10-20 and 2026-10-21 (company-wide).
 *
 * Registered in the main DatabaseSeeder AND called by AMZDemoSeeder, so either entry point lays the same
 * calendar. Real people are never rewritten (firstOrNew), and every date-keyed write goes through
 * whereDate because date-cast columns store 'Y-m-d 00:00:00' — a naive updateOrCreate keyed on a raw
 * 'Y-m-d' string never matches its own row on a second run.
 */
class AMZOctober2026CalendarSeeder extends Seeder
{
    private const SHIFT_NAME = 'Evening 03PM-11PM';

    public function run(): void
    {
        $department = Department::query()->orderBy('id')->first();

        // People: create-if-absent with demo attributes; a live record is left completely untouched.
        foreach ([
            ['employee_id' => 'L402003', 'first_name' => 'Demo', 'last_name' => 'Employee', 'full_name' => 'Demo Employee (L402003)'],
            ['employee_id' => 'L1009001', 'first_name' => 'Tanbhir', 'last_name' => 'Hossain', 'full_name' => 'Tanbhir Hossain'],
        ] as $person) {
            $employee = Employee::query()->firstOrNew(['employee_id' => $person['employee_id']]);
            if ($employee->exists) {
                $this->command?->info("{$person['employee_id']} already exists — attributes untouched");
            } else {
                $employee->fill([
                    'first_name' => $person['first_name'],
                    'last_name' => $person['last_name'],
                    'full_name' => $person['full_name'],
                    'department_id' => $department?->id,
                    'employment_type' => 'Permanent',
                    'status' => 'Active',
                    'joining_date' => '2024-01-15',
                    'is_active' => true,
                ])->save();
                $this->command?->info("Created {$person['employee_id']} (did not exist before)");
            }
            // Policies are attendance-engine prerequisites; a real policy is HRM's decision, not a demo's.
            if (! EmployeePolicy::query()->where('employee_id', $person['employee_id'])->exists()) {
                EmployeePolicy::query()->create([
                    'employee_id' => $person['employee_id'],
                    'grace_time_in' => 10,
                    'grace_time_out' => 10,
                    'shift_type' => 'Fixed',
                    'is_punishable' => false,
                ]);
            }
        }

        // Shift: the canonical 15:00–23:00 Evening shift (what RosterShiftSeeder seeds). Created here too
        // so this seeder also runs standalone, before the main chain.
        $shift = RosterShift::query()->where('shift_name', self::SHIFT_NAME)->first()
            ?: RosterShift::query()->create([
                'shift_name' => self::SHIFT_NAME,
                'start_time' => '15:00:00',
                'end_time' => '23:00:00',
                'punch_in_window_start' => '14:00:00',
                'punch_in_window_end' => '16:00:00',
                'crosses_midnight' => false,
                'max_duration_hours' => 24,
            ]);

        $fridays = 0;
        for ($day = Carbon::parse('2026-10-01'); $day->lte('2026-10-31'); $day->addDay()) {
            $isFriday = $day->isFriday();
            $fridays += $isFriday ? 1 : 0;
            $date = $day->toDateString();
            $duty = RosterDuty::query()->where('employee_id', 'L402003')->whereDate('roster_date', $date)->first()
                ?: new RosterDuty(['employee_id' => 'L402003', 'roster_date' => $date]);
            $duty->fill([
                'shift_id' => $isFriday ? null : $shift->id,
                'scheduled_start_time' => $isFriday ? null : '15:00:00',
                'scheduled_end_time' => $isFriday ? null : '23:00:00',
                'status' => $isFriday ? 'Off' : 'Scheduled',
                'remarks' => 'Seeded by AMZOctober2026CalendarSeeder — 15:00–23:00 duty, Friday off',
            ])->save();
        }
        EmployeeFallbackOffDay::query()->updateOrCreate(['employee_id' => 'L402003'], ['day_of_week' => Carbon::FRIDAY]);
        $this->command?->info("Roster duty (L402003): 2026-10-01..31 on {$shift->shift_name} ({$shift->start_time}–{$shift->end_time}), {$fridays} Fridays marked Off");

        $sick = LeaveType::query()->where('name', 'Sick Leave')->first()
            ?: LeaveType::query()->create(['name' => 'Sick Leave', 'code' => 'SL', 'days_per_year' => 16, 'is_paid' => true, 'color' => '#ef4444']);
        foreach ([['L402003', '2026-10-22'], ['L1009001', '2026-10-17']] as [$employeeId, $date]) {
            $request = LeaveRequest::query()
                ->where('employee_id', $employeeId)->where('leave_type_id', $sick->id)
                ->whereDate('from_date', $date)->whereDate('to_date', $date)
                ->first() ?: new LeaveRequest(['employee_id' => $employeeId, 'leave_type_id' => $sick->id]);
            $request->fill([
                'from_date' => $date,
                'to_date' => $date,
                'days' => 1,
                'is_half_day' => false,
                'status' => 'Approved',
                'reason' => 'Seeded demo: approved sick leave',
                'approved_at' => Carbon::parse($date)->subDay()->startOfDay(),
            ])->save();
        }
        $this->command?->info('Approved sick leave: L402003 2026-10-22, L1009001 2026-10-17');

        foreach (['2026-10-20', '2026-10-21'] as $holidayDate) {
            $holiday = Holiday::query()->where('name', 'Hindu Durga puja')->whereDate('holiday_date', $holidayDate)->whereNull('department_id')->first()
                ?: new Holiday(['name' => 'Hindu Durga puja', 'holiday_date' => $holidayDate]);
            $holiday->fill(['holiday_type' => 'Public Holiday', 'notes' => 'Seeded by AMZOctober2026CalendarSeeder — company-wide'])->save();
        }
        $this->command?->info('Holiday calendar: Hindu Durga puja (Public Holiday) on 2026-10-20 and 2026-10-21');

        // Deliberately NO reconcile() here: it is company-wide (a demo seeder backfilling tens of thousands
        // of dailies into a live database is a DBA incident, not a feature). Materialise dailies through the
        // normal channel:  php artisan hrm:pipeline --from=2026-10-01 --to=2026-10-31
        // or Attendance → Fix pairing → apply, which now runs the same Stage 3 (recalc + reconcile).
    }
}
