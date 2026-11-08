<?php

/**
 * Report item 45, P1.6 — the expensive HRM endpoints are rate limited, and the limits are per person.
 *
 * The audit recorded "no rate limiting on device sync or bulk roster operations". Login was never the
 * gap: `LoginRequest::ensureIsNotRateLimited()` has throttled the login form at five attempts since
 * the starter kit. What had nothing on it were the seven HRM endpoints whose cost is out of proportion
 * to a single click — a device sync walks a terminal's attendance log over the network, `sync-all`
 * does that to every terminal at once, and a bulk roster write expands into one duty row per employee
 * per day. All seven were already behind `PermissionMiddleware`, which is why this is P1 and not P0:
 * the caller has to be an authenticated HRM user. But permission is not a budget. One user with
 * `hrm.attendance.sync` could hold `sync-all` open in a loop and starve every other device, and
 * nothing in the stack would notice, because every request was authorised.
 *
 * Two named limiters, registered in `AppServiceProvider::boot()` rather than as an anonymous
 * `throttle:6,1` on each route, so the number and the key are stated once and the routes only name
 * what they are:
 *
 *  - `hrm-device-sync` — 6 per minute, keyed on the user **and the device** where the route names one,
 *    because one terminal's backlog is not another's and a user syncing five devices legitimately
 *    should not spend one shared bucket doing it.
 *  - `hrm-bulk-write` — 12 per minute, keyed on the user, for the roster endpoints that generate rows.
 *
 * Neither limiter is keyed on the IP address alone. An office behind one NAT address shares a source
 * IP, and keying on it would mean the twelfth person to click Sync is told to wait for the first — a
 * rate limit that punishes the org chart instead of the caller. The IP is the *fallback* for a request
 * with no authenticated user, which none of these routes can currently produce: it exists so that if
 * one is ever exposed, the key degrades to per-address rather than to nothing.
 *
 * Test 2 is the one that keeps this fixed. Tests 1, 3, 4 and 5 pin the seven routes that are throttled
 * today; test 2 pins the rule, by walking every HRM POST route whose URI says sync or bulk and
 * demanding a limiter on it, so the eighth endpoint somebody adds next quarter arrives throttled or
 * fails the suite.
 *
 * Helpers are prefixed `srl`; Pest loads every Feature file into one process.
 */

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\RateLimiter;
use Modules\CORE\Database\Seeders\SuperAdminSeeder;
use Modules\CORE\Models\User;
use Modules\HRM\Database\Seeders\HRMPermissionSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/** The seven endpoints, by URI, and the limiter each one must name. */
const SRL_TARGETS = [
    'hrm/devices/{device}/sync-users' => 'throttle:hrm-device-sync',
    'hrm/overtimes/sync-attendance' => 'throttle:hrm-device-sync',
    'hrm/attendance/sync/{device}' => 'throttle:hrm-device-sync',
    'hrm/attendance/sync-all' => 'throttle:hrm-device-sync',
    'hrm/attendance/process-pending' => 'throttle:hrm-device-sync',
    'hrm/duties/bulk-week' => 'throttle:hrm-bulk-write',
    'hrm/duties/bulk' => 'throttle:hrm-bulk-write',
];

/** The cheap endpoint in the 6/minute bucket, used for the behavioural tests. */
const SRL_PROBE_URI = 'hrm/attendance/process-pending';
const SRL_PROBE_LIMIT = 6;

function srlAdmin(): User
{
    test()->seed([SuperAdminSeeder::class, HRMPermissionSeeder::class]);

    $admin = User::where('email', config('core.super_admin.email', 'admin@example.com'))->first();
    expect($admin)->not->toBeNull('SuperAdminSeeder produced no admin user');

    test()->actingAs($admin);

    return $admin;
}

/** A second HRM user, so a test can prove the buckets are per person and not per process or per IP. */
function srlSecondUser(string $email = 'srl.second@example.com'): User
{
    $role = Role::firstOrCreate(['name' => 'SRL Sync Operator', 'guard_name' => 'web']);
    $permission = Permission::where('name', 'hrm.attendance.sync')->first();
    expect($permission)->not->toBeNull('HRMPermissionSeeder did not create hrm.attendance.sync');
    $role->givePermissionTo($permission);

    $user = User::create([
        'name' => 'SRL Second Operator',
        'email' => $email,
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
    ]);
    $user->assignRole($role);

    return $user;
}

/** Every registered HRM route, as [uri, method, middleware[]] triples. */
function srlHrmRoutes(): array
{
    $out = [];
    foreach (app('router')->getRoutes() as $route) {
        /** @var Route $route */
        if (! str_starts_with($route->uri(), 'hrm/')) {
            continue;
        }
        $out[] = [$route->uri(), implode('|', $route->methods()), $route->gatherMiddleware()];
    }

    return $out;
}

function srlMiddlewareFor(string $uri): array
{
    foreach (srlHrmRoutes() as [$routeUri, $methods, $middleware]) {
        if ($routeUri === $uri) {
            return $middleware;
        }
    }
    test()->fail("no HRM route registered at {$uri}");
}

