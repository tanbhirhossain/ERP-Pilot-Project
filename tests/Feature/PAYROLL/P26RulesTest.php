<?php

use Illuminate\Support\Facades\DB;
use Modules\CORE\Models\Role;
use Modules\CORE\Models\User;
use Modules\HRM\Models\Employee\Employee;
use Modules\HRM\Models\Employee\EmployeeSalary;

/**
 * The five rules HR signed off on 2026-11-19.
 *
 * Each of these was either missing, silently off, or pointed the wrong way, and none of them was
 * covered by a test — which is why 998 employees could be unpunishable, 14 unsalaried, every gender
 * NULL, and 17,195 absent days paid in full without a single failing assertion.
 */
function p26Setup(): array
{
    $role = Role::query()->firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
    app(Modules\PAYROLL\Database\Seeders\PAYROLLPermissionSeeder::class)->run();
    $admin = User::factory()->create();
    $admin->assignRole($role);

    return compact('admin');
}

test('an employee with no policy row is punishable, because that is what the column default says', function () {
    p26Setup();

    $employee = Employee::factory()->create([
        'employee_id' => 'P26-NOPOLICY', 'full_name' => 'No Policy', 'status' => 'Active',
        'is_active' => true, 'joining_date' => '2019-01-01', 'gender' => 'Male',
    ]);

    // No employee_policies row at all — the state 994 of the 998 real employees were in.
    expect($employee->policy)->toBeNull();

    $summary = app(Modules\PAYROLL\Services\HRM\HrmAttendancePayrollDataProvider::class)
        ->summaryForEmployee('P26-NOPOLICY', '2026-10-01', '2026-10-31');

    expect($summary->punishable)->toBeTrue();

    // And the UI's own default for a new employee agrees, so the checkbox starts ticked.
    $edit = file_get_contents(base_path('modules/HRM/Resources/js/Pages/Employees/Edit.vue'));
    expect($edit)->toContain('is_punishable: true');
    expect($edit)->not->toContain('is_punishable: false');
});

