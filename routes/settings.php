<?php

use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('settings/password', [PasswordController::class, 'update'])->name('password.update');

    // Item 42: there is one appearance screen now, and it is the ERP's — inside the admin shell,
    // driven by the token engine, and able to set a company default. The starter kit's page
    // rendered in `AppLayout` (no sidebar, no module chrome) and offered a light/dark toggle that
    // wrote to localStorage only, so it is a redirect rather than a second, weaker copy.
    Route::redirect('settings/appearance', '/core/settings/appearance')->name('appearance');
});
