<?php

use Illuminate\Support\Facades\Route;
use Modules\ACCOUNTS\Http\Controllers\OptionsController;

Route::middleware(['web', 'auth'])->prefix('accounts/api')->name('accounts.api.')->group(function (): void {
    Route::get('options/{type}', OptionsController::class)->where('type', '[a-z\-]+')->name('options');
});
