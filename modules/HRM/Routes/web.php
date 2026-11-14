<?php

use Illuminate\Support\Facades\Route;
use Modules\HRM\Http\Controllers\AttendanceDailyController;
use Modules\HRM\Http\Controllers\AttendanceDebugController;
use Modules\HRM\Http\Controllers\AttendanceFixController;
use Modules\HRM\Http\Controllers\AttendanceLogController;
use Modules\HRM\Http\Controllers\AttendancePairController;
use Modules\HRM\Http\Controllers\AttendancePipelineController;
use Modules\HRM\Http\Controllers\AttendanceSyncController;
use Modules\HRM\Http\Controllers\BiometricDeviceController;
use Modules\HRM\Http\Controllers\ChecklistController;
use Modules\HRM\Http\Controllers\ClearanceController;
use Modules\HRM\Http\Controllers\DepartmentController;
use Modules\HRM\Http\Controllers\DesignationController;
use Modules\HRM\Http\Controllers\DeviceUserController;
use Modules\HRM\Http\Controllers\EmployeeAssetController;
use Modules\HRM\Http\Controllers\EmployeeController;
use Modules\HRM\Http\Controllers\EmployeeDocumentController;
use Modules\HRM\Http\Controllers\EmployeeMovementController;
use Modules\HRM\Http\Controllers\EmployeeProbationController;
use Modules\HRM\Http\Controllers\EmployeeResignationController;
use Modules\HRM\Http\Controllers\EmployeeSupervisorController;
use Modules\HRM\Http\Controllers\HolidayController;
use Modules\HRM\Http\Controllers\HrmReportsController;
use Modules\HRM\Http\Controllers\InformedRequestController;
use Modules\HRM\Http\Controllers\LeaveRequestController;
use Modules\HRM\Http\Controllers\LeaveTypeController;
use Modules\HRM\Http\Controllers\LeaveYearEndController;
use Modules\HRM\Http\Controllers\MyHrController;
use Modules\HRM\Http\Controllers\OptionsController;
use Modules\HRM\Http\Controllers\OrganizationController;
use Modules\HRM\Http\Controllers\OvertimePolicyController;
use Modules\HRM\Http\Controllers\RegularizationController;
use Modules\HRM\Http\Controllers\RosterDutyController;
use Modules\HRM\Http\Controllers\RosterOvertimeController;
use Modules\HRM\Http\Controllers\RosterShiftController;
use Modules\HRM\Http\Controllers\RosterShiftSwapController;
use Modules\HRM\Http\Controllers\ShiftAllowanceController;
use Spatie\Permission\Middleware\PermissionMiddleware;

