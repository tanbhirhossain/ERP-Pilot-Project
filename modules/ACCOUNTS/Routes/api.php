<?php

use Illuminate\Support\Facades\Route;
use Modules\ACCOUNTS\Http\Controllers\Api\BudgetApiController;
use Modules\ACCOUNTS\Http\Controllers\Api\JournalApiController;
use Modules\ACCOUNTS\Http\Controllers\Api\LedgerApiController;
use Modules\ACCOUNTS\Http\Controllers\OptionsController;
use Spatie\Permission\Middleware\PermissionMiddleware;

Route::middleware(['web', 'auth'])->prefix('accounts/api')->name('accounts.api.')->group(function (): void {
    Route::get('options/{type}', OptionsController::class)->where('type', '[a-z\-]+')->name('options');
});

/*
| F13 — v1 finance surface. The rule for money endpoints: read freely, write as a
| draft. Nothing under /api/v1 can post a journal, void one, or move the ledger.
|
| Permissions differ per route here (a trial balance is not a journal write), so they
| are declared on the route rather than the group — the same granularity the screen
| already uses, and the granularity `php artisan api:spec` publishes.
*/
Route::prefix('api/v1')->name('api.v1.')->middleware(['api', 'auth:sanctum'])->group(function (): void {
    Route::get('accounts/trial-balance', [LedgerApiController::class, 'trialBalance'])
        ->middleware([PermissionMiddleware::class.':accounts.reports.view', 'ability:accounts:read'])
        ->name('accounts.trial-balance');

    Route::get('accounts/budgets', [BudgetApiController::class, 'index'])
        ->middleware([PermissionMiddleware::class.':accounts.budgets.view', 'ability:accounts:read'])
        ->name('accounts.budgets.index');

    Route::get('accounts/budgets/{budget}/variance', [BudgetApiController::class, 'variance'])
        ->middleware([PermissionMiddleware::class.':accounts.budgets.view', 'ability:accounts:read'])
        ->name('accounts.budgets.variance');

    Route::post('accounts/journals', [JournalApiController::class, 'store'])
        ->middleware([PermissionMiddleware::class.':accounts.journals.create', 'ability:accounts:write'])
        ->name('accounts.journals.store');

    Route::get('accounts/journals/{journal}', [JournalApiController::class, 'show'])
        ->middleware([PermissionMiddleware::class.':accounts.journals.view', 'ability:accounts:read'])
        ->name('accounts.journals.show');
});
