<?php

/**
 * F22 / U21 — the installable shell: manifest, worker, icons, offline page, and the one header
 * contract that ties the till's queue to chrome it is not allowed to import.
 *
 * Two kinds of assertion live here, and it is worth being exact about which is which.
 *
 * The *manifest* and *icons* are checked by parsing them, because nothing in a PHPUnit run ever asks
 * a browser to install anything: validity, the keys Chrome requires, and files that exist and are the
 * size they claim to be are all fully decidable from the artefacts themselves. An icon entry pointing
 * at a 404, or a `sizes` that lies, installs as a blank tile and nobody tests it.
 *
 * The *worker* is checked by reading its source, which proves the text and not the behaviour — no test
 * here runs a service worker. That is still the assertion worth having, because the properties it pins
 * are prohibitions: the sync POST must be outside the cache, and no page but the two POS screens may
 * have its HTML kept. A worker that caches an authenticated Inertia page caches that page's embedded
 * `data-page` JSON, i.e. a customer list on a shared tablet.
 *
 * The CSRF tag is behavioural: it is rendered and read back. Five components put
 * `meta[name="csrf-token"]` into `X-CSRF-TOKEN` and none of them, before this slice, had anything to
 * read — which was invisible only because Laravel skips CSRF verification under testing.
 */

// Not App\Models\User: that class does not carry Spatie's HasRoles, so every `can()` check it makes is
// false and every permissioned page answers 403 to a user who really does hold the grant.
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
// Not App\Models\User: that class does not carry Spatie's HasRoles, so every `can()` check it makes
// is false and every permissioned page answers 403 to a user who really does hold the grant.
use Modules\CORE\Models\User;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    config(['hashing.bcrypt.rounds' => 4]);
});

/**
 * Read a file that lives under `public/` (or anywhere else in the project) from the project root.
 *
 * `base_path()` on Laravel's filesystem manager resolves a relative path against the *public*
 * directory, not the project root — so `base_path('pwa/icon-192.png')` is `public/pwa/...`. That is
 * the correct reading of a manifest `src` and of an app URL alike, and it is why this helper takes
 * project-relative names with the `public/` prefix spelled out: `$absolute` below is built by hand so
 * both meanings stay legible in the same file.
 */
function pwa(string $path): string
{
    $absolute = dirname(__DIR__, 3).'/'.$path;
    expect(File::exists($absolute))->toBeTrue("{$path} is missing from the repository");

    return file_get_contents($absolute);
}

/** @return array<string, mixed> */
function pwaManifest(): array
{
    $raw = pwa('public/manifest.webmanifest');
    $decoded = json_decode($raw, true);

    expect(is_array($decoded))->toBeTrue('public/manifest.webmanifest is not valid JSON — '.json_last_error_msg());

    return $decoded;
}

test('the manifest carries what a browser demands before it offers an install', function () {
    $manifest = pwaManifest();

    // Chrome's installability checklist, each one a silent "Add to Home screen" that does nothing.
    foreach (['name', 'short_name', 'start_url', 'scope', 'display', 'icons'] as $key) {
        $this->assertArrayHasKey($key, $manifest, "manifest is missing `{$key}`");
    }

    expect(strlen((string) $manifest['short_name']))->toBeLessThanOrEqual(12,
        'a short_name that long is truncated by the launcher anyway');

    expect($manifest['display'])->toBe('standalone')
        // The till, not the dashboard: this manifest exists so a counter can run as an app.
        ->and($manifest['start_url'])->toBe('/sales/pos/terminal')
        ->and($manifest['id'])->toBe('/sales/pos/terminal')
        // Scope is the whole origin on purpose — a cashier who is sent to a receipt or the day-end
        // report must stay in the installed window, not be bounced into a browser tab.
        ->and($manifest['scope'])->toBe('/');
});

