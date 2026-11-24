<?php

use Illuminate\Support\Facades\Route;
use Modules\HRM\Http\Controllers\Api\V1\ApprovalApiController;
use Modules\HRM\Http\Controllers\Api\V1\AttendanceApiController;
use Modules\HRM\Http\Controllers\Api\V1\AuthController;
use Modules\HRM\Http\Controllers\Api\V1\CeoApiController;
use Modules\HRM\Http\Controllers\Api\V1\CertificateApiController;
use Modules\HRM\Http\Controllers\Api\V1\ChainAdminApiController;
use Modules\HRM\Http\Controllers\Api\V1\DashboardController;
use Modules\HRM\Http\Controllers\Api\V1\DeviceApiController;
use Modules\HRM\Http\Controllers\Api\V1\InformedApiController;
use Modules\HRM\Http\Controllers\Api\V1\LeaveApiController;
use Modules\HRM\Http\Controllers\Api\V1\NoticeApiController;
use Modules\HRM\Http\Controllers\Api\V1\NotificationApiController;
use Modules\HRM\Http\Controllers\Api\V1\OrgApiController;
use Modules\HRM\Http\Controllers\Api\V1\OvertimeApiController;
use Modules\HRM\Http\Controllers\Api\V1\RegularizationApiController;
use Modules\HRM\Http\Controllers\Api\V1\RosterApiController;
use Modules\HRM\Http\Controllers\Api\V1\ResignationApiController;
use Modules\HRM\Http\Controllers\Api\V1\TaskApiController;
use Modules\HRM\Http\Controllers\Api\V1\TeamApiController;
use Modules\PAYROLL\Http\Controllers\Api\V1\LoanApiController;
use Modules\PAYROLL\Http\Controllers\Api\V1\PayslipApiController;
use Modules\PAYROLL\Http\Controllers\Api\V1\ProvidentFundApiController;

/**
 * Mobile API gateway (v1). One file for the whole app surface so the mobile
 * team has a single contract to read; controllers stay in their own modules.
 *
 * Auth: Sanctum bearer tokens (`auth:sanctum`). Every employee endpoint
 * resolves the employee from the token user — never from request input.
 *
 * Phase map: 0+1 (foundation + employee self-service) is routed here.
 * Phase 2 (approval queues/engine) and Phase 3 (supervisor/GM/CEO) extend
 * this same v1 group.
 */
