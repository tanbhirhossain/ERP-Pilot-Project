<?php

/*
 * P1.4 — the published-credential guard.
 *
 * The repository ships a known-answer key to itself: config('core.super_admin') defaults to
 * admin@example.com / password, .env.example sets CORE_SUPER_ADMIN_PASSWORD=password,
 * SuperAdminSeeder writes that pair and gives it the `*` wildcard permission, and CoreSeeder writes
 * Hash::make('password') for three named demo users plus up to 200 factory users whose factory
 * default is the same string. There was no runtime check — the only defence was a hardened
 * .env.production.example a deployment had to remember to copy.
 *
 * These tests pin both halves of the fix: nothing published may be *written* in production, and
 * nothing published may be *used* to sign in in production. They also pin the two things that must
 * keep working, because a guard that locks people out is worse than no guard: a real password on the
 * published email address, and the published pair outside production (which the whole existing test
 * suite and every local install depend on).
 *
 * The guard reads config('app.env'), not the container's 'env' binding — see pcgEnvironment() for
 * why that distinction is the difference between these tests passing and failing on a CSRF 419.
 */

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Modules\CORE\Database\Seeders\CoreSeeder;
use Modules\CORE\Database\Seeders\SuperAdminSeeder;
use Modules\CORE\Models\User;
use Modules\CORE\Support\PublishedCredentialGuard;

/**
 * Switch the environment the guard judges.
 *
 * Deliberately config-only. Rebinding the container's 'env' key — which is what
 * `Application::detectEnvironment()` writes — also flips `runningUnitTests()` to false, and
 * `VerifyCsrfToken` uses that to decide whether to exempt test requests. Every login post in this
 * file then dies with a 419 and no flashed errors, so the refusal tests fail for the wrong reason.
 * `PublishedCredentialGuard::environment()` reads `config('app.env')`, which is the same APP_ENV
 * value in a real deployment.
 */
function pcgEnvironment(string $env): void
{
    config(['app.env' => $env]);
}

/** Seed the super admin *before* switching to production — the seeder now refuses to run there. */
function pcgSeedSuperAdmin(): User
{
    test()->seed(SuperAdminSeeder::class);

    $admin = User::query()->where('email', config('core.super_admin.email', 'admin@example.com'))->first();

    expect($admin)->not->toBeNull('the super admin seeder did not create its account');

    return $admin;
}

function pcgRealPassword(): string
{
    return 'correct-horse-battery-staple-41';
}

/** Log in and return the response, so each test can assert on status, session and auth together. */
function pcgAttemptLogin(string $email, string $password): TestResponse
{
    return test()->post('/login', ['email' => $email, 'password' => $password]);
}

test('the credential pair this repository publishes is refused in production', function () {
    $admin = pcgSeedSuperAdmin();
    pcgEnvironment('production');

    // Sanity: the credentials really do match, so the refusal below is the guard and not a typo.
    expect(Hash::check('password', $admin->password))->toBeTrue();

    $response = pcgAttemptLogin($admin->email, 'password');

    $response->assertSessionHasErrors('email');
    $this->assertGuest();

    // And it is still refused on a second, direct request rather than only on the first one.
    $this->get('/dashboard')->assertRedirect('/login');
});

test('the refusal tells the operator which variable to set', function () {
    $admin = pcgSeedSuperAdmin();
    pcgEnvironment('production');

    $response = pcgAttemptLogin($admin->email, 'password');

    $message = session('errors')?->first('email') ?? '';

    expect($message)->toContain('APP_ENV=production')
        ->and($message)->toContain('CORE_SUPER_ADMIN_PASSWORD')
        ->and($message)->not->toContain('These credentials do not match our records');
});

test('the refusal is logged at critical level without recording the password', function () {
    $admin = pcgSeedSuperAdmin();
    pcgEnvironment('production');

    Log::spy();

    pcgAttemptLogin($admin->email, 'password');

    Log::shouldHaveReceived('critical')->once()->withArgs(function (string $message, array $context) use ($admin) {
        return str_contains($message, 'published')
            && ($context['email'] ?? null) === $admin->email
            && ! in_array('password', array_values($context), true);
    });
});

test('a real password on the published email address still works in production', function () {
    $admin = pcgSeedSuperAdmin();

    $admin->forceFill(['password' => Hash::make(pcgRealPassword())])->save();

    pcgEnvironment('production');

    pcgAttemptLogin($admin->email, pcgRealPassword())->assertSessionHasNoErrors();
    $this->assertAuthenticated();
});

test('the published pair still works outside production', function (string $env) {
    $admin = pcgSeedSuperAdmin();
    pcgEnvironment($env);

    pcgAttemptLogin($admin->email, 'password')->assertSessionHasNoErrors();
    $this->assertAuthenticated();
})->with(['local', 'testing', 'staging']);