test('every icon the manifest promises is a real png of the size it claims', function () {
    $manifest = pwaManifest();

    expect($manifest['icons'])->not->toBeEmpty();

    foreach ($manifest['icons'] as $icon) {
        expect($icon)->toHaveKey('src')->toHaveKey('sizes')->toHaveKey('type');

        // A manifest URL is resolved against the manifest, which sits at the origin root: `/pwa/x.png`
        // means `public/pwa/x.png`, never `public/public/...` and never a path that escapes the app.
        $src = (string) $icon['src'];

        expect(str_starts_with($src, '/pwa/'))->toBeTrue("{$src} is not under /pwa/, so the icon will not resolve");

        $absolute = dirname(__DIR__, 3).'/public/'.ltrim($src, '/');

        expect(File::exists($absolute))->toBeTrue("manifest points at {$src}, which does not exist");

        $bytes = file_get_contents($absolute);

        // An `image/png` that is not a PNG is what a truncated commit looks like.
        expect(substr($bytes, 0, 8))->toBe("\x89PNG\r\n\x1a\n")->and($icon['type'])->toBe('image/png');

        // IHDR: width and height are the first two big-endian uint32s after the chunk name.
        $size = unpack('Nwidth/Nheight', substr($bytes, 16, 8));
        [$claimed_w, $claimed_h] = array_map('intval', explode('x', strtolower((string) $icon['sizes'])));

        if ($claimed_w !== 0) {
            // `sizes` is what the launcher trusts; a lying entry installs a blurred or cropped tile.
            expect($size['width'])->toBe($claimed_w)->and($size['height'])->toBe($claimed_h);
        }

        expect($icon['purpose'] ?? 'any')->toBeIn(['any', 'maskable', 'any maskable']);
    }

    // A `maskable` entry needs the safe zone, which is why that file exists at all.
    $maskable = array_values(array_filter($manifest['icons'], static fn (array $i): bool => ($i['purpose'] ?? '') === 'maskable'));

    expect($maskable)->not->toBeEmpty('an icon set without a maskable entry gets white borders on Android');
});

test('the manifest shortcuts point at screens that exist and are reachable', function () {
    $manifest = pwaManifest();

    $admin = User::where('email', config('core.super_admin.email', 'admin@example.com'))->firstOrFail();

    expect($manifest['shortcuts'] ?? [])->toHaveCount(2);

    foreach ($manifest['shortcuts'] as $shortcut) {
        $url = (string) $shortcut['url'];

        expect($url)->toStartWith('/');
        $this->assertArrayHasKey('name', $shortcut, 'a shortcut with no name is offered by no launcher');

        // Not a raw path: the shortcut has to land on a screen the launcher's own icon is legit for,
        // and a renamed route should fail here rather than 404 on someone's home screen.
        $this->actingAs($admin)->get($url)->assertOk();
    }

    expect(array_column($manifest['shortcuts'], 'url'))->toBe([
        '/sales/pos/offline',
        '/sales/pos/reports/day-end',
    ]);
});

test('the worker never caches the sync, and never caches anyone else’s page', function () {
    $sw = pwa('public/sw.js');

    // The replay has to reach the server: the whole idempotency design is a lookup the cache would
    // answer for it, turning "we already have this sale" into a response the till believes blindly.
    expect($sw)->toContain("if (path.startsWith('/sales/pos/offline/sync')) return;");

    // Documents are cached for the two till screens and for nothing else, precisely because an
    // Inertia page response embeds the page's data: caching a page means caching a customer list.
    preg_match('/OFFLINE_PAGES\s*=\s*\[(.*?)\]/s', $sw, $m);
    $listed = array_values(array_filter(array_map(
        static fn (string $part): string => trim($part, " \t'\""),
        explode(',', $m[1] ?? '')
    )));

    expect($listed)->toBe(['/sales/pos/terminal', '/sales/pos/offline'])
        ->and($sw)->toContain('/offline.html')
        // A stale cached till is worse than no cache: the drawer list would be yesterday's.
        ->and($sw)->toMatch('/DOCUMENT_MAX_AGE_MS\s*=\s*12\s*\*\s*60\s*\*\s*60\s*\*\s*1000/');
});

