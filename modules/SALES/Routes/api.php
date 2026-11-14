<?php

use Illuminate\Support\Facades\Route;
use Modules\SALES\Http\Controllers\Api\InvoiceApiController;
use Modules\SALES\Http\Controllers\OptionsController;
use Spatie\Permission\Middleware\PermissionMiddleware;

Route::middleware(['web', 'auth'])->prefix('sales/api')->name('sales.api.')->group(function (): void {
    Route::get('options/{type}', OptionsController::class)->where('type', '[a-z\-]+')->name('options');
});

/*
| F13 — v1 invoice reads.
|
| The query vocabulary is the DataTable contract (`search`, `sort`, `dir`, `page`,
| `per_page`, `filters[...]`) shared with the on-screen table on purpose, and the
| permission middleware is the same object the screen's routes run — so an
| integration can never see a row the accountant could not, in either direction.
*/
Route::prefix('api/v1')->name('api.v1.')->middleware([
    'api',
    'auth:sanctum',
    PermissionMiddleware::class.':sales.invoices.view',
    'ability:sales:read',
])->group(function (): void {
    Route::get('sales/invoices', [InvoiceApiController::class, 'index'])->name('sales.invoices.index');

    Route::get('sales/invoices/{key}', [InvoiceApiController::class, 'show'])->name('sales.invoices.show');
});
