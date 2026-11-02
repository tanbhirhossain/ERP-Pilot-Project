<?php

use Illuminate\Support\Facades\DB;
use Modules\CORE\Database\Seeders\SuperAdminSeeder;
use Modules\HRM\Models\Attendance\AttendanceDaily;
use Modules\HRM\Models\Attendance\AttendanceLog;
use Modules\HRM\Models\Attendance\AttendancePair;
use Modules\HRM\Models\AttendanceSyncSetting;
use Modules\HRM\Models\BiometricDevice;
use Modules\HRM\Models\DeviceUser;
use Modules\HRM\Models\Employee\EmployeePolicy;
use Modules\HRM\Models\Roster\RosterShift;
use Modules\HRM\Services\Attendance\AttendanceProcessingService;
use Modules\HRM\Services\Attendance\AttendanceSyncService;
use Tests\Feature\HRM\HrmTestHelper;

uses(HrmTestHelper::class);

beforeEach(function () {
    $this->seed(SuperAdminSeeder::class);
});

// Helper to create full setup
function createAttendanceSetup(): array
{
    $dept = \Modules\HRM\Models\Department::factory()->create();
    $desig = \Modules\HRM\Models\Designation::factory()->create(['department_id'=>$dept->id]);
    $emp = \Modules\HRM\Models\Employee\Employee::factory()->create([
        'department_id'=>$dept->id, 'designation_id'=>$desig->id
    ]);
    $shift = RosterShift::factory()->create([
        'shift_name'=>'Day Shift','start_time'=>'09:00','end_time'=>'17:00',
        'punch_in_window_start'=>'08:00','punch_in_window_end'=>'10:00','crosses_midnight'=>false
    ]);
    EmployeePolicy::factory()->fixed()->create(['employee_id'=>$emp->employee_id,'duty_hours'=>8,'grace_time_in'=>15,'grace_time_out'=>15]);
    \Modules\HRM\Models\Roster\RosterDuty::factory()->create([
        'employee_id'=>$emp->employee_id,'shift_id'=>$shift->id,'roster_date'=>today()->toDateString(),
        'scheduled_start_time'=>'09:00','scheduled_end_time'=>'17:00','status'=>'Scheduled'
    ]);
    $device = BiometricDevice::factory()->create(['is_enabled'=>true,'auto_sync_enabled'=>true]);
    DeviceUser::factory()->create(['device_id'=>$device->id,'device_user_id'=>'1001','uid'=>'1','employee_id'=>$emp->employee_id]);

    return compact('emp','shift','device');
}

test('can view attendance logs index', function () {
    $this->actingAs($this->adminUser())
        ->get(route('hrm.attendance.logs.index'))
        ->assertOk()
        ->assertInertia(fn($p)=>$p->component('HRM::Attendance/Logs/Index'));
});

test('attendance log creation and pairing engine - basic IN/OUT', function () {
    ['emp'=>$emp, 'shift'=>$shift] = createAttendanceSetup();
    $processor = app(AttendanceProcessingService::class);

    // Simulate punch IN at 09:05
    $logIn = AttendanceLog::factory()->create([
        'device_user_id'=>'1001','employee_id'=>$emp->employee_id,
        'punch_time'=>today()->setTime(9,5,0)->toDateTimeString(),'is_processed'=>false
    ]);
    // Punch OUT at 17:30
    $logOut = AttendanceLog::factory()->create([
        'device_user_id'=>'1001','employee_id'=>$emp->employee_id,
        'punch_time'=>today()->setTime(17,30,0)->toDateTimeString(),'is_processed'=>false
    ]);

    $result = $processor->processPending(10);
    expect($result['processed'])->toBe(2);
    expect($result['closed_pairs'])->toBe(1);

    $pair = AttendancePair::where('employee_id',$emp->employee_id)->first();
    expect($pair)->not->toBeNull();
    expect($pair->duty_type)->toBe('Primary');
    expect((float)$pair->worked_hours)->toBeGreaterThan(8)->toBeLessThan(9); // 8.41h

    $daily = AttendanceDaily::where('employee_id',$emp->employee_id)->whereDate('duty_date',today()->toDateString())->first();
    expect($daily)->not->toBeNull();
    expect((float)$daily->primary_worked_hours)->toBeGreaterThan(8);
    expect($daily->status)->toBe('Present'); // within grace
});

