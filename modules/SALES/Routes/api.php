<?php

use Illuminate\Support\Facades\Route;
use Modules\SALES\Http\Controllers\OptionsController;

Route::middleware(['web', 'auth'])->prefix('sales/api')->name('sales.api.')->group(function (): void {
    Route::get('options/{type}', OptionsController::class)->where('type', '[a-z\-]+')->name('options');
});
