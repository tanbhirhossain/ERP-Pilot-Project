<?php

use Illuminate\Support\Facades\Hash;
use Modules\CORE\Models\User;
use Modules\HRM\Models\Employee\Employee;
use Modules\HRM\Services\EmployeeLoginService;
use Modules\HRM\Services\EmployeeResignationService;
use Modules\HRM\Services\EmployeeService;

/**
 * Employee auto-provision login: creating an employee creates the login, the
 * first sign-in forces a password change, the login box speaks employee code
 * as well as email, and leavers lose everything — sessions, tokens, pushes.
 *
 * Helpers are `el*`-prefixed because Pest files share one function scope.
 */
uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

function elEmployee(array $overrides = []): Employee
{
    return Employee::query()->create(array_merge([
        'employee_id' => 'EL-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
        'first_name' => 'Login',
        'last_name' => 'Test',
        'full_name' => 'Login Test',
        'email' => 'login-'.uniqid().'@example.com',
        'employment_type' => 'Permanent',
        'status' => 'Active',
        'is_active' => 1,
        'joining_date' => '2020-01-01',
        'gender' => 'Male',
    ], $overrides));
}

function elService(): EmployeeService
{
    return app(EmployeeService::class);
}

function elLogins(): EmployeeLoginService
{
    return app(EmployeeLoginService::class);
}

function elProvisioned(array $overrides = []): array
{
    return elService()->createWithLogin(array_merge([
        'employee_id' => 'EL-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
        'first_name' => 'Login',
        'last_name' => 'Test',
        'full_name' => 'Login Test',
        'email' => 'provisioned-'.uniqid().'@example.com',
        'employment_type' => 'Permanent',
    ], $overrides));
}

test('creating an employee provisions exactly one linked user with a forced temporary password', function () {
    $usersBefore = User::count();

    $result = elProvisioned();

    expect(User::count())->toBe($usersBefore + 1)
        ->and($result['tempPassword'])->toBeString()
        ->and($result['loginCreated'])->toBeTrue();

    $user = User::find($result['employee']->fresh()->user_id);

    expect($user)->not->toBeNull()
        ->and($user->email)->toBe($result['employee']->email)
        ->and((bool) $user->force_password_change)->toBeTrue()
        ->and($user->provisioned_employee_code)->toBe($result['employee']->employee_id)
        ->and(Hash::check($result['tempPassword'], (string) $user->password))->toBeTrue();
});

test('an employee without an email still gets a login on a placeholder address', function () {
    $result = elProvisioned(['email' => null, 'employee_id' => 'EL-NOMAIL']);

    expect($result['loginCreated'])->toBeTrue();

    $user = User::find($result['employee']->fresh()->user_id);

    expect($user->email)->toBe('el-nomail@employees.local');
});

test('a hand-picked user is linked, never duplicated or touched', function () {
    $admin = User::factory()->create(['password' => Hash::make('AdminPass123')]);
    $usersBefore = User::count();

    $result = elProvisioned(['user_id' => $admin->id]);

    expect(User::count())->toBe($usersBefore)
        ->and($result['tempPassword'])->toBeNull()
        ->and($result['employee']->fresh()->user_id)->toBe($admin->id)
        ->and((bool) $admin->fresh()->force_password_change)->toBeFalse()
        ->and(Hash::check('AdminPass123', (string) $admin->fresh()->password))->toBeTrue();
});

