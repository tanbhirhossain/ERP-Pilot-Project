<?php

/**
 * Every typeahead endpoint a page points at must actually work.
 *
 * This exists because all of the ERP's pickers broke at once and nothing caught
 * it. Three independent defects stacked up, each invisible to the tests that
 * did exist:
 *
 *   1. `SearchableSelect` read only `{items:[…]}`, but PARTNER, INVENTORY,
 *      SALES and ACCOUNTS all return `{data:[…]}` — so the list was always
 *      empty and the UI just said "No matching results".
 *   2. 21 call sites passed `api-url=` instead of the real `endpoint` prop. The
 *      attribute fell through to the root element, so those selectors had no
 *      endpoint at all and searched an empty static array.
 *   3. `PartnerRepository::options()` returned the partner *code* as `value`,
 *      while every consuming form validates `exists:partners,id`.
 *
 * A unit test on the component cannot see any of this: the failures live in the
 * seam between a Vue attribute, a route table and a repository mapping. So this
 * walks the seam — it reads the URLs out of the templates and requests them.
 */

use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\UrlGenerationException;
use Illuminate\Support\Facades\Route;
use Modules\CORE\Database\Seeders\SuperAdminSeeder;
use Modules\CORE\Models\User;
use Modules\INVENTORY\Models\Product;
use Modules\PARTNER\Models\Partner;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Envelope keys SearchableSelect's unwrapOptions() accepts. */
const OPTION_ENVELOPE_KEYS = ['data', 'items', 'options', 'results'];

/**
 * Every `endpoint="…"` / `api-url="…"` literal in the Vue templates.
 *
 * @return array<string, list<string>> url => files that reference it
 */
function selectorEndpoints(): array
{
    $found = [];

    $dirs = array_filter([base_path('modules'), base_path('resources/js')], 'is_dir');

    foreach ($dirs as $dir) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));

        foreach ($files as $file) {
            /** @var SplFileInfo $file */
            if ($file->getExtension() !== 'vue' || str_contains($file->getPathname(), 'node_modules')) {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());

            if (! preg_match_all('/(?:endpoint|api-url)="(\/[^"]+)"/', $contents, $matches)) {
                continue;
            }

            foreach ($matches[1] as $url) {
                $found[$url][] = str_replace(base_path().'/', '', $file->getPathname());
            }
        }
    }

    ksort($found);

    return $found;
}

function adminUser(): User
{
    test()->seed(SuperAdminSeeder::class);

    $admin = User::where('email', config('core.super_admin.email', 'admin@example.com'))->first();

    expect($admin)->not->toBeNull('the super admin was not seeded, so no endpoint can be authorised');

    return $admin;
}

beforeEach(function () {
    $this->actingAs(adminUser());
});

test('the templates reference at least one selector endpoint', function () {
    // Guards the guard: if the scan silently finds nothing (a renamed prop, a
    // moved directory) every assertion below would pass vacuously.
    expect(selectorEndpoints())->not->toBeEmpty();
});

test('no selector passes api-url, which is not a prop of SearchableSelect', function () {
    // The typo class from defect #2. `api-url` is not declared, so Vue drops it
    // on the root element and the component never learns it has an endpoint.
    $offenders = [];

    foreach (selectorEndpoints() as $url => $files) {
        foreach ($files as $file) {
            $contents = (string) file_get_contents(base_path($file));

            if (preg_match_all('/<SearchableSelect\b[^>]*>/s', $contents, $tags)) {
                foreach ($tags[0] as $tag) {
                    if (str_contains($tag, 'api-url=')) {
                        $offenders[] = $file.' → '.$url;
                    }
                }
            }
        }
    }

    expect($offenders)->toBe([], "SearchableSelect has no `api-url` prop; use `endpoint`:\n".implode("\n", $offenders));
});

test('no selector passes :error, which is not a prop of SearchableSelect', function () {
    // Validation state is `invalid` (boolean). `:error` was silently ignored, so
    // server-side validation failures never highlighted the field.
    $offenders = [];

    foreach (selectorEndpoints() as $url => $files) {
        foreach ($files as $file) {
            $contents = (string) file_get_contents(base_path($file));

            if (! preg_match_all('/<SearchableSelect\b[^>]*>/s', $contents, $tags)) {
                continue;
            }

            foreach ($tags[0] as $tag) {
                if (preg_match('/(?<![:\w])error=|:error=/', $tag)) {
                    $offenders[] = $file.' → '.$url;
                }
            }
        }
    }

    expect($offenders)->toBe([], "use `:invalid` rather than `:error`:\n".implode("\n", $offenders));
});

