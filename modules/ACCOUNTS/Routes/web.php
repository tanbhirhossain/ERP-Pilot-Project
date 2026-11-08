<?php

use Illuminate\Support\Facades\Route;
use Modules\ACCOUNTS\Http\Controllers\AccountController;
use Modules\ACCOUNTS\Http\Controllers\BankController;
use Modules\ACCOUNTS\Http\Controllers\BankReconciliationController;
use Modules\ACCOUNTS\Http\Controllers\FiscalYearController;
use Modules\ACCOUNTS\Http\Controllers\JournalController;
use Modules\ACCOUNTS\Http\Controllers\OptionsController;
use Modules\ACCOUNTS\Http\Controllers\ReportController;
use Spatie\Permission\Middleware\PermissionMiddleware;

Route::middleware(['web', 'auth'])->prefix('accounts')->name('accounts.')->group(function (): void {

    Route::get('api/options/{type}', OptionsController::class)->where('type', '[a-z\-]+')->name('api.options');

    // ── Chart of Accounts ────────────────────────────────────────────────
    Route::get('coa', [AccountController::class, 'index'])->middleware(PermissionMiddleware::class.':accounts.coa.view')->name('coa.index');
    Route::get('coa/create', [AccountController::class, 'create'])->middleware(PermissionMiddleware::class.':accounts.coa.manage')->name('coa.create');
    Route::post('coa', [AccountController::class, 'store'])->middleware(PermissionMiddleware::class.':accounts.coa.manage')->name('coa.store');
    Route::get('coa/{account}', [AccountController::class, 'show'])->middleware(PermissionMiddleware::class.':accounts.coa.view')->name('coa.show');
    Route::get('coa/{account}/edit', [AccountController::class, 'edit'])->middleware(PermissionMiddleware::class.':accounts.coa.manage')->name('coa.edit');
    Route::put('coa/{account}', [AccountController::class, 'update'])->middleware(PermissionMiddleware::class.':accounts.coa.manage')->name('coa.update');
    Route::delete('coa/{account}', [AccountController::class, 'destroy'])->middleware(PermissionMiddleware::class.':accounts.coa.manage')->name('coa.destroy');

    // ── Journals (double-entry) ──────────────────────────────────────────
    Route::get('journals', [JournalController::class, 'index'])->middleware(PermissionMiddleware::class.':accounts.journals.view')->name('journals.index');
    Route::get('journals/create', [JournalController::class, 'create'])->middleware(PermissionMiddleware::class.':accounts.journals.create')->name('journals.create');
    Route::post('journals', [JournalController::class, 'store'])->middleware(PermissionMiddleware::class.':accounts.journals.create')->name('journals.store');
    Route::get('journals/{journal}', [JournalController::class, 'show'])->middleware(PermissionMiddleware::class.':accounts.journals.view')->name('journals.show');
    Route::get('journals/{journal}/edit', [JournalController::class, 'edit'])->middleware(PermissionMiddleware::class.':accounts.journals.view')->name('journals.edit');
    Route::put('journals/{journal}', [JournalController::class, 'update'])->middleware(PermissionMiddleware::class.':accounts.journals.create')->name('journals.update');
    Route::delete('journals/{journal}', [JournalController::class, 'destroy'])->middleware(PermissionMiddleware::class.':accounts.journals.create')->name('journals.destroy');
    Route::post('journals/{journal}/post', [JournalController::class, 'post'])->middleware(PermissionMiddleware::class.':accounts.journals.post')->name('journals.post');
    Route::post('journals/{journal}/void', [JournalController::class, 'void'])->middleware(PermissionMiddleware::class.':accounts.journals.void')->name('journals.void');

    // ── Vouchers (alias to journals filtered by type PV/RV/CV/BV) ───────
    Route::get('vouchers', [JournalController::class, 'index'])->middleware(PermissionMiddleware::class.':accounts.vouchers.view')->name('vouchers.index');
    Route::get('vouchers/create', [JournalController::class, 'create'])->middleware(PermissionMiddleware::class.':accounts.vouchers.view')->name('vouchers.create');

    // ── Banks ────────────────────────────────────────────────────────────
    Route::get('banks', [BankController::class, 'index'])->middleware(PermissionMiddleware::class.':accounts.banks.manage')->name('banks.index');
    Route::get('banks/create', [BankController::class, 'create'])->middleware(PermissionMiddleware::class.':accounts.banks.manage')->name('banks.create');
    Route::post('banks', [BankController::class, 'store'])->middleware(PermissionMiddleware::class.':accounts.banks.manage')->name('banks.store');
    // Bank reconciliation must be registered BEFORE `banks/{bank}`: Laravel
    // matches routes in order, so otherwise GET banks/reconcile binds
    // {bank}='reconcile' and 404s on model binding.
    Route::get('banks/reconcile', [BankReconciliationController::class, 'index'])->middleware(PermissionMiddleware::class.':accounts.banks.view')->name('banks.reconcile');
    Route::post('banks/reconcile/import', [BankReconciliationController::class, 'import'])->middleware(PermissionMiddleware::class.':accounts.banks.manage')->name('banks.reconcile.import');
    Route::post('banks/reconcile/match', [BankReconciliationController::class, 'reconcile'])->middleware(PermissionMiddleware::class.':accounts.banks.manage')->name('banks.reconcile.match');
    Route::post('banks/reconcile/{statement}/unreconcile', [BankReconciliationController::class, 'unreconcile'])->middleware(PermissionMiddleware::class.':accounts.banks.manage')->name('banks.reconcile.unreconcile');

    Route::get('banks/{bank}', [BankController::class, 'show'])->middleware(PermissionMiddleware::class.':accounts.banks.manage')->name('banks.show');
    Route::get('banks/{bank}/edit', [BankController::class, 'edit'])->middleware(PermissionMiddleware::class.':accounts.banks.manage')->name('banks.edit');
    Route::put('banks/{bank}', [BankController::class, 'update'])->middleware(PermissionMiddleware::class.':accounts.banks.manage')->name('banks.update');
    Route::delete('banks/{bank}', [BankController::class, 'destroy'])->middleware(PermissionMiddleware::class.':accounts.banks.manage')->name('banks.destroy');

    // ── Fiscal Years ─────────────────────────────────────────────────────
    Route::get('fiscal-years', [FiscalYearController::class, 'index'])->middleware(PermissionMiddleware::class.':accounts.fiscal.manage')->name('fiscal-years.index');
    Route::get('fiscal-years/create', [FiscalYearController::class, 'create'])->middleware(PermissionMiddleware::class.':accounts.fiscal.manage')->name('fiscal-years.create');
    Route::post('fiscal-years', [FiscalYearController::class, 'store'])->middleware(PermissionMiddleware::class.':accounts.fiscal.manage')->name('fiscal-years.store');
    Route::get('fiscal-years/{fiscalYear}', [FiscalYearController::class, 'show'])->middleware(PermissionMiddleware::class.':accounts.fiscal.manage')->name('fiscal-years.show');
    Route::get('fiscal-years/{fiscalYear}/edit', [FiscalYearController::class, 'edit'])->middleware(PermissionMiddleware::class.':accounts.fiscal.manage')->name('fiscal-years.edit');
    Route::put('fiscal-years/{fiscalYear}', [FiscalYearController::class, 'update'])->middleware(PermissionMiddleware::class.':accounts.fiscal.manage')->name('fiscal-years.update');
    Route::post('fiscal-years/{fiscalYear}/close', [FiscalYearController::class, 'close'])->middleware(PermissionMiddleware::class.':accounts.fiscal.manage')->name('fiscal-years.close');
    Route::post('fiscal-years/{fiscalYear}/reopen', [FiscalYearController::class, 'reopen'])->middleware(PermissionMiddleware::class.':accounts.fiscal.manage')->name('fiscal-years.reopen');

    // ── Reports ──────────────────────────────────────────────────────────
    Route::get('reports/trial-balance', [ReportController::class, 'trial'])->middleware(PermissionMiddleware::class.':accounts.reports.view')->name('reports.trial');
    Route::get('reports/profit-loss', [ReportController::class, 'profitLoss'])->middleware(PermissionMiddleware::class.':accounts.reports.view')->name('reports.pl');
    Route::get('reports/balance-sheet', [ReportController::class, 'balanceSheet'])->middleware(PermissionMiddleware::class.':accounts.reports.view')->name('reports.bs');
    Route::get('reports/ledger', [ReportController::class, 'ledger'])->middleware(PermissionMiddleware::class.':accounts.reports.view')->name('reports.ledger');
    Route::get('reports/payables-aging', [ReportController::class, 'payablesAging'])->middleware(PermissionMiddleware::class.':accounts.payables.view')->name('reports.payables-aging');
    Route::get('reports/cash-flow', [ReportController::class, 'cashFlow'])->middleware(PermissionMiddleware::class.':accounts.reports.view')->name('reports.cash-flow');
    Route::get('reports/depreciation', [ReportController::class, 'depreciation'])->middleware(PermissionMiddleware::class.':accounts.reports.view')->name('reports.depreciation');
    Route::get('reports/receivables-aging', [ReportController::class, 'receivablesAging'])->middleware(PermissionMiddleware::class.':accounts.receivables.view')->name('reports.receivables-aging');
    Route::get('payables', [ReportController::class, 'payables'])->middleware(PermissionMiddleware::class.':accounts.payables.view')->name('payables.index');
    Route::get('receivables', [ReportController::class, 'receivables'])->middleware(PermissionMiddleware::class.':accounts.receivables.view')->name('receivables.index');
});