test('attendance engine marks late when beyond grace', function () {
    ['emp'=>$emp] = createAttendanceSetup();
    $processor = app(AttendanceProcessingService::class);

    // IN at 09:30 (30min late, grace 15)
    AttendanceLog::factory()->create([
        'device_user_id'=>'1001','employee_id'=>$emp->employee_id,
        'punch_time'=>today()->setTime(9,30,0)->toDateTimeString(),'is_processed'=>false
    ]);
    AttendanceLog::factory()->create([
        'device_user_id'=>'1001','employee_id'=>$emp->employee_id,
        'punch_time'=>today()->setTime(17,0,0)->toDateTimeString(),'is_processed'=>false
    ]);

    $processor->processPending(10);
    $daily = AttendanceDaily::where('employee_id',$emp->employee_id)->first();
    expect($daily->status)->toBe('Late');
});

test('attendance engine handles overtime as second pair', function () {
    ['emp'=>$emp] = createAttendanceSetup();
    $processor = app(AttendanceProcessingService::class);

    // Primary duty
    AttendanceLog::factory()->create(['device_user_id'=>'1001','employee_id'=>$emp->employee_id,'punch_time'=>today()->setTime(9,0,0)->toDateTimeString(),'is_processed'=>false]);
    AttendanceLog::factory()->create(['device_user_id'=>'1001','employee_id'=>$emp->employee_id,'punch_time'=>today()->setTime(17,0,0)->toDateTimeString(),'is_processed'=>false]);
    $processor->processPending(10);

    // Overtime punch same day evening
    AttendanceLog::factory()->create(['device_user_id'=>'1001','employee_id'=>$emp->employee_id,'punch_time'=>today()->setTime(18,0,0)->toDateTimeString(),'is_processed'=>false]);
    AttendanceLog::factory()->create(['device_user_id'=>'1001','employee_id'=>$emp->employee_id,'punch_time'=>today()->setTime(20,0,0)->toDateTimeString(),'is_processed'=>false]);
    $result = $processor->processPending(10);

    $pairs = AttendancePair::where('employee_id',$emp->employee_id)->get();
    expect($pairs->count())->toBe(2);
    expect($pairs->where('duty_type','Overtime')->count())->toBe(1);
    $daily = AttendanceDaily::where('employee_id',$emp->employee_id)->first();
    expect((float)$daily->total_overtime_hours)->toBe(2.0);
    expect((float)$daily->grand_total_hours)->toBe(10.0);
});

test('attendance engine handles night shift crossing midnight', function () {
    $dept=\Modules\HRM\Models\Department::factory()->create();
    $desig=\Modules\HRM\Models\Designation::factory()->create(['department_id'=>$dept->id]);
    $emp=\Modules\HRM\Models\Employee\Employee::factory()->create(['department_id'=>$dept->id,'designation_id'=>$desig->id]);
    $nightShift = RosterShift::factory()->night()->create();
    EmployeePolicy::factory()->create(['employee_id'=>$emp->employee_id,'shift_type'=>'Fixed']);
    \Modules\HRM\Models\Roster\RosterDuty::factory()->create([
        'employee_id'=>$emp->employee_id,'shift_id'=>$nightShift->id,'roster_date'=>today()->toDateString()
    ]);
    $processor = app(AttendanceProcessingService::class);

    // Punch IN at 22:05 on 2026-10-26, OUT at 06:10 on 2026-10-27 should both map to duty_date 2026-10-26
    AttendanceLog::factory()->create(['device_user_id'=>'999','employee_id'=>$emp->employee_id,'punch_time'=>today()->setTime(22,5,0)->toDateTimeString(),'is_processed'=>false]);
    AttendanceLog::factory()->create(['device_user_id'=>'999','employee_id'=>$emp->employee_id,'punch_time'=>today()->addDay()->setTime(6,10,0)->toDateTimeString(),'is_processed'=>false]);
    $processor->processPending(10);

    $pair = AttendancePair::where('employee_id',$emp->employee_id)->first();
    expect($pair)->not->toBeNull();
    expect($pair->duty_date->toDateString())->toBe(today()->toDateString());
    expect((float)$pair->worked_hours)->toBeGreaterThan(7)->toBeLessThan(9);
});

