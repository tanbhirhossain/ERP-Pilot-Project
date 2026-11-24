<?php

use Illuminate\Support\Facades\Route;
use Modules\HRM\Http\Controllers\Api\V1\AttendanceApiController;
use Modules\HRM\Http\Controllers\Api\V1\AuthController;
use Modules\HRM\Http\Controllers\Api\V1\CertificateApiController;
use Modules\HRM\Http\Controllers\Api\V1\DashboardController;
use Modules\HRM\Http\Controllers\Api\V1\InformedApiController;
use Modules\HRM\Http\Controllers\Api\V1\LeaveApiController;
use Modules\HRM\Http\Controllers\Api\V1\NotificationApiController;
use Modules\HRM\Http\Controllers\Api\V1\OvertimeApiController;
use Modules\HRM\Http\Controllers\Api\V1\RegularizationApiController;
use Modules\HRM\Http\Controllers\Api\V1\ResignationApiController;
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
            Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');

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
        });
    });
});
