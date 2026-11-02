<?php

use Modules\HRM\Models\Attendance\AttendanceDaily;
use Modules\HRM\Models\Attendance\AttendanceLog;
use Modules\HRM\Models\Attendance\AttendancePair;
use Modules\HRM\Models\BiometricDevice;
use Modules\HRM\Models\Department;
use Modules\HRM\Models\Designation;
use Modules\HRM\Models\DeviceUser;
use Modules\HRM\Models\Employee\Employee;
use Modules\HRM\Models\Employee\EmployeePolicy;
use Modules\HRM\Models\Roster\RosterDuty;
use Modules\HRM\Models\Roster\RosterShift;
use Modules\HRM\Services\Attendance\AttendanceProcessingService;

beforeEach(function () {
    // Pest already handles RefreshDatabase for Unit suite via Pest.php
});

test('service resolves employee via device_user_id mapping', function () {
    $dept=Department::factory()->create();
    $desig=Designation::factory()->create(['department_id'=>$dept->id]);
    $emp=Employee::factory()->create(['department_id'=>$dept->id,'designation_id'=>$desig->id]);
    $device=BiometricDevice::factory()->create();
    DeviceUser::factory()->create(['device_id'=>$device->id,'device_user_id'=>'5001','employee_id'=>$emp->employee_id]);
    EmployeePolicy::factory()->fixed()->create(['employee_id'=>$emp->employee_id]);

    $shift=RosterShift::factory()->create(['start_time'=>'09:00','end_time'=>'17:00']);
    RosterDuty::factory()->create(['employee_id'=>$emp->employee_id,'shift_id'=>$shift->id,'roster_date'=>today()->toDateString()]);

    $log=AttendanceLog::factory()->create([
        'device_user_id'=>'5001','employee_id'=>null,'punch_time'=>today()->setTime(9,5,0)->toDateTimeString(),'is_processed'=>false
    ]);

    $processor=app(AttendanceProcessingService::class);
    $result=$processor->processSingle($log);

    expect($result['status'])->toBe('processed');
    expect($result['action'])->toBe('created');
    expect(AttendanceLog::find($log->id)->is_processed)->toBeTrue();
    // Self-healed employee_id
    expect(AttendanceLog::find($log->id)->employee_id)->toBe($emp->employee_id);
});

test('isTimeInWindow correctly handles midnight crossing', function () {
    $processor=app(AttendanceProcessingService::class);
    $ref=new ReflectionClass($processor);
    $method=$ref->getMethod('isTimeInWindow');
    $method->setAccessible(true);

    // Normal window 09:00-17:00
    expect($method->invoke($processor,'10:00','09:00','17:00'))->toBeTrue();
    expect($method->invoke($processor,'08:00','09:00','17:00'))->toBeFalse();
    expect($method->invoke($processor,'18:00','09:00','17:00'))->toBeFalse();

    // Midnight crossing 22:00-06:00
    expect($method->invoke($processor,'23:00','22:00','06:00'))->toBeTrue();
    expect($method->invoke($processor,'02:00','22:00','06:00'))->toBeTrue();
    expect($method->invoke($processor,'06:00','22:00','06:00'))->toBeTrue();
    expect($method->invoke($processor,'07:00','22:00','06:00'))->toBeFalse();
    expect($method->invoke($processor,'21:00','22:00','06:00'))->toBeFalse();
    expect($method->invoke($processor,'22:00','22:00','06:00'))->toBeTrue(); // boundary inclusive
});

test('resolveDutyDate assigns early AM punch to previous day for night shift', function () {
    $processor=app(AttendanceProcessingService::class);
    $ref=new ReflectionClass($processor);
    $method=$ref->getMethod('resolveDutyDate');
    $method->setAccessible(true);

    $nightShift=RosterShift::factory()->night()->create(); // 22:00-06:00 crosses midnight

    // Punch at 02:00 AM on 2026-10-27 should be duty_date 2026-10-26
    $punch= \Carbon\Carbon::create(2026,10,27,2,0,0);
    $dutyDate=$method->invoke($processor,$punch,$nightShift->id);
    expect($dutyDate)->toBe('2026-10-26');

    // Punch at 23:00 on 2026-10-26 should stay 2026-10-26
    $punch2= \Carbon\Carbon::create(2026,10,26,23,0,0);
    $dutyDate2=$method->invoke($processor,$punch2,$nightShift->id);
    expect($dutyDate2)->toBe('2026-10-26');

    // Day shift punches never shift
    $dayShift=RosterShift::factory()->create(['start_time'=>'09:00','end_time'=>'17:00','crosses_midnight'=>false]);
    $punch3=\Carbon\Carbon::create(2026,10,27,2,0,0);
    $dutyDate3=$method->invoke($processor,$punch3,$dayShift->id);
    expect($dutyDate3)->toBe('2026-10-27');
});