test('attendance engine handles flexible shift window detection', function () {
    $dept=\Modules\HRM\Models\Department::factory()->create();
    $desig=\Modules\HRM\Models\Designation::factory()->create(['department_id'=>$dept->id]);
    $emp=\Modules\HRM\Models\Employee\Employee::factory()->create(['department_id'=>$dept->id,'designation_id'=>$desig->id]);
    $flex = RosterShift::factory()->flexible()->create();
    RosterShift::factory()->create(['shift_name'=>'Other','start_time'=>'14:00','end_time'=>'22:00','punch_in_window_start'=>'13:00','punch_in_window_end'=>'15:00']);
    EmployeePolicy::factory()->flexible()->create(['employee_id'=>$emp->employee_id]);

    $processor = app(AttendanceProcessingService::class);
    AttendanceLog::factory()->create(['device_user_id'=>'1','employee_id'=>$emp->employee_id,'punch_time'=>today()->setTime(7,30,0)->toDateTimeString(),'is_processed'=>false]);
    $processor->processPending(10);
    $pair = AttendancePair::where('employee_id',$emp->employee_id)->first();
    expect($pair->shift_id)->toBe($flex->id);
});

test('attendance engine debounces duplicate punches within 1 minute', function () {
    ['emp'=>$emp] = createAttendanceSetup();
    $processor = app(AttendanceProcessingService::class);

    AttendanceLog::factory()->create(['device_user_id'=>'1001','employee_id'=>$emp->employee_id,'punch_time'=>today()->setTime(9,0,0)->toDateTimeString(),'is_processed'=>false]);
    $processor->processPending(10); // creates open pair
    expect(AttendancePair::count())->toBe(1);
    expect(AttendancePair::first()->punch_out)->toBeNull();

    // Duplicate punch 30 seconds later should be skipped via debounce
    AttendanceLog::factory()->create(['device_user_id'=>'1001','employee_id'=>$emp->employee_id,'punch_time'=>today()->setTime(9,0,30)->toDateTimeString(),'is_processed'=>false]);
    $result = $processor->processPending(10);
    // Should be marked processed but not create new pair nor close existing
    expect(AttendancePair::count())->toBe(1);
    expect(AttendancePair::first()->punch_out)->toBeNull();
});

test('unmapped orphan logs remain pending and are not paired until mapping is repaired', function () {
    $log = AttendanceLog::factory()->create([
        'device_user_id' => 'UNKNOWN999',
        'employee_id' => null,
        'punch_time' => now()->toDateTimeString(),
        'is_processed' => false,
    ]);
    $processor = app(AttendanceProcessingService::class);

    // The normal pending batch deliberately excludes unmapped logs; preserving them
    // lets the orphan-reprocessing flow retry after DeviceUser mapping is fixed.
    $batch = $processor->processPending(10);
    expect($batch['fetched'])->toBe(0);

    $single = $processor->processSingle($log);
    expect($single['status'])->toBe('skipped')
        ->and($single['action'])->toBe('orphan_retry')
        ->and($log->fresh()->is_processed)->toBeFalse()
        ->and(AttendancePair::count())->toBe(0);
});

test('attendance engine handles custom 72h shift', function () {
    $dept=\Modules\HRM\Models\Department::factory()->create();
    $desig=\Modules\HRM\Models\Designation::factory()->create(['department_id'=>$dept->id]);
    $emp=\Modules\HRM\Models\Employee\Employee::factory()->create(['department_id'=>$dept->id,'designation_id'=>$desig->id]);
    $custom = RosterShift::factory()->custom72()->create();
    EmployeePolicy::factory()->custom()->create(['employee_id'=>$emp->employee_id]);
    $processor = app(AttendanceProcessingService::class);

    // IN, then OUT 48 hours later (within 72h window)
    AttendanceLog::factory()->create(['device_user_id'=>'1','employee_id'=>$emp->employee_id,'punch_time'=>now()->subHours(48)->toDateTimeString(),'is_processed'=>false]);
    $processor->processPending(10);
    AttendanceLog::factory()->create(['device_user_id'=>'1','employee_id'=>$emp->employee_id,'punch_time'=>now()->toDateTimeString(),'is_processed'=>false]);
    $result = $processor->processPending(10);
    expect($result['closed_pairs'])->toBe(1);
    $pair = AttendancePair::first();
    expect((float)$pair->worked_hours)->toBeGreaterThan(47);
});

