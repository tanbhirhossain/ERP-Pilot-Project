<?php

use Illuminate\Support\Facades\Route;
use Modules\HRM\Http\Controllers\AttendanceDebugController;
use Spatie\Permission\Middleware\PermissionMiddleware;

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

/*
|--------------------------------------------------------------------------
| Attendance diagnostics
|--------------------------------------------------------------------------
|
| Registered only outside production and only for holders of the HRM
| attendance permission. These endpoints expose raw punch data and can
| rebuild attendance rows, so they must never be reachable by an anonymous
| visitor or by an ordinary authenticated user.
|
*/
if (! app()->environment('production')) {
    Route::middleware(['web', 'auth'])
        ->prefix('hrm')
        ->name('hrm.')
        ->group(function (): void {
            Route::get('debug/attendance-orphans', [AttendanceDebugController::class, 'orphanStats'])
                ->middleware(PermissionMiddleware::class.':hrm.attendance.manage')
                ->name('debug.orphans');

            Route::post('debug/attendance-orphans/reprocess', [AttendanceDebugController::class, 'reprocessOrphans'])
                ->middleware(PermissionMiddleware::class.':hrm.attendance.manage')
                ->name('debug.reprocess');
        });
}