test('every selector endpoint resolves to a registered route', function () {
    $unrouted = [];

    foreach (array_keys(selectorEndpoints()) as $url) {
        $request = Request::create($url, 'GET');

        try {
            Route::getRoutes()->match($request);
        } catch (UrlGenerationException $e) {
            $unrouted[] = $url;
        } catch (NotFoundHttpException $e) {
            $unrouted[] = $url;
        }
    }

    expect($unrouted)->toBe([], "these endpoints are referenced by a page but no route serves them:\n".implode("\n", $unrouted));
});

test('every selector endpoint answers 200 for an authorised user', function () {
    $failures = [];

    foreach (selectorEndpoints() as $url => $files) {
        $status = $this->getJson($url.'?search=')->status();

        if ($status !== 200) {
            $failures[] = sprintf('%s → HTTP %d (used by %s)', $url, $status, implode(', ', $files));
        }
    }

    expect($failures)->toBe([], "selector endpoints did not answer 200:\n".implode("\n", $failures));
});

test('every selector endpoint returns an envelope the component can read', function () {
    // Defect #1. A 200 carrying `{rows:[…]}` is exactly as broken as a 404 from
    // the user's point of view: the picker renders "No matching results".
    $unreadable = [];

    foreach (array_keys(selectorEndpoints()) as $url) {
        $payload = $this->getJson($url.'?search=')->json();

        if (is_array($payload) && array_is_list($payload)) {
            continue; // bare array — supported
        }

        if (! is_array($payload)) {
            $unreadable[] = $url.' → not JSON';

            continue;
        }

        $hasRows = false;

        foreach (OPTION_ENVELOPE_KEYS as $key) {
            if (array_key_exists($key, $payload)) {
                $hasRows = true;
                break;
            }
        }

        if (! $hasRows) {
            $unreadable[] = sprintf('%s → keys [%s]', $url, implode(',', array_keys($payload)));
        }
    }

    expect($unreadable)->toBe([], 'envelope is not one SearchableSelect reads ('.implode('|', OPTION_ENVELOPE_KEYS)."):\n".implode("\n", $unreadable));
});

test('partner options submit the id, which is what the forms validate against', function () {
    // Defect #3, pinned directly. Every partner-consuming request class
    // validates `exists:partners,id`, so a code in `value` cannot be submitted.
    // `partners` has no status column; activity is the `is_active` flag and the
    // repository's active() scope reads it.
    $partner = Partner::factory()->create([
        'type' => 'vendor',
        'is_active' => true,
    ]);

    foreach (['/partner/api/options/vendors', '/partner/api/options/customers'] as $url) {
        $rows = $this->getJson($url.'?search=')->json('data') ?? $this->getJson($url.'?search=')->json('items') ?? [];

        foreach ($rows as $row) {
            expect($row['value'])->toBeInt(
                "$url returned a non-integer value ({$row['value']}); forms validate exists:partners,id"
            );
        }
    }

    // And the created partner must be reachable by that integer value.
    $rows = $this->getJson('/partner/api/options/vendors?search='.urlencode($partner->name))->json('data') ?? [];
    $values = array_column($rows, 'value');

    if ($rows !== []) {
        expect($values)->toContain($partner->id);
        expect($values)->not->toContain($partner->partner_code);
    }
});

test('searching narrows the result set rather than being ignored', function () {
    // A `search` parameter the endpoint quietly drops looks identical to a
    // working picker until the list is long enough to matter.
    Partner::factory()->create([
        'name' => 'Zzqunique Vendor Name',
        'type' => 'vendor',
        'is_active' => true,
    ]);

    $all = $this->getJson('/partner/api/options/vendors?search=')->json('data') ?? [];
    $hit = $this->getJson('/partner/api/options/vendors?search=Zzqunique')->json('data') ?? [];

    expect($hit)->not->toBeEmpty('an exact search term returned nothing');
    expect(count($hit))->toBeLessThan(max(count($all), count($hit) + 1));
    expect(array_column($hit, 'label')[0])->toContain('Zzqunique');
});

test('the POS product lookup still resolves an exact sku and barcode', function () {
    // Separate endpoint from the picker: exact match on id, sku or barcode, used
    // by the barcode scanner path in Pos/Terminal.vue.
    // status is a CHECK-constrained lowercase enum; the factory already defaults
    // to 'active', so pass nothing rather than a value the schema rejects.
    $product = Product::factory()->create();

    foreach ([$product->id, $product->sku, $product->barcode] as $key) {
        if ($key === null || $key === '') {
            continue;
        }

        $payload = $this->getJson('/inventory/api/pos-product/'.urlencode((string) $key))->json();

        expect($payload['found'] ?? false)->toBeTrue("POS lookup failed for key: {$key}");
        expect($payload['data']['id'])->toBe($product->id);
    }
});
