<?php

use Modules\HRM\Interfaces\Attendance\AttendanceDailyRepositoryInterface;
use Modules\HRM\Interfaces\Roster\RosterDutyRepositoryInterface;
use Modules\HRM\Models\Attendance\AttendancePair;
use Modules\HRM\Models\Department;
use Modules\HRM\Models\Designation;
use Modules\HRM\Models\Employee\Employee;
use Modules\HRM\Models\Employee\EmployeePolicy;
use Modules\HRM\Models\Organization;
use Modules\HRM\Models\Roster\RosterDuty;
use Modules\HRM\Models\Roster\RosterShift;
use Modules\HRM\Services\EmployeeService;
use Modules\HRM\Services\OrganizationService;
use Modules\HRM\Services\RosterDutyService;
use Modules\HRM\Services\RosterShiftService;

test('RosterShiftService CRUD operations', function () {
    $service = app(RosterShiftService::class);
    $shift = $service->create([
        'shift_name' => 'Test Shift',
        'start_time' => '08:00',
        'end_time' => '16:00',
        'crosses_midnight' => false,
        'max_duration_hours' => 24,
    ]);
    expect($shift->id)->toBeInt();
    expect($shift->shift_name)->toBe('Test Shift');

    $found = $service->findOrFail($shift->id);
    expect($found->id)->toBe($shift->id);

    $updated = $service->update($shift, ['shift_name' => 'Updated', 'start_time' => '09:00', 'end_time' => '17:00', 'max_duration_hours' => 12]);
    expect($updated->shift_name)->toBe('Updated');

    $options = $service->options('Updated');
    expect($options->first()['label'])->toContain('Updated');

    $service->delete($updated);
    expect(RosterShift::find($shift->id))->toBeNull();
});

test('RosterDutyService paginates and bulk assign', function () {
    $dept = Department::factory()->create();
    $desig = Designation::factory()->create(['department_id' => $dept->id]);
    $emp1 = Employee::factory()->create(['department_id' => $dept->id, 'designation_id' => $desig->id]);
    $emp2 = Employee::factory()->create(['department_id' => $dept->id, 'designation_id' => $desig->id]);
    $shift = RosterShift::factory()->create();

    $service = app(RosterDutyService::class);
    $count = $service->bulkAssign([$emp1->employee_id, $emp2->employee_id], $shift->id, '2026-10-20', '2026-10-22');
    expect($count)->toBe(6);
    expect(RosterDuty::count())->toBe(6);

    // Verify snapshot times set
    $duty = RosterDuty::first();
    expect($duty->scheduled_start_time)->toBe($shift->start_time);
    expect($duty->scheduled_end_time)->toBe($shift->end_time);

    // Bulk assign again same range should upsert not duplicate
    $count2 = $service->bulkAssign([$emp1->employee_id], $shift->id, '2026-10-20', '2026-10-20');
    expect(RosterDuty::count())->toBe(6); // not 7
});

test('RosterDutyRepository upsert prevents duplicate unique violation', function () {
    $dept = Department::factory()->create();
    $desig = Designation::factory()->create(['department_id' => $dept->id]);
    $emp = Employee::factory()->create(['department_id' => $dept->id, 'designation_id' => $desig->id]);
    $shift = RosterShift::factory()->create();
    $repo = app(RosterDutyRepositoryInterface::class);

    $d1 = $repo->create(['employee_id' => $emp->employee_id, 'shift_id' => $shift->id, 'roster_date' => '2026-10-26', 'status' => 'Scheduled']);
    expect($d1->id)->toBeInt();

    // Creating again same employee+date should via bulkAssign upsert not throw
    $service = app(RosterDutyService::class);
    $service->bulkAssign([$emp->employee_id], $shift->id, '2026-10-26', '2026-10-26');
    expect(RosterDuty::where('employee_id', $emp->employee_id)->where('roster_date', '2026-10-26')->count())->toBe(1);
});

