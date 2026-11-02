<?php
use Illuminate\Support\Facades\Route;
use Modules\HRM\Http\Controllers\OptionsController;
use Modules\HRM\Http\Controllers\OrganizationController;
use Modules\HRM\Http\Controllers\DepartmentController;
use Modules\HRM\Http\Controllers\DesignationController;
use Modules\HRM\Http\Controllers\BiometricDeviceController;
use Modules\HRM\Http\Controllers\EmployeeController;
use Modules\HRM\Http\Controllers\RosterShiftController;
use Modules\HRM\Http\Controllers\RosterDutyController;
use Modules\HRM\Http\Controllers\AttendanceLogController;
use Modules\HRM\Http\Controllers\AttendancePairController;
use Modules\HRM\Http\Controllers\AttendanceDailyController;
use Modules\HRM\Http\Controllers\AttendanceSyncController;
use Modules\HRM\Http\Controllers\DeviceUserController;
use Modules\HRM\Http\Controllers\EmployeeSupervisorController;

Route::middleware(['web','auth'])->prefix('hrm')->name('hrm.')->group(function () {

    // JSON options
    Route::get('api/options/{type}', OptionsController::class)->where('type','[a-z\-]+')->name('api.options');

    // Organization - single row
    Route::get('organization', [OrganizationController::class,'index'])->name('organizations.index');
    Route::post('organization', [OrganizationController::class,'store'])->name('organizations.store');
    Route::put('organization', [OrganizationController::class,'update'])->name('organizations.update');

    // Departments
    Route::get('departments', [DepartmentController::class,'index'])->name('departments.index');
    Route::get('departments/create', [DepartmentController::class,'create'])->name('departments.create');
    Route::post('departments', [DepartmentController::class,'store'])->name('departments.store');
    Route::get('departments/{department}/edit', [DepartmentController::class,'edit'])->name('departments.edit');
    Route::put('departments/{department}', [DepartmentController::class,'update'])->name('departments.update');
    Route::delete('departments/{department}', [DepartmentController::class,'destroy'])->name('departments.destroy');

    // Designations
    Route::get('designations', [DesignationController::class,'index'])->name('designations.index');
    Route::get('designations/create', [DesignationController::class,'create'])->name('designations.create');
    Route::post('designations', [DesignationController::class,'store'])->name('designations.store');
    Route::get('designations/{designation}/edit', [DesignationController::class,'edit'])->name('designations.edit');
    Route::put('designations/{designation}', [DesignationController::class,'update'])->name('designations.update');
    Route::delete('designations/{designation}', [DesignationController::class,'destroy'])->name('designations.destroy');

    // Biometric Devices
    Route::get('devices', [BiometricDeviceController::class,'index'])->name('devices.index');
    Route::get('devices/create', [BiometricDeviceController::class,'create'])->name('devices.create');
    Route::post('devices', [BiometricDeviceController::class,'store'])->name('devices.store');
    Route::get('devices/{device}/edit', [BiometricDeviceController::class,'edit'])->name('devices.edit');
    Route::put('devices/{device}', [BiometricDeviceController::class,'update'])->name('devices.update');
    Route::delete('devices/{device}', [BiometricDeviceController::class,'destroy'])->name('devices.destroy');
    Route::post('devices/{device}/test', [BiometricDeviceController::class,'test'])->name('devices.test');


    // Device User Mapping (ZK User -> Employee)
    Route::get('device-users', [DeviceUserController::class,'index'])->name('device-users.index');
    Route::post('device-users', [DeviceUserController::class,'store'])->name('device-users.store');
    Route::put('device-users/{deviceUser}', [DeviceUserController::class,'update'])->name('device-users.update');
    Route::delete('device-users/{deviceUser}', [DeviceUserController::class,'destroy'])->name('device-users.destroy');
    Route::post('devices/{device}/sync-users', [DeviceUserController::class,'syncFromDevice'])->name('devices.sync-users');

    // Employees
    Route::get('employees', [EmployeeController::class,'index'])->name('employees.index');
    Route::get('employees/create', [EmployeeController::class,'create'])->name('employees.create');
    Route::post('employees', [EmployeeController::class,'store'])->name('employees.store');
    Route::get('employees/{employee}', [EmployeeController::class,'show'])->name('employees.show');
    Route::get('employees/{employee}/edit', [EmployeeController::class,'edit'])->name('employees.edit');
    Route::put('employees/{employee}', [EmployeeController::class,'update'])->name('employees.update');
    Route::delete('employees/{employee}', [EmployeeController::class,'destroy'])->name('employees.destroy');

    // Employee resignation requests (additive HRM record; employee table remains unchanged)
    Route::get('resignations', [\Modules\HRM\Http\Controllers\EmployeeResignationController::class, 'index'])->name('resignations.index');
    Route::post('resignations', [\Modules\HRM\Http\Controllers\EmployeeResignationController::class, 'store'])->name('resignations.store');
    Route::post('resignations/{resignation}/decision', [\Modules\HRM\Http\Controllers\EmployeeResignationController::class, 'decide'])->name('resignations.decision');

    // Roster Shifts
    Route::get('shifts', [RosterShiftController::class,'index'])->name('shifts.index');
    Route::get('shifts/create', [RosterShiftController::class,'create'])->name('shifts.create');
    Route::post('shifts', [RosterShiftController::class,'store'])->name('shifts.store');
    Route::get('shifts/{shift}/edit', [RosterShiftController::class,'edit'])->name('shifts.edit');
    Route::put('shifts/{shift}', [RosterShiftController::class,'update'])->name('shifts.update');
    Route::delete('shifts/{shift}', [RosterShiftController::class,'destroy'])->name('shifts.destroy');

    // Roster Duties - Excel + Week Bulk (before wildcard)
    Route::get('duties', [RosterDutyController::class,'index'])->name('duties.index');
    Route::get('duties/excel', [RosterDutyController::class,'excel'])->name('duties.excel');
    Route::get('duties/bulk-week', [RosterDutyController::class,'bulkWeek'])->name('duties.bulk-week');
    Route::get('duties/excel-data', [RosterDutyController::class,'excelData'])->name('duties.excel-data');
    Route::post('duties/excel-save', [RosterDutyController::class,'excelSave'])->name('duties.excel-save');
    Route::get('duties/create', [RosterDutyController::class,'create'])->name('duties.create');
    Route::post('duties', [RosterDutyController::class,'store'])->name('duties.store');
    Route::post('duties/bulk', [RosterDutyController::class,'bulkStore'])->name('duties.bulk');
    Route::get('duties/{duty}/edit', [RosterDutyController::class,'edit'])->name('duties.edit');
    Route::put('duties/{duty}', [RosterDutyController::class,'update'])->name('duties.update');
    Route::delete('duties/{duty}', [RosterDutyController::class,'destroy'])->name('duties.destroy');

    // Roster Swaps
    Route::get('roster-swaps', [\Modules\HRM\Http\Controllers\RosterShiftSwapController::class,'index'])->name('swaps.index');
    Route::get('roster-swaps/create', [\Modules\HRM\Http\Controllers\RosterShiftSwapController::class,'create'])->name('swaps.create');
    Route::post('roster-swaps', [\Modules\HRM\Http\Controllers\RosterShiftSwapController::class,'store'])->name('swaps.store');
    Route::get('roster-swaps/{swap}', [\Modules\HRM\Http\Controllers\RosterShiftSwapController::class,'show'])->name('swaps.show');
    Route::post('roster-swaps/{swap}/approve', [\Modules\HRM\Http\Controllers\RosterShiftSwapController::class,'approve'])->name('swaps.approve');
    Route::post('roster-swaps/{swap}/reject', [\Modules\HRM\Http\Controllers\RosterShiftSwapController::class,'reject'])->name('swaps.reject');
    Route::delete('roster-swaps/{swap}', [\Modules\HRM\Http\Controllers\RosterShiftSwapController::class,'destroy'])->name('swaps.destroy');

    // Holiday Calendar
    Route::get('holidays', [\Modules\HRM\Http\Controllers\HolidayController::class,'index'])->name('holidays.index');
    Route::post('holidays', [\Modules\HRM\Http\Controllers\HolidayController::class,'store'])->name('holidays.store');
    Route::put('holidays/{holiday}', [\Modules\HRM\Http\Controllers\HolidayController::class,'update'])->name('holidays.update');
    Route::delete('holidays/{holiday}', [\Modules\HRM\Http\Controllers\HolidayController::class,'destroy'])->name('holidays.destroy');

    // Leave Types
    Route::get('leave-types', [\Modules\HRM\Http\Controllers\LeaveTypeController::class,'index'])->name('leave-types.index');
    Route::get('leave-types/create', [\Modules\HRM\Http\Controllers\LeaveTypeController::class,'create'])->name('leave-types.create');
    Route::post('leave-types', [\Modules\HRM\Http\Controllers\LeaveTypeController::class,'store'])->name('leave-types.store');
    Route::get('leave-types/{leave_type}/edit', [\Modules\HRM\Http\Controllers\LeaveTypeController::class,'edit'])->name('leave-types.edit');
    Route::put('leave-types/{leave_type}', [\Modules\HRM\Http\Controllers\LeaveTypeController::class,'update'])->name('leave-types.update');
    Route::delete('leave-types/{leave_type}', [\Modules\HRM\Http\Controllers\LeaveTypeController::class,'destroy'])->name('leave-types.destroy');

    // Leave Requests
    Route::get('leaves/balances', [\Modules\HRM\Http\Controllers\LeaveRequestController::class,'balances'])->name('leaves.balances');
    Route::get('leaves/balance', [\Modules\HRM\Http\Controllers\LeaveRequestController::class,'balance'])->name('leaves.balance');
    Route::get('leaves', [\Modules\HRM\Http\Controllers\LeaveRequestController::class,'index'])->name('leaves.index');
    Route::get('leaves/create', [\Modules\HRM\Http\Controllers\LeaveRequestController::class,'create'])->name('leaves.create');
    Route::post('leaves', [\Modules\HRM\Http\Controllers\LeaveRequestController::class,'store'])->name('leaves.store');
    Route::get('leaves/{leave}', [\Modules\HRM\Http\Controllers\LeaveRequestController::class,'show'])->name('leaves.show');
    Route::get('leaves/{leave}/edit', [\Modules\HRM\Http\Controllers\LeaveRequestController::class,'edit'])->name('leaves.edit');
    Route::put('leaves/{leave}', [\Modules\HRM\Http\Controllers\LeaveRequestController::class,'update'])->name('leaves.update');
    Route::post('leaves/{leave}/approve', [\Modules\HRM\Http\Controllers\LeaveRequestController::class,'approve'])->name('leaves.approve');
    Route::post('leaves/{leave}/reject', [\Modules\HRM\Http\Controllers\LeaveRequestController::class,'reject'])->name('leaves.reject');
    Route::delete('leaves/{leave}', [\Modules\HRM\Http\Controllers\LeaveRequestController::class,'destroy'])->name('leaves.destroy');

    // Informed Panel — Late/Early/Absent notice
    Route::get('informed', [\Modules\HRM\Http\Controllers\InformedRequestController::class,'index'])->name('informed.index');
    Route::get('informed/create', [\Modules\HRM\Http\Controllers\InformedRequestController::class,'create'])->name('informed.create');
    Route::post('informed', [\Modules\HRM\Http\Controllers\InformedRequestController::class,'store'])->name('informed.store');
    Route::get('informed/{informed}', [\Modules\HRM\Http\Controllers\InformedRequestController::class,'show'])->name('informed.show');
    Route::get('informed/{informed}/edit', [\Modules\HRM\Http\Controllers\InformedRequestController::class,'edit'])->name('informed.edit');
    Route::put('informed/{informed}', [\Modules\HRM\Http\Controllers\InformedRequestController::class,'update'])->name('informed.update');
    Route::post('informed/{informed}/acknowledge', [\Modules\HRM\Http\Controllers\InformedRequestController::class,'acknowledge'])->name('informed.acknowledge');
    Route::post('informed/{informed}/reject', [\Modules\HRM\Http\Controllers\InformedRequestController::class,'reject'])->name('informed.reject');
    Route::delete('informed/{informed}', [\Modules\HRM\Http\Controllers\InformedRequestController::class,'destroy'])->name('informed.destroy');

    // Overtime & Extra Duty Approval Queues
    Route::get('extra-duty', [\Modules\HRM\Http\Controllers\RosterOvertimeController::class,'extraDutyIndex'])->name('extra-duty.index');
    Route::post('overtimes/sync-attendance', [\Modules\HRM\Http\Controllers\RosterOvertimeController::class,'syncFromAttendance'])->name('overtimes.sync-attendance');
    Route::get('overtimes', [\Modules\HRM\Http\Controllers\RosterOvertimeController::class,'index'])->name('overtimes.index');
    Route::get('overtimes/{overtime}', [\Modules\HRM\Http\Controllers\RosterOvertimeController::class,'show'])->name('overtimes.show');
    Route::post('overtimes/{overtime}/approve', [\Modules\HRM\Http\Controllers\RosterOvertimeController::class,'approve'])->name('overtimes.approve');
    Route::post('overtimes/{overtime}/reject', [\Modules\HRM\Http\Controllers\RosterOvertimeController::class,'reject'])->name('overtimes.reject');

    // Attendance Logs
    Route::get('attendance/logs', [AttendanceLogController::class,'index'])->name('attendance.logs.index');
    Route::post('attendance/logs', [AttendanceLogController::class,'store'])->name('attendance.logs.store');
    Route::delete('attendance/logs/{log}', [AttendanceLogController::class,'destroy'])->name('attendance.logs.destroy');
    Route::post('attendance/logs/process', [AttendanceLogController::class,'process'])->name('attendance.logs.process');

    // Attendance Pairs
    Route::get('attendance/pairs', [AttendancePairController::class,'index'])->name('attendance.pairs.index');
    Route::delete('attendance/pairs/{pair}', [AttendancePairController::class,'destroy'])->name('attendance.pairs.destroy');

    // Attendance Dailies
    Route::get('attendance/dailies', [AttendanceDailyController::class,'index'])->name('attendance.dailies.index');
    Route::post('attendance/dailies/reconcile', [AttendanceDailyController::class,'reconcile'])->name('attendance.dailies.reconcile');
    Route::delete('attendance/dailies/{daily}', [AttendanceDailyController::class,'destroy'])->name('attendance.dailies.destroy');

    // Attendance Fix — by Department / Employee / All
    Route::get('attendance/fix', [\Modules\HRM\Http\Controllers\AttendanceFixController::class,'index'])->name('attendance.fix.index');
    Route::post('attendance/fix', [\Modules\HRM\Http\Controllers\AttendanceFixController::class,'fix'])->name('attendance.fix');

    // Attendance Sync Panel
    Route::get('attendance/sync', [AttendanceSyncController::class,'index'])->name('attendance.sync.index');
    Route::post('attendance/sync/{device}', [AttendanceSyncController::class,'syncDevice'])->name('attendance.sync.device');
    Route::post('attendance/sync-all', [AttendanceSyncController::class,'syncAll'])->name('attendance.sync.all');
    Route::post('attendance/process-pending', [AttendanceSyncController::class,'processPending'])->name('attendance.sync.process');
    Route::post('attendance/reprocess-orphans', [\Modules\HRM\Http\Controllers\AttendanceDebugController::class,'reprocessOrphans'])->name('attendance.reprocess');
    Route::get('attendance/debug-orphans', [\Modules\HRM\Http\Controllers\AttendanceDebugController::class,'orphanStats'])->name('attendance.debug');
    Route::put('attendance/sync/{device}/settings', [AttendanceSyncController::class,'updateSetting'])->name('attendance.sync.settings');
    Route::post('attendance/manual-punch', [AttendanceSyncController::class,'manualPunch'])->name('attendance.manual');
    Route::post('attendance/pipeline/{device}', [\Modules\HRM\Http\Controllers\AttendancePipelineController::class,'run'])->name('attendance.pipeline.device');
    Route::post('attendance/pipeline-all', [\Modules\HRM\Http\Controllers\AttendancePipelineController::class,'runAll'])->name('attendance.pipeline.all');
    Route::post('attendance/pipeline/stage/{stage}', [\Modules\HRM\Http\Controllers\AttendancePipelineController::class,'stage'])->name('attendance.pipeline.stage');

    // Employee Supervisors — flexible: Users can be supervisor & Employee can also be supervisor
    Route::get('supervisors', [EmployeeSupervisorController::class,'index'])->name('supervisors.index');
    Route::get('supervisors/create', [EmployeeSupervisorController::class,'create'])->name('supervisors.create');
    Route::post('supervisors', [EmployeeSupervisorController::class,'store'])->name('supervisors.store');
    Route::get('supervisors/{supervisor}', [EmployeeSupervisorController::class,'show'])->name('supervisors.show');
    Route::get('supervisors/{supervisor}/edit', [EmployeeSupervisorController::class,'edit'])->name('supervisors.edit');
    Route::put('supervisors/{supervisor}', [EmployeeSupervisorController::class,'update'])->name('supervisors.update');
    Route::delete('supervisors/{supervisor}', [EmployeeSupervisorController::class,'destroy'])->name('supervisors.destroy');
});