test('the guard can be switched off deliberately', function () {
    $admin = pcgSeedSuperAdmin();
    pcgEnvironment('production');
    config(['core.security.block_published_credentials' => false]);

    pcgAttemptLogin($admin->email, 'password')->assertSessionHasNoErrors();
    $this->assertAuthenticated();
});

test('the guard covers every account holding a published password, not only the super admin', function () {
    // A manager whose password happens to be the published string.
    $manager = User::query()->create([
        'name' => 'Published Password Manager',
        'email' => 'manager.published@example.test',
        'password' => Hash::make('password'),
        'email_verified_at' => now(),
    ]);

    pcgEnvironment('production');

    pcgAttemptLogin($manager->email, 'password')->assertSessionHasErrors('email');
    $this->assertGuest();

    expect(PublishedCredentialGuard::isPublishedEmail($manager->email))->toBeFalse()
        ->and(PublishedCredentialGuard::isPublishedPassword('password'))->toBeTrue();
});

test('the super admin seeder refuses to write a published password in production', function () {
    pcgEnvironment('production');
    config(['core.super_admin.password' => 'password']);

    expect(fn () => app(SuperAdminSeeder::class)->run())
        ->toThrow(RuntimeException::class, 'published in this repository');

    expect(User::query()->where('email', config('core.super_admin.email'))->exists())->toBeFalse()
        ->and('the seeder must fail closed, not half-create the account');
});

test('the super admin seeder runs in production once a real password is configured', function () {
    pcgEnvironment('production');
    config([
        'core.super_admin.email' => 'root@deployment.test',
        'core.super_admin.password' => pcgRealPassword(),
    ]);

    app(SuperAdminSeeder::class)->run();

    $admin = User::query()->where('email', 'root@deployment.test')->first();

    expect($admin)->not->toBeNull()
        ->and(Hash::check(pcgRealPassword(), $admin->password))->toBeTrue();

    // And that account can actually sign in, so hardening does not lock the deployment out.
    pcgAttemptLogin('root@deployment.test', pcgRealPassword())->assertSessionHasNoErrors();
    $this->assertAuthenticated();
});

test('the core seeder does not write the published password in production', function () {
    pcgEnvironment('production');

    // CoreSeeder tops the user table up to 200 accounts. Checking each one against bcrypt at the
    // default cost would take longer than the rest of the suite, and the cost is irrelevant to what
    // is being asserted here — only the plaintext matters.
    config(['hashing.bcrypt.rounds' => 4, 'core.super_admin.password' => pcgRealPassword()]);

    app(CoreSeeder::class)->run();

    $demo = User::query()->where('email', 'nadia@example.com')->first();

    expect($demo)->not->toBeNull('CoreSeeder did not create its demo accounts')
        ->and(Hash::check('password', $demo->password))->toBeFalse('a demo account still holds the published password')
        ->and(Hash::check(pcgRealPassword(), $demo->password))->toBeFalse('demo accounts must not share the super admin password either');

    // The bulk factory loop is held to the same rule: no seeded production account may accept the
    // published password, whatever its email address turns out to be.
    $seeded = User::query()->get();

    // CoreSeeder tops the table up to 200 accounts; if that loop did not run, iterating over
    // $seeded below would prove nothing about the factory path.
    expect($seeded->count())->toBeGreaterThanOrEqual(200);

    foreach ($seeded as $user) {
        expect(Hash::check('password', (string) $user->password))
            ->toBeFalse("{$user->email} was seeded with the published password in production");
    }
});

test('the same seeding outside production keeps the documented developer credentials', function () {
    app(CoreSeeder::class)->run();

    $demo = User::query()->where('email', 'nadia@example.com')->first();

    expect($demo)->not->toBeNull()
        ->and(Hash::check('password', $demo->password))->toBeTrue();
});

test('no seeder writes the published password any more', function () {
    $root = dirname(__DIR__, 3);
    expect(is_dir($root.'/modules'))->toBeTrue('the scan root is wrong, so this test would pass vacuously');

    $files = array_merge(
        glob($root.'/modules/*/Database/Seeders/*.php') ?: [],
        glob($root.'/database/seeders/*.php') ?: [],
    );

    expect($files)->not->toBeEmpty('the seeder glob matched nothing');

    $offenders = [];

    foreach ($files as $file) {
        $source = (string) file_get_contents($file);

        if (str_contains($source, "Hash::make('password')") || str_contains($source, "bcrypt('password')")) {
            $offenders[] = str_replace($root.'/', '', $file);
        }
    }

    // database/factories/UserFactory.php still defaults to Hash::make('password'), deliberately: it
    // is a test and local-development convenience. What must not happen is a *seeder* writing it,
    // because seeders run on live deployments. CoreSeeder's bulk factory loop overrides the default
    // and is pinned behaviourally by the production-seeding test above.
    expect($offenders)->toBe([]);
});