Route::middleware(['api'])->prefix('api')->group(function (): void {
    Route::prefix('v1')->name('api.v1.')->group(function (): void {
        Route::post('auth/login', [AuthController::class, 'login'])
            ->middleware('throttle:10,1')
            ->name('auth.login');

        Route::middleware('auth:sanctum')->group(function (): void {
            Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
            Route::post('auth/change-password', [AuthController::class, 'changePassword'])->name('auth.password');
            // NOTE: GET auth/me belongs to the F13 integrator surface (CallerIdentity);
            // the mobile profile lives at auth/profile so the two never collide.
            Route::get('auth/profile', [AuthController::class, 'profile'])->name('auth.profile');

            Route::get('dashboard', DashboardController::class)->name('dashboard');

            Route::get('leaves/types', [LeaveApiController::class, 'types'])->name('leaves.types');
            Route::get('leaves/balances', [LeaveApiController::class, 'balances'])->name('leaves.balances');
            Route::get('leaves/estimate', [LeaveApiController::class, 'estimate'])->name('leaves.estimate');
            Route::get('leaves', [LeaveApiController::class, 'index'])->name('leaves.index');
            Route::post('leaves', [LeaveApiController::class, 'store'])->name('leaves.store');
            Route::get('leaves/{id}', [LeaveApiController::class, 'show'])->name('leaves.show');
            Route::delete('leaves/{id}', [LeaveApiController::class, 'destroy'])->name('leaves.destroy');

            Route::get('overtimes', [OvertimeApiController::class, 'index'])->name('overtimes.index');
            Route::post('overtimes', [OvertimeApiController::class, 'store'])->name('overtimes.store');
            Route::get('overtimes/{id}', [OvertimeApiController::class, 'show'])->name('overtimes.show');
            Route::post('overtimes/{id}/cancel', [OvertimeApiController::class, 'cancel'])->name('overtimes.cancel');

            Route::get('attendance/logs', [AttendanceApiController::class, 'logs'])->name('attendance.logs');
            Route::get('attendance/pairs', [AttendanceApiController::class, 'pairs'])->name('attendance.pairs');
            Route::get('attendance/daily', [AttendanceApiController::class, 'daily'])->name('attendance.daily');
            Route::get('attendance/summary', [AttendanceApiController::class, 'summary'])->name('attendance.summary');

            Route::get('regularizations/types', [RegularizationApiController::class, 'types'])->name('regularizations.types');
            Route::get('regularizations', [RegularizationApiController::class, 'index'])->name('regularizations.index');
            Route::post('regularizations', [RegularizationApiController::class, 'store'])->name('regularizations.store');
            Route::get('regularizations/{id}', [RegularizationApiController::class, 'show'])->name('regularizations.show');
            Route::post('regularizations/{id}/cancel', [RegularizationApiController::class, 'cancel'])->name('regularizations.cancel');

            Route::get('informed/types', [InformedApiController::class, 'types'])->name('informed.types');
            Route::get('informed', [InformedApiController::class, 'index'])->name('informed.index');
            Route::post('informed', [InformedApiController::class, 'store'])->name('informed.store');
            Route::get('informed/{id}', [InformedApiController::class, 'show'])->name('informed.show');

            Route::get('resignations', [ResignationApiController::class, 'index'])->name('resignations.index');
            Route::post('resignations', [ResignationApiController::class, 'store'])->name('resignations.store');

            Route::get('certificates/types', [CertificateApiController::class, 'types'])->name('certificates.types');
            Route::get('certificates', [CertificateApiController::class, 'index'])->name('certificates.index');
            Route::post('certificates', [CertificateApiController::class, 'store'])->name('certificates.store');
            Route::get('certificates/{id}', [CertificateApiController::class, 'show'])->name('certificates.show');
            Route::post('certificates/{id}/cancel', [CertificateApiController::class, 'cancel'])->name('certificates.cancel');

            Route::get('payslips', [PayslipApiController::class, 'index'])->name('payslips.index');
            Route::get('payslips/{id}', [PayslipApiController::class, 'show'])->name('payslips.show');

            Route::get('loans', [LoanApiController::class, 'index'])->name('loans.index');
            Route::post('loans', [LoanApiController::class, 'store'])->name('loans.store');
            Route::get('loans/{id}', [LoanApiController::class, 'show'])->name('loans.show');

            Route::get('pf/account', [ProvidentFundApiController::class, 'account'])->name('pf.account');
            Route::get('pf/ledger', [ProvidentFundApiController::class, 'ledger'])->name('pf.ledger');
            Route::get('pf/withdrawals', [ProvidentFundApiController::class, 'withdrawals'])->name('pf.withdrawals');

            Route::get('notifications', [NotificationApiController::class, 'index'])->name('notifications.index');
            Route::get('notifications/unread-count', [NotificationApiController::class, 'unreadCount'])->name('notifications.unread-count');
            Route::post('notifications/{id}/read', [NotificationApiController::class, 'markRead'])->name('notifications.read');
            Route::post('notifications/read-all', [NotificationApiController::class, 'markAllRead'])->name('notifications.read-all');

            // Phase 4 — push device registry (FCM).
            Route::get('devices', [DeviceApiController::class, 'index'])->name('devices.index');
            Route::post('devices', [DeviceApiController::class, 'store'])->name('devices.store');
            Route::delete('devices/{id}', [DeviceApiController::class, 'destroy'])->name('devices.destroy');

            // Phase 2 — approval desks (stage membership authorizes, not a permission).
            Route::get('approvals/pending', [ApprovalApiController::class, 'pending'])->name('approvals.pending');
            Route::get('approvals/history', [ApprovalApiController::class, 'history'])->name('approvals.history');
            Route::post('approvals/leaves/{id}/approve', [ApprovalApiController::class, 'approveLeave'])->name('approvals.leaves.approve');
            Route::post('approvals/leaves/{id}/reject', [ApprovalApiController::class, 'rejectLeave'])->name('approvals.leaves.reject');
            Route::post('approvals/overtimes/{id}/approve', [ApprovalApiController::class, 'approveOvertime'])->name('approvals.overtimes.approve');
            Route::post('approvals/overtimes/{id}/reject', [ApprovalApiController::class, 'rejectOvertime'])->name('approvals.overtimes.reject');

            // Phase 2 — chain admin (hrm.approval-chains.view/.manage).
            Route::get('admin/chains', [ChainAdminApiController::class, 'index'])->name('admin.chains.index');
            Route::get('admin/chains/{key}', [ChainAdminApiController::class, 'show'])->name('admin.chains.show');
            Route::put('admin/chains/{key}', [ChainAdminApiController::class, 'update'])->name('admin.chains.update');

            // Phase 3 — team (direct reports, or everyone for HR/DGM/GM/CEO/Admins).
            Route::get('team/members', [TeamApiController::class, 'members'])->name('team.members');
            Route::get('team/attendance/logs', [TeamApiController::class, 'attendanceLogs'])->name('team.attendance.logs');
            Route::get('team/attendance/pairs', [TeamApiController::class, 'attendancePairs'])->name('team.attendance.pairs');
            Route::get('team/attendance/daily', [TeamApiController::class, 'attendanceDaily'])->name('team.attendance.daily');
            Route::get('team/attendance/summary', [TeamApiController::class, 'attendanceSummary'])->name('team.attendance.summary');
            Route::get('team/leaves', [TeamApiController::class, 'leaves'])->name('team.leaves');
            Route::post('team/leaves/apply', [TeamApiController::class, 'applyLeave'])->name('team.leaves.apply');
            Route::get('team/overtimes', [TeamApiController::class, 'overtimes'])->name('team.overtimes');
            Route::get('team/roster', [TeamApiController::class, 'roster'])->name('team.roster');

            // Phase 3 — roster writes (team scope, via RosterDutyService).
            Route::get('roster/shifts', [RosterApiController::class, 'shifts'])->name('roster.shifts');
            Route::post('roster/duties/bulk', [RosterApiController::class, 'bulkAssign'])->name('roster.duties.bulk');
            Route::post('roster/duties', [RosterApiController::class, 'store'])->name('roster.duties.store');
            Route::delete('roster/duties/{id}', [RosterApiController::class, 'destroy'])->name('roster.duties.destroy');

            // Phase 3 — work assignment (hrm.tasks.manage + scope).
            Route::post('tasks', [TaskApiController::class, 'store'])->name('tasks.store');
            Route::get('tasks/mine', [TaskApiController::class, 'mine'])->name('tasks.mine');
            Route::get('tasks/assigned', [TaskApiController::class, 'assigned'])->name('tasks.assigned');
            Route::get('tasks/team', [TaskApiController::class, 'team'])->name('tasks.team');
            Route::get('tasks/{id}', [TaskApiController::class, 'show'])->name('tasks.show');
            Route::patch('tasks/{id}/progress', [TaskApiController::class, 'progress'])->name('tasks.progress');
            Route::post('tasks/{id}/verify', [TaskApiController::class, 'verify'])->name('tasks.verify');
            Route::post('tasks/{id}/close', [TaskApiController::class, 'close'])->name('tasks.close');
            Route::post('tasks/{id}/reopen', [TaskApiController::class, 'reopen'])->name('tasks.reopen');
            Route::post('tasks/{id}/cancel', [TaskApiController::class, 'cancel'])->name('tasks.cancel');

            // Phase 3 — notices (hrm.notices.manage + audience scope).
            Route::post('notices', [NoticeApiController::class, 'store'])->name('notices.store');
            Route::get('notices/mine', [NoticeApiController::class, 'mine'])->name('notices.mine');
            Route::get('notices/posted', [NoticeApiController::class, 'posted'])->name('notices.posted');
            Route::patch('notices/{id}', [NoticeApiController::class, 'update'])->name('notices.update');
            Route::post('notices/{id}/retract', [NoticeApiController::class, 'retract'])->name('notices.retract');

            // Phase 3 — organisation directory.
            Route::get('org/departments', [OrgApiController::class, 'departments'])->name('org.departments');
            Route::get('org/employees', [OrgApiController::class, 'employees'])->name('org.employees');

            // Phase 3 — CEO cockpit (CEO role only).
            Route::get('ceo/dashboard', [CeoApiController::class, 'dashboard'])->name('ceo.dashboard');
            Route::get('ceo/statistics', [CeoApiController::class, 'statistics'])->name('ceo.statistics');
            Route::get('ceo/report', [CeoApiController::class, 'report'])->name('ceo.report');
        });
    });
});
