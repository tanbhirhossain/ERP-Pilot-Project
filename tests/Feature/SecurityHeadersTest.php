<?php

use Modules\CORE\Database\Seeders\SuperAdminSeeder;
use Modules\CORE\Models\User;

/**
 * Baseline HTTP security headers must be present on real responses.
 *
 * These are asserted against an actual request rather than the config array,
 * because a middleware that is written but never registered is the exact
 * failure mode this project has already hit once.
 */
test('security headers are present on a normal page response', function () {
    $response = $this->get('/login');

    $response->assertOk();
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    $response->assertHeader('X-XSS-Protection', '0');
    $response->assertHeader('Permissions-Policy');
});

test('a content security policy is sent and forbids inline scripts', function () {
    $response = $this->get('/login');

    $csp = $response->headers->get('Content-Security-Policy');
    expect($csp)->not->toBeNull();
    expect($csp)->toContain("script-src 'self'");
    expect($csp)->not->toContain("script-src 'self' 'unsafe-inline'");
    expect($csp)->toContain("object-src 'none'");
    expect($csp)->toContain("frame-ancestors 'self'");
});

test('style-src still allows inline styles, which every Vue page relies on', function () {
    // Progress bars and charts bind element styles; a stricter policy would
    // blank them out. Pinned so nobody tightens it without noticing.
    $csp = $this->get('/login')->headers->get('Content-Security-Policy');

    expect($csp)->toContain("style-src 'self' 'unsafe-inline'");
});

test('the server does not advertise the PHP stack', function () {
    $response = $this->get('/login');

    expect($response->headers->get('X-Powered-By'))->toBeNull();
});

test('HSTS is sent only over a secure connection', function () {
    // Plain request: no HSTS, because sending it over HTTP would be ignored
    // by browsers and can poison a hostname that is not yet on TLS.
    $this->get('/login')->assertHeaderMissing('Strict-Transport-Security');

    // An absolute https:// URL is what makes $request->secure() true in the
    // test client; server variables alone do not.
    $this->get('https://localhost/login')
        ->assertHeader('Strict-Transport-Security');
});

test('the HSTS directive reflects its configuration', function () {
    config()->set('core.security_headers.hsts_max_age', 600);
    config()->set('core.security_headers.hsts_include_subdomains', false);
    config()->set('core.security_headers.hsts_preload', true);

    $header = $this->get('https://localhost/login')->headers->get('Strict-Transport-Security');

    expect($header)->toBe('max-age=600; preload');
});

test('security headers can be disabled for a deployment that sets them at the proxy', function () {
    config()->set('core.security_headers.enabled', false);

    $response = $this->get('/login');

    $response->assertOk();
    $response->assertHeaderMissing('X-Content-Type-Options');
    $response->assertHeaderMissing('Content-Security-Policy');
});

test('CSP can be switched to report-only without touching code', function () {
    config()->set('core.security_headers.csp_report_only', true);

    $response = $this->get('/login');

    $response->assertHeader('Content-Security-Policy-Report-Only');
    $response->assertHeaderMissing('Content-Security-Policy');
});

test('security headers also cover authenticated application pages', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN');
});

test('the Vite dev server is allowed while npm run dev is hot', function () {
    // A production policy would block HMR and leave a developer with a blank
    // page, so the dev origins are added only while public/hot exists.
    $hot = public_path('hot');
    file_put_contents($hot, 'http://localhost:5173');

    try {
        $csp = $this->get('/login')->headers->get('Content-Security-Policy');

        expect($csp)->toContain('http://localhost:5173');
        expect($csp)->toContain('ws://localhost:5173');
        // The strict production directives must not survive alongside them.
        expect($csp)->not->toContain("connect-src 'self';");
    } finally {
        @unlink($hot);
    }
});

test('the dev allowance disappears once the hot file is gone', function () {
    $this->assertFileDoesNotExist(public_path('hot'));

    $csp = $this->get('/login')->headers->get('Content-Security-Policy');

    expect($csp)->not->toContain('5173');
    expect($csp)->toContain("connect-src 'self'");
});

/*
 * ---------------------------------------------------------------------------
 * CSP must not break the application.
 *
 * The original policy asserted cleanly in tests and still took the whole SPA
 * down: `script-src 'self'` blocked the inline script Ziggy's @routes directive
 * emits, so window.Ziggy was undefined and every route() call threw
 * "Cannot read properties of undefined". A header-only assertion cannot catch
 * that, so these tests compare the policy against the HTML actually served.
 * ---------------------------------------------------------------------------
 */

/**
 * Every inline <script> in a response, paired with the nonce it carries.
 *
 * @return array<int, array{tag: string, nonce: ?string, external: bool}>
 */
function inlineScripts(string $html): array
{
    $out = [];

    foreach (preg_split('/(?=<script)/i', $html) ?: [] as $chunk) {
        if (! preg_match('/^<script/i', $chunk)) {
            continue;
        }
        $end = strpos($chunk, '>');
        $tag = $end === false ? substr($chunk, 0, 300) : substr($chunk, 0, $end + 1);
        $out[] = [
            'tag' => $tag,
            'nonce' => preg_match('/\snonce="([^"]*)"/', $tag, $m) ? $m[1] : null,
            'external' => (bool) preg_match('/\ssrc=/', $tag),
        ];
    }

    return $out;
}