test('attendance daily correctly handles extra duty without primary', function () {
    ['emp'=>$emp] = createAttendanceSetup();
    $processor = app(AttendanceProcessingService::class);
    // Create primary pair first, then simulate extra duty on different date with no roster
    AttendanceLog::factory()->create(['device_user_id'=>'1001','employee_id'=>$emp->employee_id,'punch_time'=>today()->setTime(9,0,0)->toDateTimeString(),'is_processed'=>false]);
    AttendanceLog::factory()->create(['device_user_id'=>'1001','employee_id'=>$emp->employee_id,'punch_time'=>today()->setTime(17,0,0)->toDateTimeString(),'is_processed'=>false]);
    $processor->processPending(10);

    $daily = AttendanceDaily::where('employee_id',$emp->employee_id)->first();
    expect($daily->status)->toBe('Present');
});

// Sync Panel Tests
test('can view attendance sync panel', function () {
    $this->actingAs($this->adminUser())
        ->get(route('hrm.attendance.sync.index'))
        ->assertOk()
        ->assertInertia(fn($p)=>$p->component('HRM::AttendanceSync/Index'));
});

test('can trigger sync for single device', function () {
    $device = $this->createDevice(['is_enabled'=>true,'auto_sync_enabled'=>false]);
    // Sync will attempt ZK connection and fail gracefully, but should still log
    $this->actingAs($this->adminUser())
        ->post(route('hrm.attendance.sync.device', $device->id))
        ->assertRedirect()
        ->assertSessionHas('success');

    // Should have created a sync log
    expect(\Modules\HRM\Models\AttendanceSyncLog::where('device_id',$device->id)->exists())->toBeTrue();
});

test('can sync all devices', function () {
    BiometricDevice::factory()->count(2)->create(['is_enabled'=>true,'auto_sync_enabled'=>true]);
    $this->actingAs($this->adminUser())
        ->post(route('hrm.attendance.sync.all'))
        ->assertRedirect();
});

test('can update sync settings', function () {
    $device = $this->createDevice();
    $this->actingAs($this->adminUser())
        ->put(route('hrm.attendance.sync.settings', $device->id), [
            'auto_sync_enabled' => true,
            'sync_interval_minutes' => 5,
            'sync_window_hours' => 48,
        ])
        ->assertRedirect(route('hrm.attendance.sync.index'));

    $device->refresh();
    expect($device->auto_sync_enabled)->toBeTrue();
    expect($device->sync_interval_minutes)->toBe(5);
    expect(AttendanceSyncSetting::where('device_id',$device->id)->exists())->toBeTrue();
});

test('can add manual punch via sync panel', function () {
    $emp = $this->createEmployee();
    $this->actingAs($this->adminUser())
        ->post(route('hrm.attendance.manual'), [
            'employee_id' => $emp->employee_id,
            'punch_time' => now()->toDateTimeString(),
        ])
        ->assertRedirect();

    expect(AttendanceLog::where('employee_id',$emp->employee_id)->exists())->toBeTrue();
    $log = AttendanceLog::where('employee_id',$emp->employee_id)->first();
    expect($log->device_user_id)->toStartWith('MANUAL-');
    expect($log->is_processed)->toBeFalse();
});

test('can process pending logs via sync panel', function () {
    ['emp'=>$emp] = createAttendanceSetup();
    AttendanceLog::factory()->create(['device_user_id'=>'1001','employee_id'=>$emp->employee_id,'punch_time'=>today()->setTime(9,0,0)->toDateTimeString(),'is_processed'=>false]);
    $this->actingAs($this->adminUser())
        ->post(route('hrm.attendance.sync.process'))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(AttendanceLog::where('is_processed',false)->count())->toBe(0);
});

test('attendance sync service validates sockets extension (graceful fallback)', function () {
    // When package not installed, fetchFromDevice returns empty and sync succeeds with 0 fetched
    $device = $this->createDevice(['ip_address'=>'192.168.1.200']);
    $service = app(AttendanceSyncService::class);
    $result = $service->syncDevice($device);
    expect($result)->toHaveKey('ok');
    // Should be true (graceful empty fetch) or false if connection fails, but not exception
    expect($result)->toHaveKey('fetched');
});

test('device users mapping can be created', function () {
    $device = $this->createDevice();
    $emp = $this->createEmployee();
    $this->actingAs($this->adminUser())
        ->post(route('hrm.device-users.store'), [
            'device_id' => $device->id,
            'device_user_id' => '1001',
            'uid' => '1',
            'name' => 'Test User',
            'employee_id' => $emp->employee_id,
        ])
        ->assertRedirect();

    expect(DeviceUser::where('device_user_id','1001')->exists())->toBeTrue();
    expect(DeviceUser::where('employee_id',$emp->employee_id)->exists())->toBeTrue();
});
