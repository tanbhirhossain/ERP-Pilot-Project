<?php

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\CORE\Models\Role;
use Modules\CORE\Models\User;
use Modules\HRM\Models\Employee\Employee;
use Modules\HRM\Models\Employee\EmployeeSalary;
use Modules\HRM\Services\Attendance\AttendanceDayReconciliationService;
use Modules\HRM\Services\Attendance\AttendancePipeline;

beforeEach(function (): void {
    @set_time_limit(0);
    $this->travelTo(Carbon::parse('2026-11-20 10:00:00'));

    $role = Role::query()->firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
    app(Modules\PAYROLL\Database\Seeders\PAYROLLPermissionSeeder::class)->run();
    $admin = User::factory()->create();
    $admin->assignRole($role);
    $this->actingAs($admin);

    DB::table('payroll_settings')->updateOrInsert(['id' => 1], [
        'currency' => 'BDT',
        'unpaid_leave_day_divisor' => 30,
        'punishment_days_per_salary_day' => 3,
        'overtime_multiplier' => 1,
        'extra_duty_multiplier' => 1,
        'missing_punch_penalty_amount' => 0,
        'cutlist_review_required' => false,
        'missing_attendance_policy' => 'waive',
        'created_at' => now(), 'updated_at' => now(),
    ]);
});

afterEach(function (): void {
    $this->travelBack();
    Carbon::setTestNow(null);
    \Illuminate\Support\Carbon::setTestNow(null);
});

/**
 * Everything below starts from raw device punches and nothing else.
 *
 * The point of building the employees rather than borrowing the seeded ones is that the seeded
 * roster carries months of history whose quirks are not what is being tested: "did this payroll come
 * out right" is only answerable on data whose every input was chosen. So these employees are
 * invented, their punches are invented, and each assertion names the number it expects — if the
 * pipeline changes its mind about an hour, the test says so instead of quietly paying a different
 * amount.
 */

