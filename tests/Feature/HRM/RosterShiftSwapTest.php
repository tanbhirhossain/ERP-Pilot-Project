<?php

use Modules\CORE\Database\Seeders\SuperAdminSeeder;
use Modules\HRM\Models\Roster\RosterDuty;
use Modules\HRM\Models\RosterShiftSwap;
use Modules\HRM\Services\RosterShiftSwapService;
use Tests\Feature\HRM\HrmTestHelper;

uses(HrmTestHelper::class);

beforeEach(function () {
    $this->seed(SuperAdminSeeder::class);
});

/*
 * P34 — an approved swap must actually move the duties.
 *
 * `RosterShiftSwap::approve()` looked the day up with `where('roster_date', $swap->swap_date)`. The
 * model casts `swap_date` to a date, so the bound value was "2026-10-13 00:00:00" while the column
 * holds "2026-10-13": no duty matched, the exchange was skipped, and the swap was still stamped
 * Approved. The roster screen reported a swap that never happened. Found on the clean rebuild.
 */
test('approving a shift swap exchanges both duties instead of silently doing nothing', function () {
    $this->actingAs($this->adminUser());

    $general = $this->createShift(['shift_name' => 'General 09-05', 'start_time' => '09:00', 'end_time' => '17:00']);
    $night = $this->createShift(['shift_name' => 'Night 11-07', 'start_time' => '23:00', 'end_time' => '07:00', 'crosses_midnight' => true]);
    $a = $this->createEmployee(['employee_id' => 'SWAP-A', 'full_name' => 'Swap A']);
    $b = $this->createEmployee(['employee_id' => 'SWAP-B', 'full_name' => 'Swap B']);

    $dutyA = RosterDuty::create([
        'employee_id' => 'SWAP-A', 'roster_date' => '2026-10-13', 'shift_id' => $general->id,
        'scheduled_start_time' => '09:00:00', 'scheduled_end_time' => '17:00:00', 'status' => 'Scheduled',
    ]);
    RosterDuty::create([
        'employee_id' => 'SWAP-B', 'roster_date' => '2026-10-13', 'shift_id' => $night->id,
        'scheduled_start_time' => '23:00:00', 'scheduled_end_time' => '07:00:00', 'status' => 'Scheduled',
    ]);

    $swap = RosterShiftSwap::create([
        'roster_duty_id' => $dutyA->id, 'requester_employee_id' => 'SWAP-A', 'target_employee_id' => 'SWAP-B',
        'requested_shift_id' => $night->id, 'target_shift_id' => $general->id,
        'swap_date' => '2026-10-13', 'reason' => 'Night clash', 'status' => 'Pending',
    ]);

    $approved = app(RosterShiftSwapService::class)->approve($swap->fresh());

    expect($approved->status)->toBe('Approved');

    $a = RosterDuty::where('employee_id', 'SWAP-A')->whereDate('roster_date', '2026-10-13')->sole();
    $b = RosterDuty::where('employee_id', 'SWAP-B')->whereDate('roster_date', '2026-10-13')->sole();

    expect($a->shift_id)->toBe($night->id)
        ->and($b->shift_id)->toBe($general->id)
        ->and($a->status)->toBe('Swapped')
        ->and($b->status)->toBe('Swapped')
        // The stored times must follow the shift, not stay on the old one.
        ->and(substr((string) $a->scheduled_start_time, 0, 5))->toBe('23:00')
        ->and(substr((string) $b->scheduled_start_time, 0, 5))->toBe('09:00');
});

test('approving a swap for a day with no duty fails loudly instead of pretending', function () {
    $this->actingAs($this->adminUser());

    $general = $this->createShift(['shift_name' => 'General 09-05', 'start_time' => '09:00', 'end_time' => '17:00']);
    $this->createEmployee(['employee_id' => 'SWAP-C', 'full_name' => 'Swap C']);
    $this->createEmployee(['employee_id' => 'SWAP-D', 'full_name' => 'Swap D']);

    // Only the requester is rostered on 2026-10-14: there is no counterpart duty to exchange.
    $requesterDuty = RosterDuty::create([
        'employee_id' => 'SWAP-C', 'roster_date' => '2026-10-14', 'shift_id' => $general->id,
        'scheduled_start_time' => '09:00:00', 'scheduled_end_time' => '17:00:00', 'status' => 'Scheduled',
    ]);
    $swap = RosterShiftSwap::create([
        'roster_duty_id' => $requesterDuty->id, 'requester_employee_id' => 'SWAP-C', 'target_employee_id' => 'SWAP-D',
        'requested_shift_id' => $general->id, 'target_shift_id' => $general->id,
        'swap_date' => '2026-10-14', 'reason' => 'No duty that day', 'status' => 'Pending',
    ]);

    expect(fn () => app(RosterShiftSwapService::class)->approve($swap->fresh()))
        ->toThrow(DomainException::class);

    expect($swap->fresh()->status)->toBe('Pending');
});
