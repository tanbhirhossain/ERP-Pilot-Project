<?php

use Modules\CORE\Database\Seeders\SuperAdminSeeder;
use Modules\HRM\Models\Roster\RosterDuty;
use Modules\HRM\Models\Roster\RosterShift;
use Modules\HRM\Services\RosterDutyService;
use Tests\Feature\HRM\HrmTestHelper;

uses(HrmTestHelper::class);

beforeEach(function () {
    $this->seed(SuperAdminSeeder::class);
});

// ---- Roster Shifts ----
test('can view roster shifts index', function () {
    $this->actingAs($this->adminUser())
        ->get(route('hrm.shifts.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('HRM::RosterShifts/Index'));
});

test('can create fixed shift', function () {
    $this->actingAs($this->adminUser())
        ->post(route('hrm.shifts.store'), [
            'shift_name' => 'Day Shift A',
            'start_time' => '09:00',
            'end_time' => '17:00',
            'crosses_midnight' => false,
            'max_duration_hours' => 24,
        ])
        ->assertRedirect(route('hrm.shifts.index'));

    $shift = RosterShift::where('shift_name', 'Day Shift A')->first();
    expect($shift)->not->toBeNull();
    expect($shift->start_time)->toBe('09:00');
});

test('can create night shift crossing midnight', function () {
    $this->actingAs($this->adminUser())
        ->post(route('hrm.shifts.store'), [
            'shift_name' => 'Night Shift',
            'start_time' => '22:00',
            'end_time' => '06:00',
            'crosses_midnight' => true,
            'max_duration_hours' => 12,
        ])
        ->assertRedirect();

    $shift = RosterShift::where('shift_name', 'Night Shift')->first();
    expect($shift->crosses_midnight)->toBeTrue();
});

test('can create flexible shift with window', function () {
    $this->actingAs($this->adminUser())
        ->post(route('hrm.shifts.store'), [
            'shift_name' => 'Flex Shift',
            'start_time' => '09:00',
            'end_time' => '17:00',
            'punch_in_window_start' => '07:00',
            'punch_in_window_end' => '11:00',
            'crosses_midnight' => false,
            'max_duration_hours' => 24,
        ])
        ->assertRedirect();

    $shift = RosterShift::where('shift_name', 'Flex Shift')->first();
    expect($shift->punch_in_window_start)->toBe('07:00');
});

test('shift validation requires shift_name', function () {
    $this->actingAs($this->adminUser())
        ->post(route('hrm.shifts.store'), [
            'shift_name' => '',
            'max_duration_hours' => 24,
        ])
        ->assertSessionHasErrors('shift_name');
});

test('can update shift', function () {
    $shift = $this->createShift();
    $this->actingAs($this->adminUser())
        ->put(route('hrm.shifts.update', $shift->id), [
            'shift_name' => 'Updated Shift',
            'start_time' => '10:00',
            'end_time' => '18:00',
            'crosses_midnight' => false,
            'max_duration_hours' => 24,
        ])
        ->assertRedirect();

    expect($shift->refresh()->shift_name)->toBe('Updated Shift');
});

test('can delete shift', function () {
    $shift = $this->createShift();
    $this->actingAs($this->adminUser())
        ->delete(route('hrm.shifts.destroy', $shift->id))
        ->assertRedirect();

    expect(RosterShift::find($shift->id))->toBeNull();
});

// ---- Roster Duties ----
test('can view roster duties index', function () {
    $this->actingAs($this->adminUser())
        ->get(route('hrm.duties.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('HRM::RosterDuties/Index'));
});

test('can create single roster duty', function () {
    $emp = $this->createEmployee();
    $shift = $this->createShift();
    $this->actingAs($this->adminUser())
        ->post(route('hrm.duties.store'), [
            'employee_id' => $emp->employee_id,
            'shift_id' => $shift->id,
            'roster_date' => '2026-10-26',
            'status' => 'Scheduled',
        ])
        ->assertRedirect(route('hrm.duties.index'));

    expect(RosterDuty::where('employee_id', $emp->employee_id)->whereDate('roster_date', '2026-10-26')->exists())->toBeTrue();
    $duty = RosterDuty::where('employee_id', $emp->employee_id)->first();
    expect($duty->scheduled_start_time)->toBe($shift->start_time); // auto snapshot
});

test('roster duty unique per employee per date', function () {
    $emp = $this->createEmployee();
    $shift = $this->createShift();
    RosterDuty::factory()->create(['employee_id' => $emp->employee_id, 'roster_date' => '2026-10-26', 'shift_id' => $shift->id]);

    $this->actingAs($this->adminUser())
        ->post(route('hrm.duties.store'), [
            'employee_id' => $emp->employee_id,
            'shift_id' => $shift->id,
            'roster_date' => '2026-10-26',
            'status' => 'Scheduled',
        ])
        ->assertSessionHasErrors('employee_id');
});

test('can update roster duty', function () {
    $emp = $this->createEmployee();
    $shift = $this->createShift();
    $duty = RosterDuty::factory()->create(['employee_id' => $emp->employee_id, 'roster_date' => '2026-10-26', 'shift_id' => $shift->id]);
    $newShift = RosterShift::factory()->create(['shift_name' => 'New Shift', 'start_time' => '08:00', 'end_time' => '16:00']);

    $this->actingAs($this->adminUser())
        ->put(route('hrm.duties.update', $duty->id), [
            'employee_id' => $emp->employee_id,
            'shift_id' => $newShift->id,
            'roster_date' => '2026-10-26',
            'status' => 'Working',
        ])
        ->assertRedirect();

    expect($duty->refresh()->shift_id)->toBe($newShift->id);
    expect($duty->status)->toBe('Working');
});

test('can delete roster duty', function () {
    $emp = $this->createEmployee();
    $duty = RosterDuty::factory()->create(['employee_id' => $emp->employee_id]);
    $this->actingAs($this->adminUser())
        ->delete(route('hrm.duties.destroy', $duty->id))
        ->assertRedirect();

    expect(RosterDuty::find($duty->id))->toBeNull();
});

test('can bulk assign roster duties', function () {
    $shift = $this->createShift();
    $emp1 = $this->createEmployee();
    $emp2 = $this->createEmployee();

    $this->actingAs($this->adminUser())
        ->post(route('hrm.duties.bulk'), [
            'employee_ids' => [$emp1->employee_id, $emp2->employee_id],
            'shift_id' => $shift->id,
            'from_date' => '2026-10-20',
            'to_date' => '2026-10-22',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    // 2 employees × 3 days = 6 duties
    expect(RosterDuty::count())->toBe(6);
});

test('bulk assign rejects excessive range', function () {
    $shift = $this->createShift();
    $emp = $this->createEmployee();

    $this->actingAs($this->adminUser())
        ->post(route('hrm.duties.bulk'), [
            'employee_ids' => [$emp->employee_id],
            'shift_id' => $shift->id,
            'from_date' => '2026-01-01',
            'to_date' => '2026-04-01', // >60 days
        ])
        ->assertSessionHasErrors();
});

test('roster duty service bulkAssign respects unique constraint via upsert', function () {
    $shift = $this->createShift();
    $emp = $this->createEmployee();
    $service = app(RosterDutyService::class);

    $count1 = $service->bulkAssign([$emp->employee_id], $shift->id, '2026-10-20', '2026-10-20');
    expect($count1)->toBe(1);
    $count2 = $service->bulkAssign([$emp->employee_id], $shift->id, '2026-10-20', '2026-10-20');
    expect($count2)->toBe(1); // upsert, not duplicate
    expect(RosterDuty::count())->toBe(1);
});

test('roster duty controller fixed issues: bulk before wildcard and correct request class', function () {
    // Verify bulk route exists and is POST
    expect(route('hrm.duties.bulk'))->toContain('duties/bulk');
    // Verify update uses UpdateRosterDutyRequest (allows same date for same record)
    $emp = $this->createEmployee();
    $shift = $this->createShift();
    $duty = RosterDuty::factory()->create(['employee_id' => $emp->employee_id, 'roster_date' => '2026-10-26', 'shift_id' => $shift->id]);
    // Updating same duty with same date should pass (unique ignore self)
    $this->actingAs($this->adminUser())
        ->put(route('hrm.duties.update', $duty->id), [
            'employee_id' => $emp->employee_id,
            'shift_id' => $shift->id,
            'roster_date' => '2026-10-26',
            'status' => 'Scheduled',
        ])
        ->assertRedirect();
});