test('the offline notice renders with nothing to load', function () {
    $html = pwa('public/offline.html');

    // The page shown when the network is gone must not need the network: no stylesheet, no font, no
    // bundle. Inline styles are the whole point of it.
    expect($html)->not->toMatch('/<link[^>]+rel=["\']stylesheet/i')
        ->and($html)->not->toMatch('/<script[^>]+src=/i')
        ->and($html)->toContain('<style')
        ->and($html)->toContain('/sales/pos/terminal')
        // Bilingual, because a counter in Chattogram reads both and this page appears at the worst moment.
        ->toMatch('/[ঀ-৿]/u');
});

test('the shell links the manifest and emits the csrf token every json post needs', function () {
    $blade = pwa('resources/views/app.blade.php');

    expect($blade)->toContain('csrf_token()')
        ->toContain("asset('manifest.webmanifest')")
        // asset(), not a bare string, so the shell still finds the manifest under a sub-directory
        // deploy — the same reason every other public asset in this project is referenced that way.
        ->and($blade)->toMatch('/rel="manifest" href="\{\{\s*asset\(/')
        ->toContain('theme-color');

    $html = $this->actingAs(User::where('email', config('core.super_admin.email', 'admin@example.com'))->firstOrFail())
        ->get(route('core.dashboard'))
        ->assertOk()
        ->getContent();

    // The rendered tag, not just the source line: DataTable, NotificationBell, BarcodePrint, the
    // roster bulk editor and the offline sync all read this, and none of them could before F22.
    expect($html)->toMatch('/<meta name="csrf-token" content="[A-Za-z0-9]{40}">/')
        ->and($html)->toMatch('/<link rel="manifest" href="[^"]*manifest\.webmanifest">/');

    // The token in the tag is the session's own — `assertSessionHas` cannot show it, because Laravel
    // disables CSRF verification while running tests, which is exactly how this stayed invisible.
    expect($html)->toContain(session()->token());
});

test('the app registers the worker only in production, from a module script', function () {
    $app = pwa('resources/js/app.ts');

    // An inline registration script would need a CSP nonce; a module is 'self'. And in development a
    // cached shell fights Vite's HMR, which is a bug nobody can diagnose.
    expect($app)->toContain("import.meta.env.PROD && 'serviceWorker' in navigator")
        ->and($app)->toContain('navigator.serviceWorker')
        ->and($app)->toMatch("/register\('\/sw\.js',\s*\{ scope: '\/' \}/")
        // A worker that fails to register must not look like a failed app.
        ->toContain('.catch(');

    expect(pwa('public/manifest.webmanifest'))->toContain('icons');
});

test('the queue and the header badge agree on the one thing they share', function () {
    $queue = pwa('modules/SALES/Resources/js/offline/queue.ts');
    $badge = pwa('modules/CORE/Resources/js/Components/OfflineBadge.vue');

    // Writer and reader are in different modules on purpose: CORE must not import SALES. The cost of
    // that decoupling is a contract in two places, so it is pinned in one test.
    preg_match("/PENDING_SIGNAL_KEY = '([^']+)'/", $queue, $writer);
    preg_match("/SIGNAL_KEY = '([^']+)'/", $badge, $reader);
    preg_match('/QUEUE_VERSION = (\d+)/', $queue, $writer_v);
    preg_match('/SIGNAL_VERSION = (\d+)/', $badge, $reader_v);

    expect($reader[1] ?? null)->toBe($writer[1] ?? null, 'the badge reads a different storage key than the queue writes')
        ->and($writer[1] ?? '')->toBe('sales.offline.pending.v1')
        ->and((int) ($reader_v[1] ?? 0))->toBe((int) ($writer_v[1] ?? -1), 'the badge and the queue disagree on the blob version');

    // The fields, both directions.
    foreach (['waiting', 'attention', 'value', 'v'] as $field) {
        expect($queue)->toContain($field)->and($badge)->toContain($field);
    }

    // The same-tab event, which `storage` does not cover.
    expect($queue)->toContain("dispatchEvent(new CustomEvent('sales.offline.signal'))")
        ->and($badge)->toContain("SIGNAL_EVENT = 'sales.offline.signal'");

    // And CORE really does not reach into SALES.
    expect($badge)->not->toContain('@sales/');
});