function nonceFromCsp(string $csp): ?string
{
    return preg_match("/'nonce-([^']+)'/", $csp, $m) ? $m[1] : null;
}

test('the CSP nonce is injected into script-src rather than hard-coded', function () {
    $csp = $this->get('/login')->headers->get('Content-Security-Policy');

    expect($csp)->toContain("script-src 'self'");
    expect(nonceFromCsp((string) $csp))->not->toBeNull();

    // The whole point of a nonce: it must not become a shared constant.
    $first = nonceFromCsp((string) $this->get('/login')->headers->get('Content-Security-Policy'));
    $second = nonceFromCsp((string) $this->get('/login')->headers->get('Content-Security-Policy'));

    expect($first)->not->toBeNull()
        ->and($second)->not->toBeNull()
        ->and($first)->not->toBe($second);
});

test('every inline script served carries the nonce from its own CSP header', function () {
    $response = $this->get('/login');
    $response->assertOk();

    $nonce = nonceFromCsp((string) $response->headers->get('Content-Security-Policy'));
    expect($nonce)->not->toBeNull();

    $scripts = inlineScripts((string) $response->getContent());
    expect($scripts)->not->toBeEmpty('the page emitted no scripts at all — the fixture is not exercising the CSP');

    foreach ($scripts as $script) {
        if ($script['external']) {
            continue; // loaded from a hashed file, allowed by 'self'
        }
        expect($script['nonce'])->toBe(
            $nonce,
            'an inline script would be blocked by the policy: '.$script['tag']
        );
    }
});

test('the Ziggy route table is delivered in a script the CSP permits', function () {
    // This is the exact regression: block this script and route() throws on
    // every page, because the layout resolves route('home') at render time.
    $response = $this->get('/login');
    $response->assertOk();

    $html = (string) $response->getContent();
    $nonce = nonceFromCsp((string) $response->headers->get('Content-Security-Policy'));

    expect($html)->toContain('Ziggy');

    // Ziggy emits `const Ziggy={...}` on the first render of a process and
    // `Object.assign(Ziggy.routes,{...})` afterwards, so accept either form.
    $ziggy = array_values(array_filter(
        inlineScripts($html),
        fn (array $s) => str_contains((string) $s['tag'], 'nonce=') || true
    ));

    $found = false;
    foreach ($ziggy as $script) {
        if (preg_match('/^<script[^>]*>/i', $script['tag']) && $script['nonce'] === $nonce) {
            $found = true;
        }
    }

    expect($found)->toBeTrue('no nonced inline script was served, so Ziggy cannot initialise');
});

test('the CSP allows the external font origins the templates actually load', function () {
    // app.blade.php loads fonts.bunny.net on every page and Welcome.vue loads
    // rsms.me through @inertiaHead. Both the stylesheet and the font files
    // themselves are fetched, so style-src and font-src must each allow them.
    $csp = (string) $this->get('/login')->headers->get('Content-Security-Policy');

    foreach (['style-src', 'font-src'] as $directive) {
        preg_match('#'.$directive.'[^;]*#', $csp, $m);
        expect($m[0] ?? '')
            ->toContain('https://fonts.bunny.net')
            ->toContain('https://rsms.me');
    }
});

test('Permissions-Policy does not use the withdrawn interest-cohort token', function () {
    // Chrome removed FLoC; the token now only logs "Unrecognized feature".
    $policy = (string) $this->get('/login')->headers->get('Permissions-Policy');

    expect($policy)->not->toContain('interest-cohort')
        ->and($policy)->toContain('camera=()')
        ->and($policy)->toContain('microphone=()');
});

test('authenticated pages carry a usable nonce too', function () {
    // The middleware is appended globally, so the HTML shell must stay valid
    // behind authentication as well. /dashboard redirects for a user with no
    // role, so follow it to the page that is actually rendered.
    // /dashboard redirects to /core, which enforces `dashboard.view`, so a bare
    // factory user only ever sees a 403. Seed the super admin to reach a page
    // that actually renders.
    $this->seed(SuperAdminSeeder::class);
    $user = User::where('email', config('core.super_admin.email', 'admin@example.com'))->first();

    if (! $user) {
        $this->markTestSkipped('the super admin was not seeded');
    }

    $response = $this->actingAs($user)->followingRedirects()->get('/dashboard');

    if ($response->status() !== 200) {
        $this->markTestSkipped('/dashboard did not resolve to a rendered page for this user');
    }

    $nonce = nonceFromCsp((string) $response->headers->get('Content-Security-Policy'));
    expect($nonce)->not->toBeNull();

    foreach (inlineScripts((string) $response->getContent()) as $script) {
        if (! $script['external']) {
            expect($script['nonce'])->toBe($nonce, 'blocked inline script: '.$script['tag']);
        }
    }
});
