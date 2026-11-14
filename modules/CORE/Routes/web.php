<?php

use Illuminate\Support\Facades\Route;
use Modules\CORE\Http\Controllers\ActivityController;
use Modules\CORE\Http\Controllers\ApiReferenceController;
use Modules\CORE\Http\Controllers\ApiTokensController;
use Modules\CORE\Http\Controllers\ApprovalController;
use Modules\CORE\Http\Controllers\AttachmentController;
use Modules\CORE\Http\Controllers\DashboardController;
use Modules\CORE\Http\Controllers\DocumentPdfController;
use Modules\CORE\Http\Controllers\ExportController;
use Modules\CORE\Http\Controllers\GlobalSearchController;
use Modules\CORE\Http\Controllers\ImportController;
use Modules\CORE\Http\Controllers\LocaleController;
use Modules\CORE\Http\Controllers\NotificationController;
use Modules\CORE\Http\Controllers\OptionsController;
use Modules\CORE\Http\Controllers\PermissionController;
use Modules\CORE\Http\Controllers\RoleController;
use Modules\CORE\Http\Controllers\SavedViewController;
use Modules\CORE\Http\Controllers\SsoController;
use Modules\CORE\Http\Controllers\UiKitController;
use Modules\CORE\Http\Controllers\UiSettingsController;
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

    // ------------------------------------------------------- UI settings (item 42)
    // Colour scheme and light/dark mode. Reading and choosing for yourself needs
    // only `auth`; setting the company default is a permission.
    Route::get('settings/appearance', [UiSettingsController::class, 'edit'])
        ->name('settings.appearance');

    Route::put('settings/appearance', [UiSettingsController::class, 'update'])
        ->name('settings.appearance.update');

    Route::put('settings/appearance/company', [UiSettingsController::class, 'updateCompany'])
        ->middleware(PermissionMiddleware::class.':settings.appearance.manage')
        ->name('settings.appearance.company');

    // ------------------------------------------------------- Language (item U19)
    // Choosing your own UI language needs only `auth` — a preference, not a
    // privilege. Setting the company default rides the same permission as the
    // colour default, because both are shell-wide policy.
    Route::put('locale', [LocaleController::class, 'update'])
        ->name('locale.update');

    Route::put('locale/company', [LocaleController::class, 'updateCompany'])
        ->middleware(PermissionMiddleware::class.':settings.appearance.manage')
        ->name('locale.company');

    // ------------------------------------------------------------------ Exports
    // Excel + PDF export of the *current* table view (same view permission).
    Route::get('exports/{resource}/{format}', ExportController::class)
        ->where('resource', 'users|roles|permissions|activities')
        ->where('format', 'excel|pdf')
        ->name('exports');

    // ------------------------------------------------- Notifications (Phase 1 F2)
    // The bell inbox. Reads are scoped to the signed-in user by the Notifiable
    // relation, so `auth` alone is the gate — no permission can widen whose
    // notifications you see.
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('api/notifications/feed', [NotificationController::class, 'feed'])->name('notifications.feed');
    Route::post('notifications/{id}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
    Route::post('notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');

    // ------------------------------------------------- Saved views (Phase 2 U8)
    // Per-user table views, scoped to the signed-in user in the controller, so
    // `auth` alone is the gate — no permission can widen whose views you see.
    // Global record search for the command palette (Phase 2 U9). Permission-scoped
    // per entity type inside the controller.
    Route::get('api/global-search', GlobalSearchController::class)->name('global-search');

    // ---------------------------------------- Document PDFs (Phase 2 U16)
    // Allow-listed types + per-type view permission enforced via the service.
    Route::get('documents/{type}/{id}/pdf', DocumentPdfController::class)->name('documents.pdf');

    // ------------------------------------------- Attachments (Phase 2 F19)
    // Type allow-list + per-record permissions enforced in the controller.
    Route::post('attachments/{type}/{id}', [AttachmentController::class, 'store'])->name('attachments.store');
    Route::get('attachments/{attachment}/download', [AttachmentController::class, 'download'])->name('attachments.download');
    Route::delete('attachments/{attachment}', [AttachmentController::class, 'destroy'])->name('attachments.destroy');

    Route::get('api/saved-views/{tableKey}', [SavedViewController::class, 'index'])->name('saved-views.index');
    Route::post('api/saved-views/{tableKey}', [SavedViewController::class, 'store'])->name('saved-views.store');
    Route::delete('api/saved-views/{tableKey}/{id}', [SavedViewController::class, 'destroy'])->name('saved-views.destroy');

    // --------------------------------------------------- Approvals (Phase 1 F3)
    Route::get('approvals', [ApprovalController::class, 'index'])
        ->middleware(PermissionMiddleware::class.':approvals.view')->name('approvals.index');
    Route::post('approvals/{approval}/approve', [ApprovalController::class, 'approve'])
        ->middleware(PermissionMiddleware::class.':approvals.manage')->name('approvals.approve');
    Route::post('approvals/{approval}/reject', [ApprovalController::class, 'reject'])
        ->middleware(PermissionMiddleware::class.':approvals.manage')->name('approvals.reject');
    Route::get('approval-policies', [ApprovalController::class, 'policies'])
        ->middleware(PermissionMiddleware::class.':approvals.manage')->name('approvals.policies');
    Route::post('approval-policies', [ApprovalController::class, 'storePolicy'])
        ->middleware(PermissionMiddleware::class.':approvals.manage')->name('approvals.policies.store');
    Route::post('approval-policies/{policy}/toggle', [ApprovalController::class, 'togglePolicy'])
        ->middleware(PermissionMiddleware::class.':approvals.manage')->name('approvals.policies.toggle');
    Route::delete('approval-policies/{policy}', [ApprovalController::class, 'destroyPolicy'])
        ->middleware(PermissionMiddleware::class.':approvals.manage')->name('approvals.policies.destroy');

    // ----------------------------------------------------- Imports (Phase 1 F5)
    Route::get('imports', [ImportController::class, 'index'])
        ->middleware(PermissionMiddleware::class.':imports.manage')->name('imports.index');
    Route::get('imports/{type}/template', [ImportController::class, 'template'])
        ->middleware(PermissionMiddleware::class.':imports.manage')->name('imports.template');
    Route::post('imports/{type}/preview', [ImportController::class, 'preview'])
        ->middleware(PermissionMiddleware::class.':imports.manage')->name('imports.preview');
    Route::post('imports/{type}/commit', [ImportController::class, 'commit'])
        ->middleware(PermissionMiddleware::class.':imports.manage')->name('imports.commit');

    // ------------------------------------------- F13 — API tokens & reference (self-service)
    // No PermissionMiddleware on purpose: a token inherits its owner's ACL, so issuing one
    // is not an escalation and gating it behind a role would only push teams onto shared
    // integration accounts. See ApiTokensController for the reasoning.
    Route::get('developer/tokens', [ApiTokensController::class, 'index'])->name('developer.tokens.index');
    Route::post('developer/tokens', [ApiTokensController::class, 'store'])->name('developer.tokens.store');
    Route::delete('developer/tokens/{token}', [ApiTokensController::class, 'destroy'])
        ->whereNumber('token')->name('developer.tokens.destroy');
    Route::get('developer/api', [ApiReferenceController::class, 'index'])->name('developer.api.index');

    // ------------------------------------------------- SSO — linked sign-ins (self-service)
    // No PermissionMiddleware: who may link *their own* account is their business, and an admin who
    // wants to see everyone's links has the identity rows in the database. Unlinking proves password
    // knowledge in the request itself when it would strand the account — see SsoController::unlink for
    // why that is not done with the `password.confirm` middleware.
    Route::get('sso/accounts', [SsoController::class, 'accounts'])->name('sso.accounts');
    Route::delete('sso/identities/{identity}', [SsoController::class, 'unlink'])->name('sso.identities.unlink');
});