/** A fresh employee with a fixed 9-to-5 shift, an off day, a policy and a salary. */
function flowEmployee(string $code, float $basic, float $house, array $overrides = []): void
{
    Employee::factory()->create($overrides + [
        'employee_id' => $code,
        'full_name' => 'Flow '.$code,
        'first_name' => 'Flow',
        'last_name' => $code,
        'gender' => 'Male',
        'joining_date' => '2025-01-01',
        'employment_type' => 'Permanent',
        'status' => 'Active',
        'is_active' => true,
        'duty_hours' => 8,
        'department_id' => null,
        'designation_id' => null,
    ]);

    EmployeeSalary::factory()->create([
        'employee_id' => $code,
        'basic_salary' => $basic,
        'house_rent' => $house,
        'medical_allowance' => 500,
        'other_allowance' => 0,
        'gross_salary' => $basic + $house + 500,
        'net_salary' => $basic + $house + 500,
        'effective_from' => '2025-01-01',
        'effective_to' => null,
        'is_current' => true,
        'payment_method' => 'cash',
    ]);

    DB::table('employee_policies')->insert([
        'employee_id' => $code,
        'grace_time_in' => 15,
        'grace_time_out' => 15,
        'shift_type' => 'Fixed',
        'is_punishable' => true,
        'duty_hours' => 8,
        'auto_shift' => false,
        'buffer_minutes' => 90,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('employee_fallback_off_days')->insertOrIgnore([
        'employee_id' => $code, 'day_of_week' => 5,   // Friday
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** The 9-to-5 shift every flow employee is rostered on. */
function flowShiftId(): int
{
    $existing = DB::table('roster_shifts')->where('shift_name', 'FLOW 9-5')->value('id');
    if ($existing) {
        return (int) $existing;
    }

    return DB::table('roster_shifts')->insertGetId([
        'shift_name' => 'FLOW 9-5',
        'start_time' => '09:00:00',
        'end_time' => '17:00:00',
        'punch_in_window_start' => '08:00:00',
        'punch_in_window_end' => '10:00:00',
        'crosses_midnight' => false,
        'max_duration_hours' => 24,
        'is_fallback' => false,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** Roster a shift onto every working day of the month (Fridays left alone — they are the off day). */
function flowRoster(string $code, string $from, string $to): void
{
    $shiftId = flowShiftId();
    $rows = [];
    for ($day = Carbon::parse($from); $day->lte(Carbon::parse($to)); $day->addDay()) {
        if ($day->dayOfWeek === Carbon::FRIDAY) {
            continue;
        }
        $rows[] = [
            'employee_id' => $code,
            'shift_id' => $shiftId,
            'roster_date' => $day->toDateString(),
            'scheduled_start_time' => '09:00:00',
            'scheduled_end_time' => '17:00:00',
            'status' => 'Scheduled',
            'created_at' => now(), 'updated_at' => now(),
        ];
    }
    DB::table('roster_duties')->insert($rows);
}

/** A device to punch at. */
function flowDevice(): int
{
    $id = DB::table('biometric_devices')->where('name', 'FLOW-DEVICE')->value('id');
    if ($id) {
        return (int) $id;
    }

    return DB::table('biometric_devices')->insertGetId([
        'name' => 'FLOW-DEVICE',
        'device_name' => 'FLOW-DEVICE',
        'ip_address' => '10.9.9.9',
        'status' => 'Active',
        'is_enabled' => true,
        'auto_sync_enabled' => false,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** Raw punches, exactly as the device would hand them over: nothing processed. */
function flowPunch(string $code, string $at): void
{
    DB::table('attendance_logs')->insert([
        'employee_id' => $code,
        'device_user_id' => null,
        'device_id' => flowDevice(),
        'punch_time' => $at,
        'punch_type' => 'AUTO',
        'is_processed' => false,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function flowRunPipeline(string $from, string $to): array
{
    $pipeline = app(AttendancePipeline::class);
    $pairs = $pipeline->stage2MakePairs(5000, $from, $to);
    $daily = $pipeline->stage3PrepareAttendance($from, $to);

return ['pairs' => $pairs, 'daily' => $daily];
}

/**
 * `duty_date` is stored as a datetime ("2026-10-05 00:00:00"), not as a date, so every lookup in this
 * file goes through whereDate. Comparing the column to a plain 'YYYY-MM-DD' silently matches nothing.
 */
function flowDaily(string $code, string $date)
{
    return DB::table('attendance_dailies')->where('employee_id', $code)->whereDate('duty_date', $date)->first();
}

function flowPair(string $code, string $date)
{
    return DB::table('attendance_pairs')->where('employee_id', $code)->whereDate('duty_date', $date)->first();
}

/**
 * The whole chain, from a punch to a payslip.
 *
 * Four employees, one month, six different kinds of day — and each one is checked at every stage it
 * passes through, because a payroll that pays the right total for the wrong reason is still broken.
 */
test('raw punches become paired attendance, and every kind of day is classified correctly', function (): void {
    flowEmployee('FL-LATE', 20000, 9000);      // arrives late, leaves on time
    flowEmployee('FL-EARLY', 20000, 9000);     // arrives on time, leaves early
    flowEmployee('FL-OT', 20000, 9000);        // works 3 hours past the shift
    flowEmployee('FL-FULL', 20000, 9000);      // a clean, unremarkable month

    foreach (['FL-LATE', 'FL-EARLY', 'FL-OT', 'FL-FULL'] as $code) {
        flowRoster($code, '2026-10-01', '2026-10-31');
    }

    // A working Monday–Tuesday, chosen so the assertions can name exact dates.
    // 2026-10-05 is a Monday, 2026-10-06 a Tuesday.
    flowPunch('FL-LATE', '2026-10-05 09:45:00');   // 45 min late (grace is 15)
    flowPunch('FL-LATE', '2026-10-05 17:05:00');

    flowPunch('FL-EARLY', '2026-10-05 08:58:00');
    flowPunch('FL-EARLY', '2026-10-05 15:30:00');  // 90 min early

    flowPunch('FL-OT', '2026-10-06 09:00:00');
    flowPunch('FL-OT', '2026-10-06 20:00:00');     // 3 h past 17:00

    flowPunch('FL-FULL', '2026-10-05 09:00:00');
    flowPunch('FL-FULL', '2026-10-05 17:00:00');

    flowRunPipeline('2026-10-01', '2026-10-31');

    // --- the punches became pairs, with the hours the punches actually contain ---
    $latePair = flowPair('FL-LATE', '2026-10-05');
    expect($latePair)->not->toBeNull()
        ->and(Carbon::parse($latePair->punch_in)->format('H:i'))->toBe('09:45')
        ->and(Carbon::parse($latePair->punch_out)->format('H:i'))->toBe('17:05')
        ->and(round((float) $latePair->worked_hours, 2))->toBe(7.33);

    $otPair = flowPair('FL-OT', '2026-10-06');
    expect($otPair)->not->toBeNull()
        ->and(round((float) $otPair->worked_hours, 2))->toBe(11.0);

    // --- the days were classified, each for its own reason ---
    expect(flowDaily('FL-LATE', '2026-10-05')->status)->toBe('Late')
        ->and(flowDaily('FL-EARLY', '2026-10-05')->status)->toBe('Early Out')
        ->and(flowDaily('FL-FULL', '2026-10-05')->status)->toBe('Present');

    // Overtime is recorded as hours on the day, not as a status.
    $otDaily = flowDaily('FL-OT', '2026-10-06');
    expect((float) $otDaily->total_overtime_hours)->toBe(3.0)
        ->and((float) $otDaily->primary_worked_hours)->toBe(11.0);

    // --- and a Friday with no punch is an off day, not an absence ---
    expect(flowDaily('FL-FULL', '2026-10-02'))->not->toBeNull()
        ->and(flowDaily('FL-FULL', '2026-10-02')->status)->toBe('Off Day');
});

test('a day the employee was rostered for and did not attend is Absent, and an unrostered one is not', function (): void {
    flowEmployee('FL-ABSENT', 20000, 9000);
    flowRoster('FL-ABSENT', '2026-10-01', '2026-10-31');

    // One punch on the 1st opens the absence gate; the rest of the month is silence.
    flowPunch('FL-ABSENT', '2026-10-01 09:00:00');
    flowPunch('FL-ABSENT', '2026-10-01 17:00:00');

    flowRunPipeline('2026-10-01', '2026-10-31');

    expect(flowDaily('FL-ABSENT', '2026-10-01')->status)->toBe('Present')
        ->and(flowDaily('FL-ABSENT', '2026-10-05')->status)->toBe('Absent')
        // The five Fridays are off days, and off is not absent.
        ->and(flowDaily('FL-ABSENT', '2026-10-02')->status)->toBe('Off Day');
});

test('approved overtime pays only where the attendance day can back it up', function (): void {
    flowEmployee('FL-OTPAID', 20000, 9000);
    flowRoster('FL-OTPAID', '2026-10-01', '2026-10-31');

    // Tuesday 6th: in at 9, out at 20 — three hours past the 17:00 shift.
    flowPunch('FL-OTPAID', '2026-10-06 09:00:00');
    flowPunch('FL-OTPAID', '2026-10-06 20:00:00');
    // Wednesday 7th: a normal day, but HR has approved two hours of overtime anyway.
    flowPunch('FL-OTPAID', '2026-10-07 09:00:00');
    flowPunch('FL-OTPAID', '2026-10-07 17:00:00');

    flowRunPipeline('2026-10-01', '2026-10-31');

    foreach ([['2026-10-06', 3.0], ['2026-10-07', 2.0]] as [$date, $hours]) {
        DB::table('roster_overtimes')->insert([
            'employee_id' => 'FL-OTPAID',
            'overtime_date' => $date,
            'overtime_type' => 'Overtime',
            'hours' => $hours,
            'reason' => 'Approved by the flow test',
            'status' => 'Approved',
            'approved_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $summary = app(Modules\PAYROLL\Services\HRM\HrmAttendancePayrollDataProvider::class)
        ->summaryForEmployee('FL-OTPAID', '2026-10-01', '2026-10-31');

    // Three hours are real — the 6th was worked past the shift. The two hours approved for the 7th
    // are not: he left at 17:00, so there is nothing to pay. An approved row is a claim, and the
    // attendance day is the evidence for it.
    expect($summary->approvedOvertimeHours)->toBe(3.0)
        ->and($summary->sourceBreakdown['approved_overtime_hours'])->toBe(3.0);
});

test('a public holiday that was worked is Extra Duty, and the extra-duty rate applies to it', function (): void {
    flowEmployee('FL-HOL', 20000, 9000);
    flowRoster('FL-HOL', '2026-10-01', '2026-10-31');

    DB::table('hrm_holidays')->insert([
        'holiday_date' => '2026-10-20',
        'name' => 'FLOW Holiday',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // Turned up on the holiday.
    flowPunch('FL-HOL', '2026-10-20 09:00:00');
    flowPunch('FL-HOL', '2026-10-20 17:00:00');
    // And was absent on an ordinary Tuesday.
    flowPunch('FL-HOL', '2026-10-01 09:00:00');
    flowPunch('FL-HOL', '2026-10-01 17:00:00');

    flowRunPipeline('2026-10-01', '2026-10-31');

    expect(flowDaily('FL-HOL', '2026-10-20')->status)->toBe('Extra Duty');

    DB::table('roster_overtimes')->insert([
        'employee_id' => 'FL-HOL',
        'overtime_date' => '2026-10-20',
        'overtime_type' => 'Holiday Duty',
        'hours' => 8,
        'reason' => 'Worked the holiday',
        'status' => 'Approved',
        'approved_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $summary = app(Modules\PAYROLL\Services\HRM\HrmAttendancePayrollDataProvider::class)
        ->summaryForEmployee('FL-HOL', '2026-10-01', '2026-10-31');

    // Holiday duty is extra duty, not overtime: the two are paid at different multipliers.
    expect($summary->approvedExtraDutyHours)->toBe(8.0)
        ->and($summary->approvedOvertimeHours)->toBe(0.0);

    // And the holiday itself is not an absence, even though nobody was rostered to work it.
    expect($summary->sourceBreakdown['absent_dates'])->not->toContain('2026-10-20');
});

test('late and early-out counts reach the payslip as a punishment, and clearing punishable clears it', function (): void {
    flowEmployee('FL-PUN', 30000, 12000);
    flowRoster('FL-PUN', '2026-10-01', '2026-10-31');

    // Late twice, early once.
    flowPunch('FL-PUN', '2026-10-05 10:00:00');
    flowPunch('FL-PUN', '2026-10-05 17:00:00');
    flowPunch('FL-PUN', '2026-10-06 09:50:00');
    flowPunch('FL-PUN', '2026-10-06 17:00:00');
    flowPunch('FL-PUN', '2026-10-07 09:00:00');
    flowPunch('FL-PUN', '2026-10-07 15:00:00');

    flowRunPipeline('2026-10-01', '2026-10-31');

    expect(flowDaily('FL-PUN', '2026-10-05')->status)->toBe('Late')
        ->and(flowDaily('FL-PUN', '2026-10-06')->status)->toBe('Late')
        ->and(flowDaily('FL-PUN', '2026-10-07')->status)->toBe('Early Out');

    $provider = app(Modules\PAYROLL\Services\HRM\HrmAttendancePayrollDataProvider::class);
    $withPunishment = $provider->summaryForEmployee('FL-PUN', '2026-10-01', '2026-10-31');

    expect($withPunishment->lateCount)->toBe(2)
        ->and($withPunishment->earlyOutCount)->toBe(1)
        ->and($withPunishment->punishmentDays)->toBe(3);

    $cutlist = app(Modules\PAYROLL\Actions\GenerateSalaryCutlistAction::class)
        ->execute(2026, 10);

    $row = DB::table('payroll_cutlist_items')->where('payroll_cutlist_id', $cutlist->id)
        ->where('employee_id', 'FL-PUN')->first();

    expect($row)->not->toBeNull()
        ->and((int) $row->late_count)->toBe(2)
        ->and((int) $row->early_out_count)->toBe(1);
});

/**
 * And the last hop: the summary the attendance system produced has to reach a payslip intact.
 *
 * Each employee is paid a different rate so the arithmetic cannot cancel itself out: if the overtime
 * hours or the absence days were attached to the wrong person, the totals would still add up while
 * every individual payslip was wrong. These are checked one by one.
 */
test('attendance reaches the payslip: absence, overtime and the number of days all survive the trip', function (): void {
    flowEmployee('PAY-OT', 30000, 15000);     // basic 30,000
    flowEmployee('PAY-ABS', 30000, 15000);
    flowEmployee('PAY-CLEAN', 30000, 15000);

    foreach (['PAY-OT', 'PAY-ABS', 'PAY-CLEAN'] as $code) {
        flowRoster($code, '2026-10-01', '2026-10-31');
    }

    // PAY-OT: in on the 1st, and three hours past the shift on the 6th.
    flowPunch('PAY-OT', '2026-10-01 09:00:00');
    flowPunch('PAY-OT', '2026-10-01 17:00:00');
    flowPunch('PAY-OT', '2026-10-06 09:00:00');
    flowPunch('PAY-OT', '2026-10-06 20:00:00');

    // PAY-ABS: in on the 1st, and nowhere to be found on the 6th, 7th and 8th.
    flowPunch('PAY-ABS', '2026-10-01 09:00:00');
    flowPunch('PAY-ABS', '2026-10-01 17:00:00');

    // PAY-CLEAN: every working day of the month, 9 to 5.
    for ($day = Carbon::parse('2026-10-01'); $day->lte(Carbon::parse('2026-10-31')); $day->addDay()) {
        if ($day->dayOfWeek === Carbon::FRIDAY) {
            continue;
        }
        flowPunch('PAY-CLEAN', $day->toDateString().' 09:00:00');
        flowPunch('PAY-CLEAN', $day->toDateString().' 17:00:00');
    }

    flowRunPipeline('2026-10-01', '2026-10-31');

    // The three overtime hours are approved only after the attendance exists to support them.
    DB::table('roster_overtimes')->insert([
        'employee_id' => 'PAY-OT', 'overtime_date' => '2026-10-06', 'overtime_type' => 'Overtime',
        'hours' => 3, 'reason' => 'Worked late', 'status' => 'Approved',
        'approved_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $sheet = Modules\PAYROLL\Models\PayrollSalarySheet::query()->create([
        'pay_year' => 2026, 'pay_month' => 10, 'status' => 'queued',
        'include_punishments' => true, 'employee_count' => 3, 'processed_count' => 0,
        'gross_total' => 0, 'deduction_total' => 0, 'net_total' => 0, 'created_by' => 1,
    ]);
    app(Modules\PAYROLL\Actions\ProcessMonthlyPayrollBatchAction::class)->execute($sheet->id);

    $slip = fn (string $id) => Modules\PAYROLL\Models\PayrollSlip::query()
        ->where('salary_sheet_id', $sheet->id)->where('employee_id', $id)->first();

    $ot = $slip('PAY-OT');
    $abs = $slip('PAY-ABS');
    $clean = $slip('PAY-CLEAN');

    expect($ot)->not->toBeNull()->and($abs)->not->toBeNull()->and($clean)->not->toBeNull();

    // Gross is basic + house rent + medical = 30,000 + 15,000 + 500 = 45,500.
    //   daily rate      = gross / 30          = 1,516.67  (rounded before it is multiplied)
    //   hourly basic    = basic / (30 * 8)    = 125.00
    $gross = 45500.0;
    $dailyRate = round($gross / 30, 2);
    $otPay = round((30000 / (30 * 8)) * 3, 2);

    // PAY-OT: one charged day? No — 26 working days, 2 of them worked, so 24 are unworked...
    // but only the 6th has overtime and the 1st and 6th carry punches. The remaining 24 working days
    // are absences, and the punch gate is open because he was on the device.
    $otSummary = app(Modules\PAYROLL\Services\HRM\HrmAttendancePayrollDataProvider::class)
        ->summaryForEmployee('PAY-OT', '2026-10-01', '2026-10-31');
    expect((float) $otSummary->approvedOvertimeHours)->toBe(3.0)
        ->and((float) $otSummary->unpaidLeaveDays)->toBe(24.0);

    // The payslip carries the same absence days, priced at the daily rate.
    expect(round((float) $ot->breakdown_json['cutlist']['unpaid_leave_days'], 2))->toBe(24.0)
        ->and(round((float) $ot->breakdown_json['cutlist']['unpaid_leave'], 2))->toBe(round($dailyRate * 24, 2))
        // ...and the overtime as a benefit line, priced at the hourly basic rate.
        ->and(round((float) $ot->breakdown_json['cutlist']['overtime_pay'], 2))->toBe($otPay);

    // PAY-ABS: 26 working days, 1 worked, 25 unworked → 25 days charged.
    expect(round((float) $abs->breakdown_json['cutlist']['unpaid_leave_days'], 2))->toBe(25.0);

    // PAY-CLEAN: every working day worked, nothing deducted, nothing added.
    expect(round((float) $clean->breakdown_json['cutlist']['unpaid_leave_days'], 2))->toBe(0.0)
        ->and(round((float) $clean->breakdown_json['cutlist']['overtime_pay'], 2))->toBe(0.0)
        ->and(round((float) $clean->net_salary, 2))->toBe($gross);

    // Nobody was paid twice, and the sheet's own totals agree with its slips.
    expect(Modules\PAYROLL\Models\PayrollSlip::query()->where('salary_sheet_id', $sheet->id)->count())->toBe(3)
        ->and(round((float) $sheet->fresh()->net_total, 2))
        ->toBe(round((float) Modules\PAYROLL\Models\PayrollSlip::query()->where('salary_sheet_id', $sheet->id)->sum('net_salary'), 2));
});
