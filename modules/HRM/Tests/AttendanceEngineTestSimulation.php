<?php
/**
 * Simulation runner - can be executed via php artisan tinker or standalone
 * Tests the 6-step engine without needing a real ZK device
 * 
 * Usage: php artisan tinker < modules/HRM/Tests/AttendanceEngineTestSimulation.php
 * Or:    php modules/HRM/Tests/AttendanceEngineTestSimulation.php (with Laravel bootstrap)
 */

use Modules\HRM\Models\Employee\Employee;
use Modules\HRM\Models\Employee\EmployeePolicy;
use Modules\HRM\Models\Roster\RosterShift;
use Modules\HRM\Models\Roster\RosterDuty;
use Modules\HRM\Models\Attendance\AttendanceLog;
use Modules\HRM\Models\Attendance\AttendancePair;
use Modules\HRM\Models\Attendance\AttendanceDaily;
use Modules\HRM\Models\BiometricDevice;

// 1. Setup test employee
$employee = Employee::where('employee_id','EMP-0001')->first();
if(!$employee){
    $employee = Employee::create([
        'employee_id'=>'EMP-0001','first_name'=>'Test','last_name'=>'Worker','full_name'=>'Test Worker',
        'email'=>'test@factory.local','department_id'=>1,'designation_id'=>1,
        'employment_type'=>'Permanent','status'=>'Active','joining_date'=>now()->toDateString()
    ]);
    EmployeePolicy::create(['employee_id'=>'EMP-0001','shift_type'=>'Fixed','grace_time_in'=>15,'grace_time_out'=>15,'is_punishable'=>true]);
    echo "Created test employee EMP-0001\n";
}

// 2. Setup shift
$shift = RosterShift::firstOrCreate(['shift_name'=>'Day Shift'], [
    'shift_name'=>'Day Shift','start_time'=>'09:00','end_time'=>'17:00',
    'punch_in_window_start'=>'08:00','punch_in_window_end'=>'10:00','crosses_midnight'=>false,'max_duration_hours'=>24
]);
echo "Shift: {$shift->shift_name} {$shift->start_time}-{$shift->end_time}\n";

// 3. Assign roster
RosterDuty::updateOrCreate(['employee_id'=>'EMP-0001','roster_date'=>today()->toDateString()], [
    'shift_id'=>$shift->id,'scheduled_start_time'=>$shift->start_time,'scheduled_end_time'=>$shift->end_time,'status'=>'Scheduled'
]);
echo "Roster assigned for ".today()->toDateString()."\n";

// 4. Simulate punches (IN then OUT)
$punchIn = now()->setTime(9,5,0);
$punchOut = now()->setTime(17,30,0);

AttendanceLog::create(['device_user_id'=>'1','employee_id'=>'EMP-0001','punch_time'=>$punchIn,'device_id'=>null,'punch_type'=>'AUTO','is_processed'=>false]);
AttendanceLog::create(['device_user_id'=>'1','employee_id'=>'EMP-0001','punch_time'=>$punchOut,'device_id'=>null,'punch_type'=>'AUTO','is_processed'=>false]);
echo "Created 2 raw logs: IN {$punchIn} / OUT {$punchOut}\n";

// 5. Process
$processor = app(\Modules\HRM\Services\Attendance\AttendanceProcessingService::class);
$result = $processor->processPending(10);
echo "Processing result: ".json_encode($result)."\n";

// 6. Verify
$pair = AttendancePair::where('employee_id','EMP-0001')->whereDate('duty_date',today()->toDateString())->latest()->first();
echo "Pair: ".($pair ? "{$pair->punch_in} -> {$pair->punch_out} = {$pair->worked_hours}h [{$pair->duty_type}]" : "NONE")."\n";

$daily = AttendanceDaily::where('employee_id','EMP-0001')->whereDate('duty_date',today()->toDateString())->first();
echo "Daily: ".($daily ? "P:{$daily->primary_worked_hours}h OT:{$daily->total_overtime_hours}h Total:{$daily->grand_total_hours}h Status:{$daily->status}" : "NONE")."\n";

echo "\n✓ ENGINE SIMULATION COMPLETE - if you see Pair + Daily with correct hours, engine is 100% workable\n";