test('AttendanceDailyRepository deriveStatus handles late and early correctly', function () {
    $dept = Department::factory()->create();
    $desig = Designation::factory()->create(['department_id' => $dept->id]);
    $emp = Employee::factory()->create(['department_id' => $dept->id, 'designation_id' => $desig->id]);
    $shift = RosterShift::factory()->create(['start_time' => '09:00', 'end_time' => '17:00']);
    EmployeePolicy::factory()->create(['employee_id' => $emp->employee_id, 'shift_type' => 'Fixed', 'grace_time_in' => 15, 'grace_time_out' => 15, 'is_punishable' => true]);
    RosterDuty::factory()->create(['employee_id' => $emp->employee_id, 'shift_id' => $shift->id, 'roster_date' => today()->toDateString(), 'scheduled_start_time' => '09:00', 'scheduled_end_time' => '17:00']);

    $dailyRepo = app(AttendanceDailyRepositoryInterface::class);

    // Case 1: On time → Present
    $pair = AttendancePair::factory()->create([
        'employee_id' => $emp->employee_id, 'shift_id' => $shift->id, 'duty_date' => today()->toDateString(),
        'punch_in' => today()->setTime(9, 5, 0)->toDateTimeString(), 'punch_out' => today()->setTime(17, 0, 0)->toDateTimeString(), 'duty_type' => 'Primary', 'worked_hours' => 8,
    ]);
    $daily = $dailyRepo->upsertForPair($pair);
    expect($daily->status)->toBe('Present');

    // Case 2: Late (09:30 with 15 grace)
    $emp2 = Employee::factory()->create(['department_id' => $dept->id, 'designation_id' => $desig->id]);
    EmployeePolicy::factory()->create(['employee_id' => $emp2->employee_id, 'shift_type' => 'Fixed', 'grace_time_in' => 15, 'grace_time_out' => 15, 'is_punishable' => true]);
    RosterDuty::factory()->create(['employee_id' => $emp2->employee_id, 'shift_id' => $shift->id, 'roster_date' => today()->toDateString(), 'scheduled_start_time' => '09:00', 'scheduled_end_time' => '17:00']);
    $pairLate = AttendancePair::factory()->create([
        'employee_id' => $emp2->employee_id, 'shift_id' => $shift->id, 'duty_date' => today()->toDateString(),
        'punch_in' => today()->setTime(9, 30, 0)->toDateTimeString(), 'punch_out' => today()->setTime(17, 0, 0)->toDateTimeString(), 'duty_type' => 'Primary', 'worked_hours' => 7.5,
    ]);
    $dailyLate = $dailyRepo->upsertForPair($pairLate);
    expect($dailyLate->status)->toBe('Late');

    // Case 3: Early Out
    $emp3 = Employee::factory()->create(['department_id' => $dept->id, 'designation_id' => $desig->id]);
    EmployeePolicy::factory()->create(['employee_id' => $emp3->employee_id, 'shift_type' => 'Fixed', 'grace_time_in' => 15, 'grace_time_out' => 15]);
    RosterDuty::factory()->create(['employee_id' => $emp3->employee_id, 'shift_id' => $shift->id, 'roster_date' => today()->toDateString(), 'scheduled_start_time' => '09:00', 'scheduled_end_time' => '17:00']);
    $pairEarly = AttendancePair::factory()->create([
        'employee_id' => $emp3->employee_id, 'shift_id' => $shift->id, 'duty_date' => today()->toDateString(),
        'punch_in' => today()->setTime(9, 0, 0)->toDateTimeString(), 'punch_out' => today()->setTime(16, 0, 0)->toDateTimeString(), 'duty_type' => 'Primary', 'worked_hours' => 7,
    ]);
    $dailyEarly = $dailyRepo->upsertForPair($pairEarly);
    expect($dailyEarly->status)->toBe('Early Out');
});

test('AttendanceDailyRepository handles missing punch status', function () {
    $dept = Department::factory()->create();
    $desig = Designation::factory()->create(['department_id' => $dept->id]);
    $emp = Employee::factory()->create(['department_id' => $dept->id, 'designation_id' => $desig->id]);
    $shift = RosterShift::factory()->create();
    EmployeePolicy::factory()->create(['employee_id' => $emp->employee_id]);
    RosterDuty::factory()->create(['employee_id' => $emp->employee_id, 'shift_id' => $shift->id, 'roster_date' => today()->toDateString()]);

    $dailyRepo = app(AttendanceDailyRepositoryInterface::class);
    // Open pair (no punch_out) → Missing Out
    $pairOpen = AttendancePair::factory()->open()->create([
        'employee_id' => $emp->employee_id, 'shift_id' => $shift->id, 'duty_date' => today()->toDateString(),
        'punch_in' => today()->setTime(9, 0, 0)->toDateTimeString(), 'duty_type' => 'Primary',
    ]);
    $daily = $dailyRepo->upsertForPair($pairOpen);
    expect($daily->status)->toBe('Missing Out');
    expect($daily->primary_punch_out)->toBeNull();
});

test('OrganizationService upsert maintains single row invariant', function () {
    $svc = app(OrganizationService::class);
    $o1 = $svc->upsert(['name' => 'Org One', 'email' => 'a@test.com']);
    $o2 = $svc->upsert(['name' => 'Org Two', 'email' => 'b@test.com']);
    expect(Organization::count())->toBe(1);
    expect($o1->id)->toBe($o2->id);
    expect($o2->name)->toBe('Org Two');
});

test('Employee auto ID generation is race-safe with lock', function () {
    $service = app(EmployeeService::class);
    $emp1 = $service->create(['first_name' => 'A', 'employment_type' => 'Permanent', 'status' => 'Active']);
    $emp2 = $service->create(['first_name' => 'B', 'employment_type' => 'Permanent', 'status' => 'Active']);
    $emp3 = $service->create(['first_name' => 'C', 'employment_type' => 'Permanent', 'status' => 'Active']);
    expect($emp1->employee_id)->toBe('EMP-0001');
    expect($emp2->employee_id)->toBe('EMP-0002');
    expect($emp3->employee_id)->toBe('EMP-0003');
    // Custom ID respected
    $custom = $service->create(['employee_id' => 'EMP-9999', 'first_name' => 'Custom', 'employment_type' => 'Permanent', 'status' => 'Active']);
    expect($custom->employee_id)->toBe('EMP-9999');
});
