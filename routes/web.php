<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Entry routes
|--------------------------------------------------------------------------
|
| The admin application lives inside the CORE module (premium dashboard,
| tree sidebar with ACL menu, audit trail). Both starter-kit entry points
| forward into it, so a fresh login lands straight on the real dashboard
| instead of the kit's placeholder pages.
|
*/

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('core.dashboard')
        : redirect()->route('login');
})->name('home');

Route::get('dashboard', function () {
    return redirect()->route('core.dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
