<?php

use Illuminate\Support\Facades\Route;
use Modules\PAYROLL\Http\Controllers\PayrollAdjustmentController;
use Modules\PAYROLL\Http\Controllers\PayrollBonusController;
use Modules\PAYROLL\Http\Controllers\PayrollCutlistController;
use Modules\PAYROLL\Http\Controllers\PayrollDashboardController;
use Modules\PAYROLL\Http\Controllers\PayrollLoanController;
use Modules\PAYROLL\Http\Controllers\PayrollSalarySheetController;
use Modules\PAYROLL\Http\Controllers\PayrollSettingsController;
use Modules\PAYROLL\Http\Controllers\PayrollSettlementController;
use Modules\PAYROLL\Http\Controllers\PayrollSlipController;
use Modules\PAYROLL\Http\Controllers\ProvidentFundController;
use Modules\PAYROLL\Http\Controllers\SalaryIncrementController;
use Modules\PAYROLL\Http\Controllers\TaxConfigurationController;
use Spatie\Permission\Middleware\PermissionMiddleware;

Route::middleware(['web', 'auth'])->prefix('payroll')->name('payroll.')->group(function (): void {
    Route::get('/', PayrollDashboardController::class)->middleware(PermissionMiddleware::class.':payroll.view')->name('dashboard');

    Route::get('salary-sheets', [PayrollSalarySheetController::class, 'index'])->middleware(PermissionMiddleware::class.':payroll.sheets.manage')->name('sheets.index');
    Route::get('salary-sheets/create', [PayrollSalarySheetController::class, 'create'])->middleware(PermissionMiddleware::class.':payroll.sheets.manage')->name('sheets.create');
    Route::post('salary-sheets', [PayrollSalarySheetController::class, 'store'])->middleware(PermissionMiddleware::class.':payroll.sheets.manage')->name('sheets.store');
    Route::post('salary-sheets/{salarySheet}/release', [PayrollSalarySheetController::class, 'release'])->middleware(PermissionMiddleware::class.':payroll.sheets.release')->name('sheets.release');
    Route::get('salary-sheets/{salarySheet}', [PayrollSalarySheetController::class, 'show'])->middleware(PermissionMiddleware::class.':payroll.sheets.manage')->name('sheets.show');
    Route::post('cutlists/generate', [PayrollCutlistController::class, 'generate'])->middleware(PermissionMiddleware::class.':payroll.sheets.manage')->name('cutlists.generate');
    Route::get('cutlists/{cutlist}', [PayrollCutlistController::class, 'show'])->middleware(PermissionMiddleware::class.':payroll.sheets.manage')->name('cutlists.show');

    Route::get('slips/{slip}', [PayrollSlipController::class, 'show'])->middleware(PermissionMiddleware::class.':payroll.slips.view')->name('slips.show');
    Route::post('slips/{slip}/payment-status', [PayrollSlipController::class, 'setPaymentStatus'])->middleware(PermissionMiddleware::class.':payroll.slips.pay')->name('slips.payment-status');

    Route::get('increments', [SalaryIncrementController::class, 'index'])->middleware(PermissionMiddleware::class.':payroll.increments.manage')->name('increments.index');
    Route::get('increments/create', [SalaryIncrementController::class, 'create'])->middleware(PermissionMiddleware::class.':payroll.increments.manage')->name('increments.create');
    Route::post('increments', [SalaryIncrementController::class, 'store'])->middleware(PermissionMiddleware::class.':payroll.increments.manage')->name('increments.store');
    Route::post('increments/{increment}/approve', [SalaryIncrementController::class, 'approve'])->middleware(PermissionMiddleware::class.':payroll.increments.approve')->name('increments.approve');
    Route::post('increments/{increment}/reject', [SalaryIncrementController::class, 'reject'])->middleware(PermissionMiddleware::class.':payroll.increments.approve')->name('increments.reject');

    Route::get('loans', [PayrollLoanController::class, 'index'])->middleware(PermissionMiddleware::class.':payroll.loans.manage')->name('loans.index');
    Route::get('loans/create', [PayrollLoanController::class, 'create'])->middleware(PermissionMiddleware::class.':payroll.loans.manage')->name('loans.create');
    Route::post('loans', [PayrollLoanController::class, 'store'])->middleware(PermissionMiddleware::class.':payroll.loans.manage')->name('loans.store');
    Route::post('loans/allocate-installments', [PayrollLoanController::class, 'allocateInstallments'])->middleware(PermissionMiddleware::class.':payroll.loans.manage')->name('loans.allocate-installments');
    Route::post('loans/allocate-monthly', [PayrollLoanController::class, 'allocateMonthly'])->middleware(PermissionMiddleware::class.':payroll.loans.manage')->name('loans.allocate-monthly');
    Route::post('loans/{loan}/approve', [PayrollLoanController::class, 'approve'])->middleware(PermissionMiddleware::class.':payroll.loans.approve')->name('loans.approve');
    Route::post('loans/{loan}/reject', [PayrollLoanController::class, 'reject'])->middleware(PermissionMiddleware::class.':payroll.loans.approve')->name('loans.reject');

    Route::get('provident-fund', [ProvidentFundController::class, 'index'])->middleware(PermissionMiddleware::class.':payroll.pf.view')->name('pf.index');
    Route::post('provident-fund/allocate', [ProvidentFundController::class, 'allocate'])->middleware(PermissionMiddleware::class.':payroll.pf.manage')->name('pf.allocate');
    Route::post('provident-fund/policies', [ProvidentFundController::class, 'storePolicy'])->middleware(PermissionMiddleware::class.':payroll.pf.manage')->name('pf.policies.store');

    Route::get('tax', [TaxConfigurationController::class, 'index'])->middleware(PermissionMiddleware::class.':payroll.tax.manage')->name('tax.index');
    Route::put('tax', [TaxConfigurationController::class, 'update'])->middleware(PermissionMiddleware::class.':payroll.tax.manage')->name('tax.update');
    Route::post('tax/employee-profiles', [TaxConfigurationController::class, 'storeProfile'])->middleware(PermissionMiddleware::class.':payroll.tax.manage')->name('tax.profiles.store');

    Route::get('adjustments', [PayrollAdjustmentController::class, 'index'])->middleware(PermissionMiddleware::class.':payroll.adjustments.manage')->name('adjustments.index');
    Route::post('adjustments', [PayrollAdjustmentController::class, 'store'])->middleware(PermissionMiddleware::class.':payroll.adjustments.manage')->name('adjustments.store');

    Route::get('settings', [PayrollSettingsController::class, 'index'])->middleware(PermissionMiddleware::class.':payroll.settings.manage')->name('settings.index');
    Route::put('settings', [PayrollSettingsController::class, 'update'])->middleware(PermissionMiddleware::class.':payroll.settings.manage')->name('settings.update');

    Route::get('bonuses', [PayrollBonusController::class, 'index'])->middleware(PermissionMiddleware::class.':payroll.bonus.manage')->name('bonuses.index');
    Route::post('bonuses/policies', [PayrollBonusController::class, 'storePolicy'])->middleware(PermissionMiddleware::class.':payroll.bonus.manage')->name('bonuses.policies.store');
    Route::post('bonuses/{run}/release', [PayrollBonusController::class, 'release'])->middleware(PermissionMiddleware::class.':payroll.bonus.manage')->name('bonuses.release');
    Route::post('bonuses/generate', [PayrollBonusController::class, 'generate'])->middleware(PermissionMiddleware::class.':payroll.bonus.manage')->name('bonuses.generate');

    Route::get('settlements', [PayrollSettlementController::class, 'index'])->middleware(PermissionMiddleware::class.':payroll.settlements.manage')->name('settlements.index');
    Route::get('settlements/create', [PayrollSettlementController::class, 'create'])->middleware(PermissionMiddleware::class.':payroll.settlements.manage')->name('settlements.create');
    Route::post('settlements', [PayrollSettlementController::class, 'store'])->middleware(PermissionMiddleware::class.':payroll.settlements.manage')->name('settlements.store');
    Route::post('settlements/gratuity-policies', [PayrollSettlementController::class, 'storeGratuityPolicy'])->middleware(PermissionMiddleware::class.':payroll.settlements.manage')->name('settlements.gratuity-policies.store');
    Route::post('settlements/{settlement}/approve', [PayrollSettlementController::class, 'approve'])->middleware(PermissionMiddleware::class.':payroll.settlements.approve')->name('settlements.approve');
    Route::post('settlements/{settlement}/paid', [PayrollSettlementController::class, 'paid'])->middleware(PermissionMiddleware::class.':payroll.settlements.manage')->name('settlements.paid');
});