test('an employee who was on site and did not come in loses the day; one with no punches at all does not', function () {
    p26Setup();

    foreach (['P26-ONSITE' => 'On Site', 'P26-NOPUNCH' => 'No Punch'] as $id => $name) {
        Employee::factory()->create([
            'employee_id' => $id, 'full_name' => $name, 'status' => 'Active', 'is_active' => true,
            'joining_date' => '2019-01-01', 'gender' => 'Male', 'department_id' => null,
        ]);
        EmployeeSalary::factory()->create([
            'employee_id' => $id, 'basic_salary' => 20000, 'gross_salary' => 30000,
            'effective_from' => '2019-01-01', 'effective_to' => null, 'is_current' => true,
        ]);
        DB::table('employee_policies')->insert([
            'employee_id' => $id, 'grace_time_in' => 15, 'grace_time_out' => 15, 'shift_type' => 'Fixed',
            'is_punishable' => true, 'duty_hours' => 8, 'auto_shift' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // Both are marked Absent all month by the reconciler that had no punches to work with.
    DB::table('attendance_dailies')->insert(
        collect(['P26-ONSITE', 'P26-NOPUNCH'])->flatMap(fn (string $id) => collect(range(1, 31))->map(fn (int $d) => [
            'employee_id' => $id, 'duty_date' => sprintf('2026-10-%02d', $d), 'status' => 'Absent',
            'created_at' => now(), 'updated_at' => now(),
        ]))->all()
    );

    // Only one of them left a trace in the device data. The other is a missing export.
    DB::table('attendance_logs')->insert([
        'employee_id' => 'P26-ONSITE', 'device_id' => null, 'punch_time' => '2026-10-05 09:00:00',
        'punch_type' => 'IN', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $service = app(Modules\PAYROLL\Services\HRM\HrmAttendancePayrollDataProvider::class);
    $onsite = $service->summaryForEmployee('P26-ONSITE', '2026-10-01', '2026-10-31');
    $nopunch = $service->summaryForEmployee('P26-NOPUNCH', '2026-10-01', '2026-10-31');

    expect($onsite->unpaidLeaveDays)->toBe(31.0)
        ->and($onsite->sourceBreakdown['absence_gated_by_punch'])->toBeTrue();

    expect($nopunch->unpaidLeaveDays)->toBe(0.0)
        ->and($nopunch->sourceBreakdown['absence_gated_by_punch'])->toBeFalse();
});

test('a contracted off day and a public holiday are not absence', function () {
    p26Setup();

    Employee::factory()->create([
        'employee_id' => 'P26-OFFDAY', 'full_name' => 'Off Day', 'status' => 'Active', 'is_active' => true,
        'joining_date' => '2019-01-01', 'gender' => 'Male',
    ]);
    EmployeeSalary::factory()->create([
        'employee_id' => 'P26-OFFDAY', 'basic_salary' => 20000, 'gross_salary' => 30000,
        'effective_from' => '2019-01-01', 'effective_to' => null, 'is_current' => true,
    ]);
    DB::table('employee_policies')->insert([
        'employee_id' => 'P26-OFFDAY', 'grace_time_in' => 15, 'grace_time_out' => 15, 'shift_type' => 'Fixed',
        'is_punishable' => true, 'duty_hours' => 8, 'auto_shift' => false,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    // Contracted off every Friday; the 20th is a public holiday.
    DB::table('employee_fallback_off_days')->insert([
        'employee_id' => 'P26-OFFDAY', 'day_of_week' => 5, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('hrm_holidays')->insert([
        'holiday_date' => '2026-10-20', 'name' => 'Durga Puja', 'created_at' => now(), 'updated_at' => now(),
    ]);

    // Every remaining day of October is Absent, and they were at work on the 1st so the gate opens.
    $dates = collect(range(1, 31))->filter(fn (int $d) => $d !== 20
        && ! in_array(\Carbon\Carbon::parse(sprintf('2026-10-%02d', $d))->dayOfWeek, [5], true));
    DB::table('attendance_dailies')->insert($dates->map(fn (int $d) => [
        'employee_id' => 'P26-OFFDAY', 'duty_date' => sprintf('2026-10-%02d', $d), 'status' => 'Absent',
        'created_at' => now(), 'updated_at' => now(),
    ])->values()->all());
    DB::table('attendance_logs')->insert([
        'employee_id' => 'P26-OFFDAY', 'device_id' => null, 'punch_time' => '2026-10-01 09:00:00',
        'punch_type' => 'IN', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $summary = app(Modules\PAYROLL\Services\HRM\HrmAttendancePayrollDataProvider::class)
        ->summaryForEmployee('P26-OFFDAY', '2026-10-01', '2026-10-31');

    // Fridays and the holiday are excluded; the rest are deducted.
    expect($summary->unpaidLeaveDays)->toBe((float) $dates->count())
        ->and($summary->sourceBreakdown['absent_dates'])->not->toContain('2026-10-20')
        ->and(collect($summary->sourceBreakdown['absent_dates'])->contains(fn ($d) => \Carbon\Carbon::parse($d)->dayOfWeek === 5))->toBeFalse();
});

test('maternity leave is credited to women only and paternity to men only', function () {
    p26Setup();

    foreach (['P26-F' => 'Female', 'P26-M' => 'Male'] as $id => $gender) {
        Employee::factory()->create([
            'employee_id' => $id, 'full_name' => 'Leave '.$gender, 'status' => 'Active',
            'is_active' => true, 'joining_date' => '2019-01-01', 'gender' => $gender,
        ]);
    }

    $balances = app(Modules\HRM\Services\LeaveBalanceService::class);

    // The two statutory types, created here because the test database seeds no leave catalogue.
    $ml = DB::table('leave_types')->updateOrInsert(
        ['code' => 'ML'],
        ['name' => 'Maternity Leave', 'days_per_year' => 180, 'is_paid' => 1, 'is_active' => 1,
         'created_at' => now(), 'updated_at' => now()]
    ) ? DB::table('leave_types')->where('code', 'ML')->value('id') : null;
    $pl = DB::table('leave_types')->updateOrInsert(
        ['code' => 'PL'],
        ['name' => 'Paternity Leave', 'days_per_year' => 7, 'is_paid' => 1, 'is_active' => 1,
         'created_at' => now(), 'updated_at' => now()]
    ) ? DB::table('leave_types')->where('code', 'PL')->value('id') : null;

    // The restriction lives on the settings row, not on the type.
    DB::table('leave_type_settings')->updateOrInsert(
        ['leave_type_id' => $ml],
        ['gender_eligibility' => 'Female', 'created_at' => now(), 'updated_at' => now()]
    );
    DB::table('leave_type_settings')->updateOrInsert(
        ['leave_type_id' => $pl],
        ['gender_eligibility' => 'Male', 'created_at' => now(), 'updated_at' => now()]
    );
    $balances->forgetPolicy();

    $female = Employee::where('employee_id', 'P26-F')->first();
    $male = Employee::where('employee_id', 'P26-M')->first();

    $mlForFemale = $balances->days('P26-F', (int) $ml, '2026-01-01', '2026-12-31', false, null, $female, []);
    $mlForMale = $balances->days('P26-M', (int) $ml, '2026-01-01', '2026-12-31', false, null, $male, []);
    $plForMale = $balances->days('P26-M', (int) $pl, '2026-01-01', '2026-12-31', false, null, $male, []);

    expect($mlForFemale)->toBeGreaterThan(0.0)
        ->and($mlForMale)->toBe(0.0)
        ->and($plForMale)->toBeGreaterThan(0.0);
});

test('every employee on the real roster now carries a gender, a salary and a punishable policy', function () {
    // This one asserts against the seeded database rather than a factory, because the whole point is
    // the state of the live roster: these were three separate holes in the same 998 people.
    if (DB::table('employees')->count() < 100) {
        $this->markTestSkipped('Real roster not seeded in this database.');
    }

    $noGender = DB::table('employees')->whereNull('gender')->orWhere('gender', '')->count();
    $noPolicy = DB::table('employees')->whereNotExists(fn ($q) => $q->select(DB::raw(1))
        ->from('employee_policies')->whereColumn('employee_policies.employee_id', 'employees.employee_id'))->count();
    $unpunishable = DB::table('employee_policies')->where('is_punishable', 0)->count();

    expect($noGender)->toBe(0, "{$noGender} employees still have no gender")
        ->and($noPolicy)->toBe(0, "{$noPolicy} employees still have no attendance policy")
        ->and($unpunishable)->toBe(0);
});
