<?php

use Illuminate\Support\Facades\Route;
use Modules\CORE\Http\Controllers\ActivityController;
use Modules\CORE\Http\Controllers\DashboardController;
use Modules\CORE\Http\Controllers\ExportController;
use Modules\CORE\Http\Controllers\OptionsController;
use Modules\CORE\Http\Controllers\PermissionController;
use Modules\CORE\Http\Controllers\RoleController;
use Modules\CORE\Http\Controllers\UiKitController;
use Modules\CORE\Http\Controllers\UserController;
use Spatie\Permission\Middleware\PermissionMiddleware;

/*
|--------------------------------------------------------------------------
| CORE module routes
|--------------------------------------------------------------------------
|
| Every page is rendered through the module's Inertia namespace, e.g.
| Inertia::render('CORE::Users/Index', [...]) — the '::' resolver in
| resources/js/app.ts maps that to modules/CORE/Resources/js/Pages.
|
| Access control is enforced server-side with Spatie's PermissionMiddleware
| on top of the 'web' + 'auth' stack.
|
*/

Route::middleware(['web', 'auth'])->prefix('core')->name('core.')->group(function (): void {
    // JSON options powering searchable dropdowns (kept inside the module).
    Route::get('api/options/{type}', OptionsController::class)
        ->where('type', '[a-z\-]+')
        ->name('api.options');

    // ---------------------------------------------------------------- Dashboard
    Route::get('/', DashboardController::class)
        ->middleware(PermissionMiddleware::class.':dashboard.view')
        ->name('dashboard');

    // --------------------------------------------------------- Design System
    Route::get('ui-kit', UiKitController::class)
        ->middleware(PermissionMiddleware::class.':dashboard.view')
        ->name('ui-kit');

    // -------------------------------------------------------------------- Users
    Route::get('users', [UserController::class, 'index'])
        ->middleware(PermissionMiddleware::class.':users.view')
        ->name('users.index');

    Route::get('users/create', [UserController::class, 'create'])
        ->middleware(PermissionMiddleware::class.':users.create')
        ->name('users.create');

    Route::post('users', [UserController::class, 'store'])
        ->middleware(PermissionMiddleware::class.':users.create')
        ->name('users.store');

    Route::get('users/{user}/edit', [UserController::class, 'edit'])
        ->middleware(PermissionMiddleware::class.':users.update')
        ->name('users.edit');

    Route::put('users/{user}', [UserController::class, 'update'])
        ->middleware(PermissionMiddleware::class.':users.update')
        ->name('users.update');

    Route::delete('users/{user}', [UserController::class, 'destroy'])
        ->middleware(PermissionMiddleware::class.':users.delete')
        ->name('users.destroy');

    // -------------------------------------------------------------------- Roles
    Route::get('roles', [RoleController::class, 'index'])
        ->middleware(PermissionMiddleware::class.':roles.view')
        ->name('roles.index');

    Route::get('roles/create', [RoleController::class, 'create'])
        ->middleware(PermissionMiddleware::class.':roles.create')
        ->name('roles.create');

    Route::post('roles', [RoleController::class, 'store'])
        ->middleware(PermissionMiddleware::class.':roles.create')
        ->name('roles.store');

    Route::get('roles/{role}/edit', [RoleController::class, 'edit'])
        ->middleware(PermissionMiddleware::class.':roles.update')
        ->name('roles.edit');

    Route::put('roles/{role}', [RoleController::class, 'update'])
        ->middleware(PermissionMiddleware::class.':roles.update')
        ->name('roles.update');

    Route::delete('roles/{role}', [RoleController::class, 'destroy'])
        ->middleware(PermissionMiddleware::class.':roles.delete')
        ->name('roles.destroy');

    // -------------------------------------------------------------- Permissions
    Route::get('permissions', [PermissionController::class, 'index'])
        ->middleware(PermissionMiddleware::class.':permissions.view')
        ->name('permissions.index');

    Route::get('permissions/create', [PermissionController::class, 'create'])
        ->middleware(PermissionMiddleware::class.':permissions.create')
        ->name('permissions.create');

    Route::post('permissions', [PermissionController::class, 'store'])
        ->middleware(PermissionMiddleware::class.':permissions.create')
        ->name('permissions.store');

    Route::get('permissions/{permission}/edit', [PermissionController::class, 'edit'])
        ->middleware(PermissionMiddleware::class.':permissions.update')
        ->name('permissions.edit');

    Route::put('permissions/{permission}', [PermissionController::class, 'update'])
        ->middleware(PermissionMiddleware::class.':permissions.update')
        ->name('permissions.update');

    Route::delete('permissions/{permission}', [PermissionController::class, 'destroy'])
        ->middleware(PermissionMiddleware::class.':permissions.delete')
        ->name('permissions.destroy');

    // -------------------------------------------------------------- Audit trail
    Route::get('activities', [ActivityController::class, 'index'])
        ->middleware(PermissionMiddleware::class.':audit.view')
        ->name('activities.index');

    Route::get('activities/{activity}', [ActivityController::class, 'show'])
        ->middleware(PermissionMiddleware::class.':audit.view')
        ->name('activities.show');

    // ------------------------------------------------------------------ Exports
    // Excel + PDF export of the *current* table view (same view permission).
    Route::get('exports/{resource}/{format}', ExportController::class)
        ->where('resource', 'users|roles|permissions|activities')
        ->where('format', 'excel|pdf')
        ->name('exports');
});