test('the web login accepts the employee code and forces the password change', function () {
    $result = elProvisioned();
    $code = $result['employee']->employee_id;

    $this->post('/login', ['email' => $code, 'password' => $result['tempPassword']])
        ->assertRedirect(route('password.edit', absolute: false));

    $this->assertAuthenticated();

    // Anywhere else bounces back to the change screen until it is done.
    $this->get(route('dashboard', absolute: false))->assertRedirect(route('password.edit', absolute: false));

    $this->put(route('password.update', absolute: false), [
        'current_password' => $result['tempPassword'],
        'password' => 'NewSecurePass123',
        'password_confirmation' => 'NewSecurePass123',
    ])->assertSessionHasNoErrors();

    expect((bool) User::find($result['employee']->user_id)->force_password_change)->toBeFalse();

    // The change screen no longer traps them: the dashboard may still refuse a
    // role-less account, but it must not bounce back to the password change.
    $dashboard = $this->get(route('dashboard', absolute: false));
    expect($dashboard->isRedirect(route('password.edit', absolute: false)))->toBeFalse();
    $this->assertAuthenticated();
});

test('the web login still accepts the email address', function () {
    $result = elProvisioned();

    $this->post('/login', ['email' => $result['employee']->email, 'password' => $result['tempPassword']])
        ->assertRedirect(route('password.edit', absolute: false));

    $this->assertAuthenticated();
});

test('mobile login speaks code, flags the forced change, and gates every endpoint until it is done', function () {
    $result = elProvisioned();
    $code = $result['employee']->employee_id;

    $login = $this->postJson('/api/v1/auth/login', [
        'email' => strtolower($code),
        'password' => $result['tempPassword'],
        'device_name' => 'test-phone',
    ])->assertOk();

    expect($login->json('data.user.must_change_password'))->toBeTrue();

    $token = $login->json('data.token');

    $this->getJson('/api/v1/auth/profile', ['Authorization' => 'Bearer '.$token])
        ->assertForbidden()
        ->assertJsonPath('errors.code', 'PASSWORD_CHANGE_REQUIRED');

    $this->postJson('/api/v1/auth/change-password', [
        'current_password' => 'wrong-temp',
        'password' => 'NewSecurePass123',
        'password_confirmation' => 'NewSecurePass123',
    ], ['Authorization' => 'Bearer '.$token])->assertStatus(422);

    $this->postJson('/api/v1/auth/change-password', [
        'current_password' => $result['tempPassword'],
        'password' => 'NewSecurePass123',
        'password_confirmation' => 'NewSecurePass123',
    ], ['Authorization' => 'Bearer '.$token])
        ->assertOk()
        ->assertJsonPath('success', true);

    $this->getJson('/api/v1/auth/profile', ['Authorization' => 'Bearer '.$token])
        ->assertOk()
        ->assertJsonPath('data.must_change_password', false);
});

test('the F13 token exchange accepts the employee code', function () {
    $result = elProvisioned();

    $this->postJson('/api/v1/auth/token', [
        'email' => $result['employee']->employee_id,
        'password' => $result['tempPassword'],
        'device_name' => 'integration',
    ])->assertCreated();
});