test('detectShift prioritizes roster duty over window', function () {
    $dept=Department::factory()->create();
    $desig=Designation::factory()->create(['department_id'=>$dept->id]);
    $emp=Employee::factory()->create(['department_id'=>$dept->id,'designation_id'=>$desig->id]);
    $shiftA=RosterShift::factory()->create(['shift_name'=>'Shift A','start_time'=>'09:00','end_time'=>'17:00','punch_in_window_start'=>'08:00','punch_in_window_end'=>'10:00']);
    $shiftB=RosterShift::factory()->create(['shift_name'=>'Shift B','start_time'=>'14:00','end_time'=>'22:00','punch_in_window_start'=>'13:00','punch_in_window_end'=>'15:00']);
    // Roster says B for today
    RosterDuty::factory()->create(['employee_id'=>$emp->employee_id,'shift_id'=>$shiftB->id,'roster_date'=>today()->toDateString()]);
    EmployeePolicy::factory()->flexible()->create(['employee_id'=>$emp->employee_id]);

    $processor=app(AttendanceProcessingService::class);
    $ref=new ReflectionClass($processor);
    $method=$ref->getMethod('detectShift');
    $method->setAccessible(true);

    // Even though 09:30 is in Shift A window, roster says B so should return B
    $punch=\Carbon\Carbon::createFromTime(9,30,0);
    $shiftId=$method->invoke($processor,$punch,EmployeePolicy::where('employee_id',$emp->employee_id)->first(),$emp->employee_id);
    expect($shiftId)->toBe($shiftB->id);
});

test('worked_hours calculation handles multi-day custom shift', function () {
    $dept=Department::factory()->create();
    $desig=Designation::factory()->create(['department_id'=>$dept->id]);
    $emp=Employee::factory()->create(['department_id'=>$dept->id,'designation_id'=>$desig->id]);
    EmployeePolicy::factory()->custom()->create(['employee_id'=>$emp->employee_id]);
    $processor=app(AttendanceProcessingService::class);

    $in = now()->subHours(50); // 50 hours ago
    $logIn=AttendanceLog::factory()->create(['device_user_id'=>'1','employee_id'=>$emp->employee_id,'punch_time'=>$in->toDateTimeString(),'is_processed'=>false]);
    $processor->processSingle($logIn);
    $pair=AttendancePair::where('employee_id',$emp->employee_id)->first();
    expect($pair->punch_out)->toBeNull();

    $logOut=AttendanceLog::factory()->create(['device_user_id'=>'1','employee_id'=>$emp->employee_id,'punch_time'=>now()->toDateTimeString(),'is_processed'=>false]);
    $processor->processSingle($logOut);
    $pair->refresh();
    expect((float)$pair->worked_hours)->toBeGreaterThan(49)->toBeLessThan(51);
});

test('upsertForPair recalculates grand_total correctly with multiple pairs', function () {
    $dept=Department::factory()->create();
    $desig=Designation::factory()->create(['department_id'=>$dept->id]);
    $emp=Employee::factory()->create(['department_id'=>$dept->id,'designation_id'=>$desig->id]);
    $shift=RosterShift::factory()->create();
    EmployeePolicy::factory()->fixed()->create(['employee_id'=>$emp->employee_id,'duty_hours'=>8]);
    RosterDuty::factory()->create(['employee_id'=>$emp->employee_id,'shift_id'=>$shift->id,'roster_date'=>today()->toDateString()]);

    $pair1=AttendancePair::factory()->create(['employee_id'=>$emp->employee_id,'shift_id'=>$shift->id,'duty_date'=>today()->toDateString(),'punch_in'=>today()->setTime(9,0,0)->toDateTimeString(),'punch_out'=>today()->setTime(12,0,0)->toDateTimeString(),'duty_type'=>'Primary','worked_hours'=>3]);
    $pair2=AttendancePair::factory()->create(['employee_id'=>$emp->employee_id,'shift_id'=>$shift->id,'duty_date'=>today()->toDateString(),'punch_in'=>today()->setTime(13,0,0)->toDateTimeString(),'punch_out'=>today()->setTime(17,0,0)->toDateTimeString(),'duty_type'=>'Primary','worked_hours'=>4]);
    $pairOt=AttendancePair::factory()->overtime()->create(['employee_id'=>$emp->employee_id,'shift_id'=>$shift->id,'duty_date'=>today()->toDateString(),'punch_in'=>today()->setTime(18,0,0)->toDateTimeString(),'punch_out'=>today()->setTime(20,0,0)->toDateTimeString(),'duty_type'=>'Overtime','worked_hours'=>2]);

    $dailyRepo=app(\Modules\HRM\Interfaces\Attendance\AttendanceDailyRepositoryInterface::class);
    $daily=$dailyRepo->upsertForPair($pair1);
    $daily=$dailyRepo->upsertForPair($pair2);
    $daily=$dailyRepo->upsertForPair($pairOt);

    expect((float)$daily->primary_worked_hours)->toBe(7.0);
    expect((float)$daily->total_overtime_hours)->toBe(2.0);
    expect((float)$daily->grand_total_hours)->toBe(9.0);
});

test('toMinutes helper converts time correctly', function () {
    $processor=app(AttendanceProcessingService::class);
    $ref=new ReflectionClass($processor);
    $m=$ref->getMethod('toMinutes');
    $m->setAccessible(true);
    expect($m->invoke($processor,'00:00'))->toBe(0);
    expect($m->invoke($processor,'09:00'))->toBe(540);
    expect($m->invoke($processor,'23:59'))->toBe(1439);
    expect($m->invoke($processor,'06:00'))->toBe(360);
});