test('the icons are committed artefacts, not something a build step has to remember to produce', function () {
    foreach (['icon-192.png', 'icon-512.png', 'icon-512-maskable.png', 'apple-touch-icon.png'] as $name) {
        expect(File::exists(dirname(__DIR__, 3).'/public/pwa/'.$name))->toBeTrue("public/pwa/{$name} is missing");
    }

    // The generator is in the repo, not in somebody's scratch directory: a checkout must be able to
    // re-render these without archaeology, which is the only reason binary artefacts survive a redesign.
    expect(File::exists(base_path('tools/make-pwa-icons.py')))->toBeTrue('tools/make-pwa-icons.py is missing');
});
test('the queue desk is reachable from the menu it belongs to', function () {
    $menu = pwa('modules/CORE/Resources/js/Config/menu.ts');
    $admin = User::where('email', config('core.super_admin.email', 'admin@example.com'))->firstOrFail();

    // A page no one can navigate to is a page that does not exist. The leaf must name a route that
    // is registered, sit under the POS group, and ask for the same grant the till does — a stricter
    // one would hide the queue from the only person who ever needs to see it.
    expect($menu)->toMatch("/\{ key: 'sales-pos-offline', label: 'Offline POS Queue', route: 'sales\.pos\.offline', icon: CloudOff, permission: 'pos\.terminal\.sell' \}/");

    $route = Route::getRoutes()->getByName('sales.pos.offline');

    // Pest's `toContain`/`toBeTrue` do not take an explanation the way PHPUnit's assertions do, and
    // spatie's middleware is aliased to `permission` in this app, so the shape is spelled out here.
    expect($route)->not->toBeNull();
    expect(collect($route->gatherMiddleware())->contains(
        static fn (string $m): bool => str_contains($m, 'PermissionMiddleware') && str_ends_with($m, ':pos.terminal.sell')
    ))->toBeTrue();

    // The leaf's own shape is the contract: the label is a dictionary key the i18n coverage test
    // already checks, and a leaf whose `route` string matches no named route renders as inert chrome.
    $names = collect(Route::getRoutes()->getRoutes())->map->getName()->filter()->values();

    expect($names)->toContain('sales.pos.offline');

    // And the named route really renders the Inertia page, so the link the badge offers is not a 404.
    $html = $this->actingAs($admin)->get('/sales/pos/offline')->assertOk()->getContent();

    // The Inertia root's `data-page` attribute is the only thing the browser is given; read it the way a
    // browser does (decode the attribute, parse the JSON) instead of matching against an encoding that
    // htmlspecialchars may change. The component name is what maps to
    // modules/SALES/Resources/js/Pages/Pos/Offline.vue, and the four props are the page's whole
    // contract — if the controller stops sending `posted`, the desk renders an empty table happily.
    libxml_use_internal_errors(true);
    $doc = new DOMDocument;
    $doc->loadHTML('<?xml encoding="utf-8" ?>'.$html);
    $el = $doc->getElementById('app');

    expect($el)->not->toBeNull('the Inertia root is gone, so nothing in the shell can mount');

    $page = json_decode($el->getAttribute('data-page'), true, flags: JSON_THROW_ON_ERROR);

    expect($page['component'])->toBe('SALES::Pos/Offline')
        ->and($page['props']);

    foreach (['posted', 'postedToday', 'maxBatch', 'canSell'] as $prop) {
        $this->assertArrayHasKey($prop, $page['props']);
    }
});