/*
|------------------------------------------------------------------------------
| The SSO round trip — deliberately outside the 'auth' stack
|------------------------------------------------------------------------------
|
| These two are reached by signed-out browsers (that is the point) and by signed-in ones (when linking),
| so they carry 'web' only and decide per request which leg they are on. Registered whether or not the
| feature is switched on: the login page builds button URLs unconditionally, and a missing route name
| would be a 500 on the sign-in form of every installation that has SSO off — which is all of them until
| someone configures a client. Disabled providers answer 404 from the controller instead.
|
| The `throttle:15,1` is not about guessing anything — there is no secret on these URLs to guess, and the
| state is single-use. It bounds amplification: one unauthenticated hit to `/redirect` costs the provider
| one outbound HTTPS exchange plus one profile fetch, and `callback` costs the same, so without a limiter
| an anonymous visitor can point this installation's curl at an identity provider indefinitely. Fifteen a
| minute per IP is far above any human signing in — including a person who loses the battle with their IdP
| and starts over four times — and far below what a scripted flood needs to matter.
|
*/
Route::middleware('web')->prefix('core/sso')->name('core.sso.')->group(function (): void {
    Route::get('{provider}/redirect', [SsoController::class, 'redirect'])
        ->where('provider', '[a-z\-]+')->middleware('throttle:15,1')->name('redirect');
    Route::get('{provider}/callback', [SsoController::class, 'callback'])
        ->where('provider', '[a-z\-]+')->middleware('throttle:15,1')->name('callback');
});
