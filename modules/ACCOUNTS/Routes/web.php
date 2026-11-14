<?php

use Illuminate\Support\Facades\Route;
use Modules\ACCOUNTS\Http\Controllers\AccountController;
use Modules\ACCOUNTS\Http\Controllers\BankController;
use Modules\ACCOUNTS\Http\Controllers\BankReconciliationController;
use Modules\ACCOUNTS\Http\Controllers\BudgetController;
use Modules\ACCOUNTS\Http\Controllers\BudgetVarianceController;
use Modules\ACCOUNTS\Http\Controllers\DepreciationController;
use Modules\ACCOUNTS\Http\Controllers\FiscalYearController;
use Modules\ACCOUNTS\Http\Controllers\FixedAssetController;
use Modules\ACCOUNTS\Http\Controllers\JournalController;
use Modules\ACCOUNTS\Http\Controllers\OpeningBalanceController;
use Modules\ACCOUNTS\Http\Controllers\OptionsController;
use Modules\ACCOUNTS\Http\Controllers\ReportController;
use Modules\ACCOUNTS\Http\Controllers\VatReturnController;
use Modules\ACCOUNTS\Http\Controllers\WithholdingController;
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

    // ── F17: Mushak 9.1 VAT return ───────────────────────────────────────
    Route::get('reports/vat-return', [VatReturnController::class, 'index'])->middleware(PermissionMiddleware::class.':accounts.reports.view')->name('reports.vat-return');
    Route::get('reports/vat-return/pdf', [VatReturnController::class, 'pdf'])->middleware(PermissionMiddleware::class.':accounts.reports.view')->name('reports.vat-return.pdf');

    // ── F17: TDS/VDS withholding ─────────────────────────────────────────
    Route::get('tax/withholding', [WithholdingController::class, 'index'])->middleware(PermissionMiddleware::class.':accounts.withholding.view')->name('tax.withholding.index');
    Route::post('tax/withholding/rules', [WithholdingController::class, 'storeRule'])->middleware(PermissionMiddleware::class.':accounts.withholding.manage')->name('tax.withholding.rules.store');
    Route::post('tax/withholding/rules/{rule}/toggle', [WithholdingController::class, 'toggleRule'])->middleware(PermissionMiddleware::class.':accounts.withholding.manage')->name('tax.withholding.rules.toggle');
    Route::post('tax/withholding/apply', [WithholdingController::class, 'applyToBill'])->middleware(PermissionMiddleware::class.':accounts.withholding.manage')->name('tax.withholding.apply');
    Route::post('tax/withholding/outward', [WithholdingController::class, 'recordOutward'])->middleware(PermissionMiddleware::class.':accounts.withholding.manage')->name('tax.withholding.outward');
    Route::post('tax/withholding/{withholding}/certify', [WithholdingController::class, 'certify'])->middleware(PermissionMiddleware::class.':accounts.withholding.manage')->name('tax.withholding.certify');
    Route::post('tax/withholding/deposit', [WithholdingController::class, 'deposit'])->middleware(PermissionMiddleware::class.':accounts.withholding.manage')->name('tax.withholding.deposit');

    // ── F15: Fixed assets & depreciation ────────────────────────────────
    Route::get('assets', [FixedAssetController::class, 'index'])->middleware(PermissionMiddleware::class.':accounts.assets.view')->name('assets.index');
    Route::post('assets', [FixedAssetController::class, 'store'])->middleware(PermissionMiddleware::class.':accounts.assets.manage')->name('assets.store');
    Route::post('assets/{asset}/capitalize', [FixedAssetController::class, 'capitalize'])->middleware(PermissionMiddleware::class.':accounts.assets.manage')->name('assets.capitalize');
    Route::post('assets/{asset}/dispose', [FixedAssetController::class, 'dispose'])->middleware(PermissionMiddleware::class.':accounts.assets.manage')->name('assets.dispose');
    Route::get('assets/depreciation', [DepreciationController::class, 'index'])->middleware(PermissionMiddleware::class.':accounts.assets.view')->name('assets.depreciation');
    Route::post('assets/depreciation', [DepreciationController::class, 'store'])->middleware(PermissionMiddleware::class.':accounts.assets.manage')->name('assets.depreciation.run');

    // ── F14: Budgets & variance ──────────────────────────────────────────
    Route::get('budgets/variance', [BudgetVarianceController::class, 'index'])->middleware(PermissionMiddleware::class.':accounts.budgets.view')->name('budgets.variance');
    Route::get('budgets', [BudgetController::class, 'index'])->middleware(PermissionMiddleware::class.':accounts.budgets.view')->name('budgets.index');
    Route::post('budgets', [BudgetController::class, 'store'])->middleware(PermissionMiddleware::class.':accounts.budgets.manage')->name('budgets.store');
    Route::post('budgets/{budget}/lines', [BudgetController::class, 'addLine'])->middleware(PermissionMiddleware::class.':accounts.budgets.manage')->name('budgets.lines.store');
    Route::delete('budgets/lines/{line}', [BudgetController::class, 'removeLine'])->middleware(PermissionMiddleware::class.':accounts.budgets.manage')->name('budgets.lines.destroy');
    Route::post('budgets/{budget}/approve', [BudgetController::class, 'approve'])->middleware(PermissionMiddleware::class.':accounts.budgets.manage')->name('budgets.approve');
    Route::post('budgets/{budget}/lock', [BudgetController::class, 'lock'])->middleware(PermissionMiddleware::class.':accounts.budgets.manage')->name('budgets.lock');

    // ── Opening-balance take-on wizard (Phase 1 F4) ─────────────────────
    Route::get('opening-balances', [OpeningBalanceController::class, 'index'])->middleware(PermissionMiddleware::class.':accounts.opening.manage')->name('opening-balances.index');
    Route::post('opening-balances/coa', [OpeningBalanceController::class, 'postCoa'])->middleware(PermissionMiddleware::class.':accounts.opening.manage')->name('opening-balances.coa');
    Route::post('opening-balances/ar', [OpeningBalanceController::class, 'postAr'])->middleware(PermissionMiddleware::class.':accounts.opening.manage')->name('opening-balances.ar');
    Route::post('opening-balances/ap', [OpeningBalanceController::class, 'postAp'])->middleware(PermissionMiddleware::class.':accounts.opening.manage')->name('opening-balances.ap');
    Route::post('opening-balances/stock', [OpeningBalanceController::class, 'postStock'])->middleware(PermissionMiddleware::class.':accounts.opening.manage')->name('opening-balances.stock');
});