test('an approved resignation with an arrived last day kills access everywhere', function () {
    $result = elProvisioned();
    $employee = $result['employee'];
    $user = User::find($employee->user_id);

    $token = $this->postJson('/api/v1/auth/login', [
        'email' => $employee->email,
        'password' => $result['tempPassword'],
    ])->json('data.token');

    expect($user->tokens()->count())->toBe(1);

    $resignation = app(EmployeeResignationService::class)->request([
        'employee_id' => $employee->employee_id,
        'last_working_date' => now()->toDateString(),
        'reason' => 'Leaving',
    ], null);
    app(EmployeeResignationService::class)->decide($resignation, 'Approved', null);

    expect($user->tokens()->count())->toBe(0);

    $this->postJson('/api/v1/auth/login', ['email' => $employee->email, 'password' => $result['tempPassword']])
        ->assertUnauthorized();

    $this->getJson('/api/v1/auth/profile', ['Authorization' => 'Bearer '.$token])
        ->assertUnauthorized();

    $this->post('/login', ['email' => $employee->email, 'password' => $result['tempPassword']])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('a future-dated resignation keeps the login working through the notice period', function () {
    $result = elProvisioned();
    $employee = $result['employee'];

    $resignation = app(EmployeeResignationService::class)->request([
        'employee_id' => $employee->employee_id,
        'last_working_date' => now()->addMonths(2)->toDateString(),
        'reason' => 'Later',
    ], null);
    app(EmployeeResignationService::class)->decide($resignation, 'Approved', null);

    // Authenticating at all proves the lockout did not fire: the force flag
    // answers with a redirect, a leaver with a session error.
    $this->post('/login', ['email' => $employee->employee_id, 'password' => $result['tempPassword']])
        ->assertRedirect(route('password.edit', absolute: false));

    $this->assertAuthenticated();
});

test('terminating the employee revokes access at once', function () {
    $result = elProvisioned();
    $employee = $result['employee'];
    $user = User::find($employee->user_id);
    $user->createToken('mobile', ['mobile']);

    elService()->update($employee, ['status' => 'Terminated']);

    expect($user->tokens()->count())->toBe(0);

    $this->postJson('/api/v1/auth/login', ['email' => $employee->email, 'password' => $result['tempPassword']])
        ->assertUnauthorized();
});

test('deleting the employee deletes the provisioned user but never a hand-linked one', function () {
    $provisioned = elProvisioned();
    $userId = $provisioned['employee']->fresh()->user_id;

    elService()->delete($provisioned['employee']->fresh());

    expect(User::find($userId))->toBeNull();

    $admin = User::factory()->create();
    $linked = elProvisioned(['user_id' => $admin->id]);

    elService()->delete($linked['employee']->fresh());

    expect(User::find($admin->id))->not->toBeNull();
});

test('the backfill command creates missing logins and the reset rotates one', function () {
    $missing = elEmployee();

    $this->artisan('hrm:employee-logins', ['--backfill' => true])->assertExitCode(0);

    $userId = $missing->fresh()->user_id;

    expect($userId)->not->toBeNull()
        ->and((bool) User::find($userId)->force_password_change)->toBeTrue();

    $user = User::find($userId);
    $user->createToken('mobile', ['mobile']);

    $this->artisan('hrm:employee-logins', ['--reset' => $missing->employee_id])->assertExitCode(0);

    expect($user->tokens()->count())->toBe(0)
        ->and((bool) $user->fresh()->force_password_change)->toBeTrue();
});

test('the leaver sweep revokes tokens for last days that arrived after approval', function () {
    $result = elProvisioned();
    $employee = $result['employee'];
    $user = User::find($employee->user_id);
    $user->createToken('mobile', ['mobile']);

    // Legacy label-only leaver: no resignation row, status set by hand.
    $employee->update(['status' => 'Resigned']);

    $this->artisan('hrm:employee-logins', ['--revoke-leavers' => true])->assertExitCode(0);

    expect($user->tokens()->count())->toBe(0);
});

test('the default password is amz at birth-year when the birth date is on file', function () {
    $result = elProvisioned(['date_of_birth' => '1990-05-17']);

    expect($result['tempPassword'])->toBe('amz@1990');

    // It really is the password: the login box accepts it and still forces the change.
    $this->post('/login', ['email' => $result['employee']->employee_id, 'password' => 'amz@1990'])
        ->assertRedirect(route('password.edit', absolute: false));

    $this->assertAuthenticated();

    // An HR reset hands back the same memorable default, not a new random one.
    expect(elLogins()->resetLogin($result['employee']->fresh()))->toBe('amz@1990');
});

test('without a birth date the temporary password stays random', function () {
    $result = elProvisioned();

    expect($result['tempPassword'])->toBeString()
        ->and($result['tempPassword'])->not->toStartWith('amz@')
        ->and(strlen((string) $result['tempPassword']))->toBeGreaterThanOrEqual(8);

    $user = User::find($result['employee']->fresh()->user_id);

    expect(Hash::check((string) $result['tempPassword'], (string) $user->password))->toBeTrue();
});
