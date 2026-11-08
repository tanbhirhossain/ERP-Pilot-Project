<?php

use Modules\CORE\Models\User;

/**
 * Self-service registration is closed by default.
 *
 * An open `POST /register` used to create a User, fire `Registered` and log the
 * caller in with no role assigned — which in this codebase still reached HRM,
 * payroll and bank-account screens. Registration is therefore opt-in via
 * `core.auth.allow_registration` (env `CORE_ALLOW_REGISTRATION`), and these
 * tests pin both states so the default cannot silently reopen.
 */
test('registration is disabled by default', function () {
    expect(config('core.auth.allow_registration'))->toBeFalse();
});

test('registration screen is not reachable when disabled', function () {
    $this->get('/register')->assertNotFound();
});

test('registering is refused when disabled', function () {
    $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertNotFound();

    $this->assertGuest();
    expect(User::where('email', 'test@example.com')->exists())->toBeFalse();
});

test('the register route exists but is inert when disabled', function () {
    // Registered unconditionally on purpose, so flipping the config does not
    // require a route re-cache. The controller aborts 404 either way.
    expect(app('router')->getRoutes()->getByName('register'))->not->toBeNull();

    $this->get(route('register'))->assertNotFound();
});

test('registration screen can be rendered when explicitly enabled', function () {
    config()->set('core.auth.allow_registration', true);

    $this->get('/register')->assertOk();
});

test('new users can register when explicitly enabled', function () {
    config()->set('core.auth.allow_registration', true);

    $response = $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('a self-registered user receives no role unless one is configured', function () {
    config()->set('core.auth.allow_registration', true);
    config()->set('core.auth.default_registration_role', null);

    $this->post('/register', [
        'name' => 'No Role User',
        'email' => 'norole@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect();

    $user = User::where('email', 'norole@example.com')->firstOrFail();

    // No role means the permission middleware rejects every module route, so a
    // sign-up cannot reach HRM, payroll or accounts data.
    expect($user->getRoleNames())->toBeEmpty();
});

test('registration validation still applies when enabled', function () {
    config()->set('core.auth.allow_registration', true);

    $response = $this->post('/register', [
        'name' => 'Test User',
        'email' => 'not-an-email',
        'password' => 'password',
        'password_confirmation' => 'mismatched',
    ]);

    $response->assertSessionHasErrors(['email', 'password']);
    $this->assertGuest();
});