Route::middleware(['web', 'auth'])->prefix('hrm')->name('hrm.')->group(function () {

    // JSON options
    // Authorised per type inside the controller via OptionAccess. The coarse `hrm.view` that used
    // to sit here rejected callers whose *specific* grant the consuming page itself accepts, so a
    // custom role could open a form and get a 403 from its own picker. See the controller.
    Route::get('api/options/{type}', OptionsController::class)->where('type', '[a-z\-]+')->name('api.options');

    // Organization - single row
    Route::get('organization', [OrganizationController::class, 'index'])->middleware(PermissionMiddleware::class.':hrm.organization.view')->name('organizations.index');
    Route::post('organization', [OrganizationController::class, 'store'])->middleware(PermissionMiddleware::class.':hrm.organization.manage')->name('organizations.store');
    Route::put('organization', [OrganizationController::class, 'update'])->middleware(PermissionMiddleware::class.':hrm.organization.manage')->name('organizations.update');

    // Departments
    Route::get('departments', [DepartmentController::class, 'index'])->middleware(PermissionMiddleware::class.':hrm.departments.view')->name('departments.index');
    Route::get('departments/create', [DepartmentController::class, 'create'])->middleware(PermissionMiddleware::class.':hrm.departments.view')->name('departments.create');
    Route::post('departments', [DepartmentController::class, 'store'])->middleware(PermissionMiddleware::class.':hrm.departments.manage')->name('departments.store');
    Route::get('departments/{department}/edit', [DepartmentController::class, 'edit'])->middleware(PermissionMiddleware::class.':hrm.departments.view')->name('departments.edit');
    Route::put('departments/{department}', [DepartmentController::class, 'update'])->middleware(PermissionMiddleware::class.':hrm.departments.manage')->name('departments.update');
    Route::delete('departments/{department}', [DepartmentController::class, 'destroy'])->middleware(PermissionMiddleware::class.':hrm.departments.manage')->name('departments.destroy');

    // Designations
    Route::get('designations', [DesignationController::class, 'index'])->middleware(PermissionMiddleware::class.':hrm.designations.view')->name('designations.index');
    Route::get('designations/create', [DesignationController::class, 'create'])->middleware(PermissionMiddleware::class.':hrm.designations.view')->name('designations.create');
    Route::post('designations', [DesignationController::class, 'store'])->middleware(PermissionMiddleware::class.':hrm.designations.manage')->name('designations.store');
    Route::get('designations/{designation}/edit', [DesignationController::class, 'edit'])->middleware(PermissionMiddleware::class.':hrm.designations.view')->name('designations.edit');
    Route::put('designations/{designation}', [DesignationController::class, 'update'])->middleware(PermissionMiddleware::class.':hrm.designations.manage')->name('designations.update');
    Route::delete('designations/{designation}', [DesignationController::class, 'destroy'])->middleware(PermissionMiddleware::class.':hrm.designations.manage')->name('designations.destroy');

    // Biometric Devices
    Route::get('devices', [BiometricDeviceController::class, 'index'])->middleware(PermissionMiddleware::class.':hrm.devices.view')->name('devices.index');
    Route::get('devices/create', [BiometricDeviceController::class, 'create'])->middleware(PermissionMiddleware::class.':hrm.devices.view')->name('devices.create');
    Route::post('devices', [BiometricDeviceController::class, 'store'])->middleware(PermissionMiddleware::class.':hrm.devices.manage')->name('devices.store');
    Route::get('devices/{device}/edit', [BiometricDeviceController::class, 'edit'])->middleware(PermissionMiddleware::class.':hrm.devices.view')->name('devices.edit');
    Route::put('devices/{device}', [BiometricDeviceController::class, 'update'])->middleware(PermissionMiddleware::class.':hrm.devices.manage')->name('devices.update');
    Route::delete('devices/{device}', [BiometricDeviceController::class, 'destroy'])->middleware(PermissionMiddleware::class.':hrm.devices.manage')->name('devices.destroy');
    Route::post('devices/{device}/test', [BiometricDeviceController::class, 'test'])->middleware(PermissionMiddleware::class.':hrm.devices.manage')->name('devices.test');

    // Device User Mapping (ZK User -> Employee)
    Route::get('device-users', [DeviceUserController::class, 'index'])->middleware(PermissionMiddleware::class.':hrm.devices.view')->name('device-users.index');
    Route::post('device-users', [DeviceUserController::class, 'store'])->middleware(PermissionMiddleware::class.':hrm.devices.manage')->name('device-users.store');
    Route::put('device-users/{deviceUser}', [DeviceUserController::class, 'update'])->middleware(PermissionMiddleware::class.':hrm.devices.manage')->name('device-users.update');
    Route::delete('device-users/{deviceUser}', [DeviceUserController::class, 'destroy'])->middleware(PermissionMiddleware::class.':hrm.devices.manage')->name('device-users.destroy');
    Route::post('devices/{device}/sync-users', [DeviceUserController::class, 'syncFromDevice'])->middleware(PermissionMiddleware::class.':hrm.devices.manage')->middleware('throttle:hrm-device-sync')->name('devices.sync-users');

    // Employees
    Route::get('employees', [EmployeeController::class, 'index'])->middleware(PermissionMiddleware::class.':hrm.employees.view')->name('employees.index');
    Route::get('employees/create', [EmployeeController::class, 'create'])->middleware(PermissionMiddleware::class.':hrm.employees.view')->name('employees.create');
    Route::post('employees', [EmployeeController::class, 'store'])->middleware(PermissionMiddleware::class.':hrm.employees.manage')->name('employees.store');
    Route::get('employees/{employee}', [EmployeeController::class, 'show'])->middleware(PermissionMiddleware::class.':hrm.employees.view')->name('employees.show');
    Route::get('employees/{employee}/edit', [EmployeeController::class, 'edit'])->middleware(PermissionMiddleware::class.':hrm.employees.view')->name('employees.edit');
    Route::put('employees/{employee}', [EmployeeController::class, 'update'])->middleware(PermissionMiddleware::class.':hrm.employees.manage')->name('employees.update');
    Route::delete('employees/{employee}', [EmployeeController::class, 'destroy'])->middleware(PermissionMiddleware::class.':hrm.employees.manage')->name('employees.destroy');

    // Employee resignation requests (additive HRM record; employee table remains unchanged)
    Route::get('resignations', [EmployeeResignationController::class, 'index'])->middleware(PermissionMiddleware::class.':hrm.employees.view')->name('resignations.index');
    Route::post('resignations', [EmployeeResignationController::class, 'store'])->middleware(PermissionMiddleware::class.':hrm.employees.manage')->name('resignations.store');
    Route::post('resignations/{resignation}/decision', [EmployeeResignationController::class, 'decide'])->middleware(PermissionMiddleware::class.':hrm.employees.manage')->name('resignations.decision');

    // Roster Shifts
    Route::get('shifts', [RosterShiftController::class, 'index'])->middleware(PermissionMiddleware::class.':hrm.shifts.view')->name('shifts.index');
    Route::get('shifts/create', [RosterShiftController::class, 'create'])->middleware(PermissionMiddleware::class.':hrm.shifts.view')->name('shifts.create');
    Route::post('shifts', [RosterShiftController::class, 'store'])->middleware(PermissionMiddleware::class.':hrm.shifts.manage')->name('shifts.store');
    Route::get('shifts/{shift}/edit', [RosterShiftController::class, 'edit'])->middleware(PermissionMiddleware::class.':hrm.shifts.view')->name('shifts.edit');
    Route::put('shifts/{shift}', [RosterShiftController::class, 'update'])->middleware(PermissionMiddleware::class.':hrm.shifts.manage')->name('shifts.update');
    Route::delete('shifts/{shift}', [RosterShiftController::class, 'destroy'])->middleware(PermissionMiddleware::class.':hrm.shifts.manage')->name('shifts.destroy');

    // Roster Duties - Excel + Week Bulk (before wildcard)
    Route::get('duties', [RosterDutyController::class, 'index'])->middleware(PermissionMiddleware::class.':hrm.duties.view')->name('duties.index');
    Route::get('duties/excel', [RosterDutyController::class, 'excel'])->middleware(PermissionMiddleware::class.':hrm.duties.view')->name('duties.excel');
    Route::get('duties/bulk-week', [RosterDutyController::class, 'bulkWeek'])->middleware(PermissionMiddleware::class.':hrm.duties.view')->middleware('throttle:hrm-bulk-write')->name('duties.bulk-week');
    Route::get('duties/excel-data', [RosterDutyController::class, 'excelData'])->middleware(PermissionMiddleware::class.':hrm.duties.view')->name('duties.excel-data');
    Route::post('duties/excel-save', [RosterDutyController::class, 'excelSave'])->middleware(PermissionMiddleware::class.':hrm.duties.manage')->name('duties.excel-save');
    Route::get('duties/create', [RosterDutyController::class, 'create'])->middleware(PermissionMiddleware::class.':hrm.duties.view')->name('duties.create');
    Route::post('duties', [RosterDutyController::class, 'store'])->middleware(PermissionMiddleware::class.':hrm.duties.manage')->name('duties.store');
    Route::post('duties/bulk', [RosterDutyController::class, 'bulkStore'])->middleware(PermissionMiddleware::class.':hrm.duties.manage')->middleware('throttle:hrm-bulk-write')->name('duties.bulk');
    Route::get('duties/{duty}/edit', [RosterDutyController::class, 'edit'])->middleware(PermissionMiddleware::class.':hrm.duties.view')->name('duties.edit');
    Route::put('duties/{duty}', [RosterDutyController::class, 'update'])->middleware(PermissionMiddleware::class.':hrm.duties.manage')->name('duties.update');
    Route::delete('duties/{duty}', [RosterDutyController::class, 'destroy'])->middleware(PermissionMiddleware::class.':hrm.duties.manage')->name('duties.destroy');

    // Roster Swaps
    Route::get('roster-swaps', [RosterShiftSwapController::class, 'index'])->middleware(PermissionMiddleware::class.':hrm.swaps.view')->name('swaps.index');
    Route::get('roster-swaps/create', [RosterShiftSwapController::class, 'create'])->middleware(PermissionMiddleware::class.':hrm.swaps.view')->name('swaps.create');
    Route::post('roster-swaps', [RosterShiftSwapController::class, 'store'])->middleware(PermissionMiddleware::class.':hrm.swaps.manage')->name('swaps.store');
    Route::get('roster-swaps/{swap}', [RosterShiftSwapController::class, 'show'])->middleware(PermissionMiddleware::class.':hrm.swaps.view')->name('swaps.show');
    Route::post('roster-swaps/{swap}/approve', [RosterShiftSwapController::class, 'approve'])->middleware(PermissionMiddleware::class.':hrm.swaps.manage')->name('swaps.approve');
    Route::post('roster-swaps/{swap}/reject', [RosterShiftSwapController::class, 'reject'])->middleware(PermissionMiddleware::class.':hrm.swaps.manage')->name('swaps.reject');
    Route::delete('roster-swaps/{swap}', [RosterShiftSwapController::class, 'destroy'])->middleware(PermissionMiddleware::class.':hrm.swaps.manage')->name('swaps.destroy');

    // Holiday Calendar
    Route::get('holidays', [HolidayController::class, 'index'])->middleware(PermissionMiddleware::class.':hrm.holidays.view')->name('holidays.index');
    Route::post('holidays', [HolidayController::class, 'store'])->middleware(PermissionMiddleware::class.':hrm.holidays.manage')->name('holidays.store');
    Route::put('holidays/{holiday}', [HolidayController::class, 'update'])->middleware(PermissionMiddleware::class.':hrm.holidays.manage')->name('holidays.update');
    Route::delete('holidays/{holiday}', [HolidayController::class, 'destroy'])->middleware(PermissionMiddleware::class.':hrm.holidays.manage')->name('holidays.destroy');

    // Leave Types
    Route::get('leave-types', [LeaveTypeController::class, 'index'])->middleware(PermissionMiddleware::class.':hrm.leave-types.view')->name('leave-types.index');
    Route::get('leave-types/create', [LeaveTypeController::class, 'create'])->middleware(PermissionMiddleware::class.':hrm.leave-types.view')->name('leave-types.create');
    Route::post('leave-types', [LeaveTypeController::class, 'store'])->middleware(PermissionMiddleware::class.':hrm.leave-types.manage')->name('leave-types.store');
    Route::get('leave-types/{leave_type}/edit', [LeaveTypeController::class, 'edit'])->middleware(PermissionMiddleware::class.':hrm.leave-types.view')->name('leave-types.edit');
    Route::put('leave-types/{leave_type}', [LeaveTypeController::class, 'update'])->middleware(PermissionMiddleware::class.':hrm.leave-types.manage')->name('leave-types.update');
    Route::delete('leave-types/{leave_type}', [LeaveTypeController::class, 'destroy'])->middleware(PermissionMiddleware::class.':hrm.leave-types.manage')->name('leave-types.destroy');

    // Leave Requests
    Route::get('leaves/balances', [LeaveRequestController::class, 'balances'])->middleware(PermissionMiddleware::class.':hrm.leaves.balance.view')->name('leaves.balances');
    Route::get('leaves/balance', [LeaveRequestController::class, 'balance'])->middleware(PermissionMiddleware::class.':hrm.leaves.view')->name('leaves.balance');
    Route::get('leaves', [LeaveRequestController::class, 'index'])->middleware(PermissionMiddleware::class.':hrm.leaves.view')->name('leaves.index');
    Route::get('leaves/create', [LeaveRequestController::class, 'create'])->middleware(PermissionMiddleware::class.':hrm.leaves.view')->name('leaves.create');
    Route::post('leaves', [LeaveRequestController::class, 'store'])->middleware(PermissionMiddleware::class.':hrm.leaves.manage')->name('leaves.store');
    Route::get('leaves/{leave}', [LeaveRequestController::class, 'show'])->middleware(PermissionMiddleware::class.':hrm.leaves.view')->name('leaves.show');
    Route::get('leaves/{leave}/edit', [LeaveRequestController::class, 'edit'])->middleware(PermissionMiddleware::class.':hrm.leaves.view')->name('leaves.edit');
    Route::put('leaves/{leave}', [LeaveRequestController::class, 'update'])->middleware(PermissionMiddleware::class.':hrm.leaves.manage')->name('leaves.update');
    Route::post('leaves/{leave}/approve', [LeaveRequestController::class, 'approve'])->middleware(PermissionMiddleware::class.':hrm.leaves.approve')->name('leaves.approve');
    Route::post('leaves/{leave}/reject', [LeaveRequestController::class, 'reject'])->middleware(PermissionMiddleware::class.':hrm.leaves.approve')->name('leaves.reject');
    Route::delete('leaves/{leave}', [LeaveRequestController::class, 'destroy'])->middleware(PermissionMiddleware::class.':hrm.leaves.manage')->name('leaves.destroy');

    // Informed Panel — Late/Early/Absent notice
    Route::get('informed', [InformedRequestController::class, 'index'])->middleware(PermissionMiddleware::class.':hrm.informed.view')->name('informed.index');
    Route::get('informed/create', [InformedRequestController::class, 'create'])->middleware(PermissionMiddleware::class.':hrm.informed.view')->name('informed.create');
    Route::post('informed', [InformedRequestController::class, 'store'])->middleware(PermissionMiddleware::class.':hrm.informed.manage')->name('informed.store');
    Route::get('informed/{informed}', [InformedRequestController::class, 'show'])->middleware(PermissionMiddleware::class.':hrm.informed.view')->name('informed.show');
    Route::get('informed/{informed}/edit', [InformedRequestController::class, 'edit'])->middleware(PermissionMiddleware::class.':hrm.informed.view')->name('informed.edit');
    Route::put('informed/{informed}', [InformedRequestController::class, 'update'])->middleware(PermissionMiddleware::class.':hrm.informed.manage')->name('informed.update');
    Route::post('informed/{informed}/acknowledge', [InformedRequestController::class, 'acknowledge'])->middleware(PermissionMiddleware::class.':hrm.informed.manage')->name('informed.acknowledge');
    Route::post('informed/{informed}/reject', [InformedRequestController::class, 'reject'])->middleware(PermissionMiddleware::class.':hrm.informed.manage')->name('informed.reject');
    Route::delete('informed/{informed}', [InformedRequestController::class, 'destroy'])->middleware(PermissionMiddleware::class.':hrm.informed.manage')->name('informed.destroy');

    // Overtime & Extra Duty Approval Queues
    Route::get('extra-duty', [RosterOvertimeController::class, 'extraDutyIndex'])->middleware(PermissionMiddleware::class.':hrm.overtimes.view')->name('extra-duty.index');
    Route::post('overtimes/sync-attendance', [RosterOvertimeController::class, 'syncFromAttendance'])->middleware(PermissionMiddleware::class.':hrm.overtimes.manage')->middleware('throttle:hrm-device-sync')->name('overtimes.sync-attendance');
    Route::get('overtimes', [RosterOvertimeController::class, 'index'])->middleware(PermissionMiddleware::class.':hrm.overtimes.view')->name('overtimes.index');
    Route::get('overtimes/{overtime}', [RosterOvertimeController::class, 'show'])->middleware(PermissionMiddleware::class.':hrm.overtimes.view')->name('overtimes.show');
    Route::post('overtimes/{overtime}/approve', [RosterOvertimeController::class, 'approve'])->middleware(PermissionMiddleware::class.':hrm.overtimes.approve')->name('overtimes.approve');
    Route::post('overtimes/{overtime}/reject', [RosterOvertimeController::class, 'reject'])->middleware(PermissionMiddleware::class.':hrm.overtimes.approve')->name('overtimes.reject');

    // Attendance Logs
    Route::get('attendance/logs', [AttendanceLogController::class, 'index'])->middleware(PermissionMiddleware::class.':hrm.attendance.view')->name('attendance.logs.index');
    Route::post('attendance/logs', [AttendanceLogController::class, 'store'])->middleware(PermissionMiddleware::class.':hrm.attendance.manage')->name('attendance.logs.store');
    Route::delete('attendance/logs/{log}', [AttendanceLogController::class, 'destroy'])->middleware(PermissionMiddleware::class.':hrm.attendance.manage')->name('attendance.logs.destroy');
    Route::post('attendance/logs/process', [AttendanceLogController::class, 'process'])->middleware(PermissionMiddleware::class.':hrm.attendance.manage')->name('attendance.logs.process');

    // Attendance Pairs
    Route::get('attendance/pairs', [AttendancePairController::class, 'index'])->middleware(PermissionMiddleware::class.':hrm.attendance.view')->name('attendance.pairs.index');
    Route::delete('attendance/pairs/{pair}', [AttendancePairController::class, 'destroy'])->middleware(PermissionMiddleware::class.':hrm.attendance.manage')->name('attendance.pairs.destroy');

    // Attendance Dailies
    Route::get('attendance/dailies', [AttendanceDailyController::class, 'index'])->middleware(PermissionMiddleware::class.':hrm.attendance.view')->name('attendance.dailies.index');
    Route::post('attendance/dailies/reconcile', [AttendanceDailyController::class, 'reconcile'])->middleware(PermissionMiddleware::class.':hrm.attendance.manage')->name('attendance.dailies.reconcile');
    Route::delete('attendance/dailies/{daily}', [AttendanceDailyController::class, 'destroy'])->middleware(PermissionMiddleware::class.':hrm.attendance.manage')->name('attendance.dailies.destroy');

    // Attendance Fix — by Department / Employee / All
    Route::get('attendance/fix', [AttendanceFixController::class, 'index'])->middleware(PermissionMiddleware::class.':hrm.attendance.view')->name('attendance.fix.index');
    Route::post('attendance/fix', [AttendanceFixController::class, 'fix'])->middleware(PermissionMiddleware::class.':hrm.attendance.manage')->name('attendance.fix');
    Route::get('attendance/fix/status', [AttendanceFixController::class, 'status'])->middleware(PermissionMiddleware::class.':hrm.attendance.view')->name('attendance.fix.status');

    // Attendance Sync Panel
    Route::get('attendance/sync', [AttendanceSyncController::class, 'index'])->middleware(PermissionMiddleware::class.':hrm.attendance.sync')->name('attendance.sync.index');
    Route::post('attendance/sync/{device}', [AttendanceSyncController::class, 'syncDevice'])->middleware(PermissionMiddleware::class.':hrm.attendance.sync')->middleware('throttle:hrm-device-sync')->name('attendance.sync.device');
    Route::post('attendance/sync-all', [AttendanceSyncController::class, 'syncAll'])->middleware(PermissionMiddleware::class.':hrm.attendance.sync')->middleware('throttle:hrm-device-sync')->name('attendance.sync.all');
    Route::post('attendance/process-pending', [AttendanceSyncController::class, 'processPending'])->middleware(PermissionMiddleware::class.':hrm.attendance.sync')->middleware('throttle:hrm-device-sync')->name('attendance.sync.process');
    Route::post('attendance/reprocess-orphans', [AttendanceDebugController::class, 'reprocessOrphans'])->middleware(PermissionMiddleware::class.':hrm.attendance.manage')->name('attendance.reprocess');
    Route::get('attendance/debug-orphans', [AttendanceDebugController::class, 'orphanStats'])->middleware(PermissionMiddleware::class.':hrm.attendance.view')->name('attendance.debug');
    Route::put('attendance/sync/{device}/settings', [AttendanceSyncController::class, 'updateSetting'])->middleware(PermissionMiddleware::class.':hrm.attendance.sync')->name('attendance.sync.settings');
    Route::post('attendance/manual-punch', [AttendanceSyncController::class, 'manualPunch'])->middleware(PermissionMiddleware::class.':hrm.attendance.manage')->name('attendance.manual');
    Route::post('attendance/pipeline/{device}', [AttendancePipelineController::class, 'run'])->middleware(PermissionMiddleware::class.':hrm.attendance.manage')->name('attendance.pipeline.device');
    Route::post('attendance/pipeline-all', [AttendancePipelineController::class, 'runAll'])->middleware(PermissionMiddleware::class.':hrm.attendance.manage')->name('attendance.pipeline.all');
    Route::post('attendance/pipeline/stage/{stage}', [AttendancePipelineController::class, 'stage'])->middleware(PermissionMiddleware::class.':hrm.attendance.manage')->name('attendance.pipeline.stage');

    // Employee Supervisors — flexible: Users can be supervisor & Employee can also be supervisor
    Route::get('supervisors', [EmployeeSupervisorController::class, 'index'])->middleware(PermissionMiddleware::class.':hrm.employees.view')->name('supervisors.index');
    Route::get('supervisors/create', [EmployeeSupervisorController::class, 'create'])->middleware(PermissionMiddleware::class.':hrm.employees.view')->name('supervisors.create');
    Route::post('supervisors', [EmployeeSupervisorController::class, 'store'])->middleware(PermissionMiddleware::class.':hrm.employees.manage')->name('supervisors.store');
    Route::get('supervisors/{supervisor}', [EmployeeSupervisorController::class, 'show'])->middleware(PermissionMiddleware::class.':hrm.employees.view')->name('supervisors.show');
    Route::get('supervisors/{supervisor}/edit', [EmployeeSupervisorController::class, 'edit'])->middleware(PermissionMiddleware::class.':hrm.employees.view')->name('supervisors.edit');
    Route::put('supervisors/{supervisor}', [EmployeeSupervisorController::class, 'update'])->middleware(PermissionMiddleware::class.':hrm.employees.manage')->name('supervisors.update');
    Route::delete('supervisors/{supervisor}', [EmployeeSupervisorController::class, 'destroy'])->middleware(PermissionMiddleware::class.':hrm.employees.manage')->name('supervisors.destroy');

    // ── Employee Documents Vault (premium) ──────────────────────
    Route::get('documents', [EmployeeDocumentController::class, 'index'])->middleware(PermissionMiddleware::class.':hrm.documents.view')->name('documents.index');
    Route::post('documents', [EmployeeDocumentController::class, 'store'])->middleware(PermissionMiddleware::class.':hrm.documents.manage')->name('documents.store');
    Route::post('documents/{document}/verify', [EmployeeDocumentController::class, 'verify'])->middleware(PermissionMiddleware::class.':hrm.documents.verify')->name('documents.verify');
    Route::get('documents/{document}/download', [EmployeeDocumentController::class, 'download'])->middleware(PermissionMiddleware::class.':hrm.documents.view')->name('documents.download');
    Route::delete('documents/{document}', [EmployeeDocumentController::class, 'destroy'])->middleware(PermissionMiddleware::class.':hrm.documents.manage')->name('documents.destroy');
    Route::get('employees/{employee}/documents', [EmployeeDocumentController::class, 'forEmployee'])->middleware(PermissionMiddleware::class.':hrm.employees.view')->name('employees.documents');

    // ── Employee Assets (inventory → settlement bridge) ─────────
    Route::get('assets', [EmployeeAssetController::class, 'index'])->middleware(PermissionMiddleware::class.':hrm.assets.view')->name('assets.index');
    Route::post('assets', [EmployeeAssetController::class, 'storeAsset'])->middleware(PermissionMiddleware::class.':hrm.assets.manage')->name('assets.store');
    Route::post('assets/issue', [EmployeeAssetController::class, 'issue'])->middleware(PermissionMiddleware::class.':hrm.assets.manage')->name('assets.issue');
    Route::post('employee-assets/{employeeAsset}/return', [EmployeeAssetController::class, 'returnAsset'])->middleware(PermissionMiddleware::class.':hrm.assets.manage')->name('assets.return');
    Route::get('employees/{employee}/assets', [EmployeeAssetController::class, 'forEmployee'])->middleware(PermissionMiddleware::class.':hrm.employees.view')->name('employees.assets');

    // ── Probation / Confirmation (enterprise) ───────────────────
    Route::get('probations', [EmployeeProbationController::class, 'index'])->middleware(PermissionMiddleware::class.':hrm.probations.view')->name('probations.index');
    Route::post('probations', [EmployeeProbationController::class, 'store'])->middleware(PermissionMiddleware::class.':hrm.probations.manage')->name('probations.store');
    Route::post('probations/{probation}/extend', [EmployeeProbationController::class, 'extend'])->middleware(PermissionMiddleware::class.':hrm.probations.manage')->name('probations.extend');
    Route::post('probations/{probation}/confirm', [EmployeeProbationController::class, 'confirm'])->middleware(PermissionMiddleware::class.':hrm.probations.manage')->name('probations.confirm');
    Route::post('probations/{probation}/fail', [EmployeeProbationController::class, 'fail'])->middleware(PermissionMiddleware::class.':hrm.probations.manage')->name('probations.fail');

    // ── Career Movements (Transfer / Promotion / Demotion) ──────
    Route::get('movements', [EmployeeMovementController::class, 'index'])->middleware(PermissionMiddleware::class.':hrm.movements.view')->name('movements.index');
    Route::get('movements/create', [EmployeeMovementController::class, 'create'])->middleware(PermissionMiddleware::class.':hrm.movements.view')->name('movements.create');
    Route::post('movements', [EmployeeMovementController::class, 'store'])->middleware(PermissionMiddleware::class.':hrm.movements.manage')->name('movements.store');
    Route::get('movements/{movement}', [EmployeeMovementController::class, 'show'])->middleware(PermissionMiddleware::class.':hrm.movements.view')->name('movements.show');
    Route::post('movements/{movement}/submit', [EmployeeMovementController::class, 'submit'])->middleware(PermissionMiddleware::class.':hrm.movements.manage')->name('movements.submit');
    Route::post('movements/{movement}/approve', [EmployeeMovementController::class, 'approve'])->middleware(PermissionMiddleware::class.':hrm.movements.approve')->name('movements.approve');
    Route::post('movements/{movement}/reject', [EmployeeMovementController::class, 'reject'])->middleware(PermissionMiddleware::class.':hrm.movements.approve')->name('movements.reject');
    Route::post('movements/{movement}/cancel', [EmployeeMovementController::class, 'cancel'])->middleware(PermissionMiddleware::class.':hrm.movements.manage')->name('movements.cancel');

    // ── Onboarding / Offboarding Checklists ─────────────────────
    Route::get('checklists', [ChecklistController::class, 'index'])->middleware(PermissionMiddleware::class.':hrm.checklists.view')->name('checklists.index');
    Route::post('checklists/templates', [ChecklistController::class, 'storeTemplate'])->middleware(PermissionMiddleware::class.':hrm.checklists.manage')->name('checklists.templates.store');
    Route::post('checklists/assign', [ChecklistController::class, 'assign'])->middleware(PermissionMiddleware::class.':hrm.checklists.manage')->name('checklists.assign');
    Route::post('checklists/progress/{progressId}', [ChecklistController::class, 'progress'])->middleware(PermissionMiddleware::class.':hrm.checklists.manage')->name('checklists.progress');
    Route::post('checklists/seed', [ChecklistController::class, 'seed'])->middleware(PermissionMiddleware::class.':hrm.checklists.manage')->name('checklists.seed');

    // ── Clearance (offboarding gate) ────────────────────────────
    Route::get('clearances', [ClearanceController::class, 'index'])->middleware(PermissionMiddleware::class.':hrm.clearance.view')->name('clearance.index');
    Route::post('clearances/{clearanceTask}/clear', [ClearanceController::class, 'clear'])->middleware(PermissionMiddleware::class.':hrm.clearance.manage')->name('clearance.clear');
    Route::get('employees/{employee}/clearance', [ClearanceController::class, 'forEmployee'])->middleware(PermissionMiddleware::class.':hrm.employees.view')->name('employees.clearance');

    // ── Attendance Regularization (employee dispute → HR approve) ─
    Route::get('regularizations', [RegularizationController::class, 'index'])->middleware(PermissionMiddleware::class.':hrm.regularizations.view')->name('regularizations.index');
    Route::post('regularizations', [RegularizationController::class, 'store'])->middleware(PermissionMiddleware::class.':hrm.regularizations.manage')->name('regularizations.store');
    Route::post('regularizations/{regularization}/review', [RegularizationController::class, 'review'])->middleware(PermissionMiddleware::class.':hrm.regularizations.approve')->name('regularizations.review');
    Route::post('regularizations/{regularization}/cancel', [RegularizationController::class, 'cancel'])->middleware(PermissionMiddleware::class.':hrm.regularizations.manage')->name('regularizations.cancel');

    // ── Shift Allowances & OT Policies ──────────────────────────────
    Route::get('shift-allowances', [ShiftAllowanceController::class, 'index'])->middleware(PermissionMiddleware::class.':hrm.shift-allowances.view')->name('shift-allowances.index');
    Route::post('shift-allowances', [ShiftAllowanceController::class, 'store'])->middleware(PermissionMiddleware::class.':hrm.shift-allowances.manage')->name('shift-allowances.store');
    Route::delete('shift-allowances/{shiftAllowance}', [ShiftAllowanceController::class, 'destroy'])->middleware(PermissionMiddleware::class.':hrm.shift-allowances.manage')->name('shift-allowances.destroy');
    Route::get('overtime-policies', [OvertimePolicyController::class, 'index'])->middleware(PermissionMiddleware::class.':hrm.ot-policies.view')->name('overtime-policies.index');
    Route::post('overtime-policies', [OvertimePolicyController::class, 'store'])->middleware(PermissionMiddleware::class.':hrm.ot-policies.manage')->name('overtime-policies.store');
    Route::post('overtime-policies/{overtimePolicy}/toggle', [OvertimePolicyController::class, 'toggle'])->middleware(PermissionMiddleware::class.':hrm.ot-policies.manage')->name('overtime-policies.toggle');
    Route::post('overtime-policies/seed', [OvertimePolicyController::class, 'seed'])->middleware(PermissionMiddleware::class.':hrm.ot-policies.manage')->name('overtime-policies.seed');

    // ── Leave Year-End (carry-forward / encashment) ────────────────
    Route::get('leave-year-end', [LeaveYearEndController::class, 'index'])->middleware(PermissionMiddleware::class.':hrm.leave-year-end.view')->name('leave-year-end.index');
    Route::post('leave-year-end/settings', [LeaveYearEndController::class, 'updateSettings'])->middleware(PermissionMiddleware::class.':hrm.leave-year-end.manage')->name('leave-year-end.settings');
    Route::post('leave-year-end/close', [LeaveYearEndController::class, 'closeYear'])->middleware(PermissionMiddleware::class.':hrm.leave-year-end.manage')->name('leave-year-end.close');
    Route::get('leave-year-end/encash-preview', [LeaveYearEndController::class, 'encashPreview'])->middleware(PermissionMiddleware::class.':hrm.leave-year-end.view')->name('leave-year-end.encash-preview');

    // ── HR Reports & Analytics (H-P1-7) ──────────────────────────────
    Route::get('reports', [HrmReportsController::class, 'index'])->middleware(PermissionMiddleware::class.':hrm.reports.view')->name('reports.index');
    Route::get('reports/headcount', [HrmReportsController::class, 'headcount'])->middleware(PermissionMiddleware::class.':hrm.reports.view')->name('reports.headcount');
    Route::get('reports/attrition', [HrmReportsController::class, 'attrition'])->middleware(PermissionMiddleware::class.':hrm.reports.view')->name('reports.attrition');
    Route::get('reports/leave', [HrmReportsController::class, 'leave'])->middleware(PermissionMiddleware::class.':hrm.reports.view')->name('reports.leave');
    Route::get('reports/attendance', [HrmReportsController::class, 'attendance'])->middleware(PermissionMiddleware::class.':hrm.reports.view')->name('reports.attendance');
    Route::get('reports/ot', [HrmReportsController::class, 'ot'])->middleware(PermissionMiddleware::class.':hrm.reports.view')->name('reports.ot');
    Route::get('reports/export', [HrmReportsController::class, 'export'])->middleware(PermissionMiddleware::class.':hrm.reports.view')->name('reports.export');

    // ── Employee Self-Service (Phase 1 F9) ──────────────────────────────
    // `auth` only (inherited from the group). Every query is scoped to the
    // signed-in user's own employee record in the controller — there is no
    // route parameter that could expose a colleague's data, so no permission
    // can widen it. A user with no linked employee gets an actionable page.
    Route::get('my', [MyHrController::class, 'dashboard'])->name('my.dashboard');
    Route::get('my/payslips', [MyHrController::class, 'payslips'])->name('my.payslips');
    Route::get('my/payslips/{id}', [MyHrController::class, 'payslip'])->name('my.payslip');
    Route::get('my/leave', [MyHrController::class, 'leave'])->name('my.leave');
    Route::get('my/attendance', [MyHrController::class, 'attendance'])->name('my.attendance');
});