// ─────────────────────────────────────────────────────────────────────────────
// The wiring
// ─────────────────────────────────────────────────────────────────────────────

test('the seven expensive HRM endpoints each name their limiter', function () {
    srlAdmin();

    foreach (SRL_TARGETS as $uri => $expected) {
        $middleware = srlMiddlewareFor($uri);

        $this->assertContains(
            $expected,
            $middleware,
            "{$uri} is not behind {$expected} — it has: ".implode(', ', $middleware)
        );
    }
});

test('no HRM POST route that syncs or bulk-writes is left unthrottled', function () {
    srlAdmin();

    // The forward-looking half: a new endpoint whose URI says sync or bulk arrives throttled or fails
    // here. `process-pending` is named explicitly because it is a bulk operation whose URI says
    // neither word. The GET sync screen and the PUT settings route are deliberately out of scope —
    // they are a page and a single-row write, and throttling them would only annoy the person using
    // the module.
    $unthrottled = [];
    foreach (srlHrmRoutes() as [$uri, $methods, $middleware]) {
        $isWrite = str_contains($methods, 'POST');
        $isExpensive = preg_match('#(sync|bulk|process-pending)#', $uri) === 1;
        if (! $isWrite || ! $isExpensive) {
            continue;
        }
        $throttled = array_filter($middleware, fn ($m) => str_starts_with((string) $m, 'throttle:'));
        if ($throttled === []) {
            $unthrottled[] = $uri;
        }
    }

    $this->assertSame([], $unthrottled, 'HRM write routes with no rate limiter: '.implode(', ', $unthrottled));
});

test('both limiters are registered with the published budget and a per-person key', function () {
    $admin = srlAdmin();
    $second = srlSecondUser();

    $sync = RateLimiter::limiter('hrm-device-sync');
    $bulk = RateLimiter::limiter('hrm-bulk-write');
    expect($sync)->not->toBeNull('hrm-device-sync is not registered')
        ->and($bulk)->not->toBeNull('hrm-bulk-write is not registered');

    $request = Request::create('/'.SRL_PROBE_URI, 'POST');
    $request->setUserResolver(fn () => $admin);
    $syncLimit = $sync($request);
    $bulkLimit = $bulk($request);

    expect($syncLimit)->toBeInstanceOf(Limit::class)
        ->and($syncLimit->maxAttempts)->toBe(6)
        ->and($bulkLimit->maxAttempts)->toBe(12);

    // Per person, not per address: the same IP, two users, two keys.
    $secondRequest = Request::create('/'.SRL_PROBE_URI, 'POST');
    $secondRequest->setUserResolver(fn () => $second);
    $this->assertNotSame($syncLimit->key, $sync($secondRequest)->key, 'the sync bucket is shared between users');
    $this->assertNotSame($bulkLimit->key, $bulk($secondRequest)->key, 'the bulk bucket is shared between users');
    $this->assertStringContainsString((string) $admin->getAuthIdentifier(), $syncLimit->key);
});

// ─────────────────────────────────────────────────────────────────────────────
// The behaviour
// ─────────────────────────────────────────────────────────────────────────────

test('the device-sync bucket refuses the seventh request inside a minute', function () {
    srlAdmin();

    for ($i = 1; $i <= SRL_PROBE_LIMIT; $i++) {
        $response = $this->post('/'.SRL_PROBE_URI, []);

        // Whatever the endpoint does with an empty payload — redirect, validation error, or a no-op
        // success — it must not be a throttle response inside the budget.
        $this->assertNotSame(429, $response->getStatusCode(), "request {$i} of ".SRL_PROBE_LIMIT.' was throttled');
    }

    $refused = $this->post('/'.SRL_PROBE_URI, []);

    $this->assertSame(429, $refused->getStatusCode(), 'the seventh request inside the minute was not refused');
    $this->assertNotEmpty($refused->headers->get('Retry-After'), 'a 429 with no Retry-After tells the caller nothing');
    $this->assertMatchesRegularExpression('/^\d+$/', (string) $refused->headers->get('Retry-After'));
});

test('one operator exhausting the bucket does not throttle the next one', function () {
    $admin = srlAdmin();

    for ($i = 0; $i <= SRL_PROBE_LIMIT; $i++) {
        $this->post('/'.SRL_PROBE_URI, []);
    }
    $this->assertSame(429, $this->post('/'.SRL_PROBE_URI, [])->getStatusCode(), 'the first operator is not throttled');

    // Same process, same test database, same source address — a different person, a different bucket.
    // This is the assertion that an IP-keyed limiter would fail, and the reason the office behind one
    // NAT address is not told to wait for whoever clicked Sync first.
    $second = srlSecondUser();
    test()->actingAs($second);

    $this->assertNotSame(
        429,
        $this->post('/'.SRL_PROBE_URI, [])->getStatusCode(),
        'the second operator inherited the first operator\'s exhausted bucket'
    );
});
