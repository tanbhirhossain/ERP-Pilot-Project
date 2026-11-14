<?php

use Illuminate\Support\Facades\Route;
use Modules\CORE\Http\Controllers\Api\AuthTokenController;
use Modules\CORE\Http\Controllers\OpenApiController;

/*
|--------------------------------------------------------------------------
| F13 — the machine-facing surface, versioned
|--------------------------------------------------------------------------
|
| `/api/v1` in the path rather than an `Accept` header, because an integrator
| debugging with curl must be able to see which contract they are on, and a support
| ticket that quotes a URL should not need a second guess.
|
| The documentation lives on the controller methods (`#[ApiEndpoint]`) and the
| authorisation lives here, and `php artisan api:spec` reads both: the published spec
| copies permission and ability strings out of this route table, so it can only ever
| describe what is actually enforced. The one endpoint you can reach without a token
| is the one whose job is to hand you a token.
*/

// Unauthenticated by definition, so it carries the tight limiter and nothing else:
// five attempts a minute per address, whether or not they succeed.
Route::prefix('api/v1')->name('api.v1.')->middleware(['api', 'throttle:token-issue'])->group(function () {
    Route::post('auth/token', [AuthTokenController::class, 'issue'])->name('auth.token');

    Route::get('openapi.json', OpenApiController::class)->name('spec');
});

Route::prefix('api/v1')->name('api.v1.')->middleware(['api', 'auth:sanctum'])->group(function () {
    // No ability gate on either of these: "who am I" and "throw my token away" must
    // work for the narrowest token that exists, or a caller cannot diagnose why its
    // wider calls are being refused.
    Route::get('auth/me', [AuthTokenController::class, 'me'])->name('auth.me');

    Route::delete('auth/token', [AuthTokenController::class, 'revoke'])->name('auth.token.revoke');
});
