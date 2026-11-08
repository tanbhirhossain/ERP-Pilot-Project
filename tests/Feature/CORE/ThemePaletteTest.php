<?php

/**
 * The colour-scheme contract — item 42.
 *
 * Three files have to agree for a scheme switch to work, and they are written in three languages:
 * `resources/css/themes.css` (the token sets), `Modules\CORE\Support\UiTheme` (the catalogue and the
 * resolver) and `resources/views/app.blade.php` + `useUiTheme.ts` (what reaches the browser before
 * the first frame). Nothing in the application can notice a disagreement between them at runtime —
 * a palette defined in CSS but absent from PHP simply cannot be chosen, and a token PHP promises but
 * CSS never defines silently falls back to whatever the unscoped default was. Both look like "the
 * theme didn't apply", which is the one bug report a colour feature always gets.
 *
 * So the tests here are mostly cross-file agreements, plus the resolver's precedence, which is the
 * only behaviour with a decision in it: a user's own choice beats the company default, an absent or
 * invalid choice does not, and a preference is not a privilege — reading it needs no permission.
 *
 * Helpers are prefixed `tp` because every Feature file loads into one process.
 */

use App\Models\User as AppUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\CORE\Models\CoreSetting;
use Modules\CORE\Models\User;
use Modules\CORE\Support\UiTheme;

uses(RefreshDatabase::class);

const TP_THEMES = 'resources/css/themes.css';
const TP_APP = 'resources/css/app.css';
const TP_BLADE = 'resources/views/app.blade.php';
const TP_THEME_TS = 'modules/CORE/Resources/js/Composables/useUiTheme.ts';

/**
 * The twenty tokens every palette must define. A palette that omits one inherits the unscoped
 * default, which is how a "complete" scheme ends up half-indigo under Amber Copper.
 */
const TP_TOKENS = [
    '--primary',
    '--primary-foreground',
    '--ring',
    '--hero-from',
    '--hero-via',
    '--hero-to',
    '--hero-ink',
    '--hero-glow',
    '--brand-tint',
    '--brand-tint-ink',
    '--sidebar-primary',
    '--sidebar-primary-foreground',
    '--sidebar-ring',
    '--sidebar-accent',
    '--sidebar-accent-foreground',
    '--chart-1',
    '--chart-2',
    '--chart-3',
    '--chart-4',
    '--chart-5',
];

/**
 * The semantic status colours. Defined once per mode and never per palette: green has to mean the
 * same thing under every scheme, or a badge changes meaning when somebody redecorates.
 */
const TP_STATUS_TOKENS = ['--success', '--warning', '--danger', '--info', '--neutral-ink'];

function tpRead(string $path): string
{
    $contents = file_get_contents(base_path($path));

    expect($contents)->not->toBeFalse("cannot read $path");

    return (string) $contents;
}

/**
 * Every top-level rule as [selector, body], with comments removed.
 *
 * @return list<array{selector: string, body: string}>
 */
function tpRules(string $css): array
{
    $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);

    $rules = [];
    $length = strlen($css);
    $cursor = 0;
    $selectorStart = 0;

    while ($cursor < $length) {
        if ($css[$cursor] === ';') {
            // An at-statement such as `@import './themes.css';` ends here. Without this the text of
            // every import would be glued to the next selector, and `@theme inline` would not be
            // found under its own name.
            $cursor++;
            $selectorStart = $cursor;

            continue;
        }

        if ($css[$cursor] !== '{') {
            $cursor++;

            continue;
        }

        $selector = trim(substr($css, $selectorStart, $cursor - $selectorStart));
        $depth = 1;
        $bodyStart = $cursor + 1;
        $cursor = $bodyStart;

        while ($cursor < $length && $depth > 0) {
            if ($css[$cursor] === '{') {
                $depth++;
            } elseif ($css[$cursor] === '}') {
                $depth--;
            }

            $cursor++;
        }

        $rules[] = [
            'selector' => $selector,
            'body' => substr($css, $bodyStart, max(0, $cursor - $bodyStart - 1)),
        ];

        $selectorStart = $cursor;
    }

    return $rules;
}

/** The body of the first rule whose selector list contains exactly this selector. */
function tpBlock(string $css, string $selector): string
{
    foreach (tpRules($css) as $rule) {
        $parts = array_map('trim', explode(',', $rule['selector']));

        if (in_array($selector, $parts, true)) {
            return $rule['body'];
        }
    }

    return '';
}

/** Every palette id that themes.css paints, light and dark. */
function tpCssSchemes(string $css): array
{
    $ids = [];

    foreach (tpRules($css) as $rule) {
        if (preg_match_all("/data-theme='([\w-]+)'/", $rule['selector'], $matches)) {
            foreach ($matches[1] as $id) {
                $ids[$id] = true;
            }
        }
    }

    return array_keys($ids);
}

beforeEach(function () {
    // The resolver memoises the company default per process; without this a test that sets it
    // leaks into every test after it.
    UiTheme::flush();
});

test('the catalogue is a set of complete, choosable schemes', function () {
    $schemes = UiTheme::SCHEMES;

    expect($schemes)->toHaveCount(8, 'a curated set of six to eight palettes was the agreed scope');

    // toHaveKey() takes no message argument — the second parameter is not an explanation — so the
    // assertion that needs to say something goes through PHPUnit.
    $this->assertArrayHasKey(UiTheme::DEFAULT_SCHEME, $schemes, 'the default scheme must be one of the offered schemes');

    foreach ($schemes as $id => $scheme) {
        expect($scheme['id'])->toBe($id, 'the array key and the id disagree, so a stored value cannot be looked up');
        expect($scheme['label'])->not->toBe('');
        expect($scheme['description'])->not->toBe('', "$id has no description; the picker shows one under each chip");
        expect($scheme['swatch'])->toMatch('/^#[0-9a-f]{6}$/i', "$id's swatch is not a six-digit hex colour");
    }

    // The picker and the header switcher are built from this list, so a duplicate label is a
    // duplicate-looking button with different behaviour.
    expect(array_unique(array_column($schemes, 'label')))->toHaveCount(count($schemes));
});

test('every scheme has a light block and a dark block', function () {
    $css = tpRead(TP_THEMES);

    foreach (UiTheme::schemeIds() as $id) {
        $light = tpBlock($css, ":root[data-theme='$id']");
        $dark = tpBlock($css, ".dark[data-theme='$id']");

        expect($light)->not->toBe('', "$id has no :root[data-theme] block, so choosing it in light mode does nothing");
        expect($dark)->not->toBe('', "$id has no .dark[data-theme] block, so dark mode falls back to another palette");
    }
});

test('every palette block defines every token, in both modes', function () {
    $css = tpRead(TP_THEMES);

    foreach (UiTheme::schemeIds() as $id) {
        foreach ([":root[data-theme='$id']" => 'light', ".dark[data-theme='$id']" => 'dark'] as $selector => $mode) {
            $body = tpBlock($css, $selector);

            foreach (TP_TOKENS as $token) {
                // The colon matters: `--primary` would otherwise pass on `--primary-foreground`.
                $this->assertStringContainsString(
                    $token.':',
                    $body,
                    "$id ($mode) does not define $token, so that token keeps its unscoped default under this scheme"
                );
            }
        }
    }
});

test('no palette exists in one layer only', function () {
    $css = tpRead(TP_THEMES);

    $inCss = tpCssSchemes($css);
    $inPhp = UiTheme::schemeIds();

    sort($inCss);
    sort($inPhp);

    // A CSS palette with no PHP id cannot be chosen; a PHP id with no CSS block paints nothing.
    expect($inCss)->toBe($inPhp);
});

test('every token is mapped to a utility an author can write', function () {
    $app = tpRead(TP_APP);

    $theme = tpBlock($app, '@theme inline');

    expect($theme)->not->toBe('', 'app.css has no @theme inline block, so Tailwind mints no token utilities at all');

    foreach ([...TP_TOKENS, ...TP_STATUS_TOKENS] as $token) {
        $name = ltrim($token, '-');

        $this->assertStringContainsString(
            "--color-$name: hsl(var($token))",
            $theme,
            "$token is defined but not mapped into @theme inline, so bg-$name / text-$name do not exist"
        );
    }
});

test('the status colours belong to the mode, not to the palette', function () {
    $css = tpRead(TP_THEMES);

    foreach ([':root', '.dark'] as $selector) {
        $body = tpBlock($css, $selector);

        expect($body)->not->toBe('', "themes.css has no $selector block for the mode-level tokens");

        foreach (TP_STATUS_TOKENS as $token) {
            $this->assertStringContainsString($token.':', $body, "$selector does not define $token");
        }
    }

    foreach (tpRules($css) as $rule) {
        if (! str_contains($rule['selector'], 'data-theme')) {
            continue;
        }

        foreach (TP_STATUS_TOKENS as $token) {
            $this->assertStringNotContainsString(
                $token.':',
                $rule['body'],
                "{$rule['selector']} redefines $token, so the meaning of that colour now depends on the palette"
            );
        }
    }
});

test('a user choice beats the company default, and the company default beats the constant', function () {
    // Nothing stored anywhere: the compiled-in default.
    $resolved = UiTheme::resolve(null);

    expect($resolved['scheme'])->toBe(UiTheme::DEFAULT_SCHEME)
        ->and($resolved['mode'])->toBe(UiTheme::DEFAULT_MODE)
        ->and($resolved['source'])->toBe('default')
        ->and($resolved['dark'])->toBeFalse();

    // An administrator sets the house scheme.
    UiTheme::setCompany('teal', 'dark');
    UiTheme::flush();

    $resolved = UiTheme::resolve(null);

    expect($resolved['scheme'])->toBe('teal')
        ->and($resolved['mode'])->toBe('dark')
        ->and($resolved['source'])->toBe('company')
        ->and($resolved['dark'])->toBeTrue('a dark company default must reach the shell as .dark, not as a client-side guess');

    // A user who disagrees overrides it for themselves only.
    $chooser = User::factory()->create(['ui_scheme' => 'rose', 'ui_mode' => 'light']);

    $resolved = UiTheme::resolve($chooser->fresh());

    expect($resolved['scheme'])->toBe('rose')
        ->and($resolved['mode'])->toBe('light')
        ->and($resolved['source'])->toBe('user')
        ->and($resolved['company_scheme'])->toBe('teal', 'the page has to be able to offer "follow the company default" again');

    // Half an opinion is still an opinion: the mode is the user's, the scheme falls through.
    $partial = User::factory()->create(['ui_scheme' => null, 'ui_mode' => 'dark']);

    $resolved = UiTheme::resolve($partial->fresh());

    expect($resolved['scheme'])->toBe('teal')
        ->and($resolved['mode'])->toBe('dark')
        ->and($resolved['source'])->toBe('user');
});

test('a stored value that is not a scheme is treated as no opinion', function () {
    // A palette removed in a later release, or a hand-edited row, must not blank the interface.
    $user = User::factory()->create(['ui_scheme' => 'chartreuse', 'ui_mode' => 'midnight']);

    $resolved = UiTheme::resolve($user->fresh());

    expect($resolved['scheme'])->toBe(UiTheme::DEFAULT_SCHEME)
        ->and($resolved['mode'])->toBe(UiTheme::DEFAULT_MODE)
        ->and($resolved['user_scheme'])->toBeNull()
        ->and($resolved['user_mode'])->toBeNull()
        ->and($resolved['source'])->toBe('default');
});

test('the company default refuses an unknown scheme and writes nothing while refusing', function () {
    UiTheme::setCompany('emerald', 'light');
    UiTheme::flush();

    $before = CoreSetting::query()->get()->mapWithKeys(fn (CoreSetting $row) => [$row->key => $row->value])->all();

    expect(fn () => UiTheme::setCompany('chartreuse', 'light'))->toThrow(DomainException::class)
        ->and(fn () => UiTheme::setCompany('emerald', 'midnight'))->toThrow(DomainException::class);

    UiTheme::flush();

    expect(CoreSetting::query()->get()->mapWithKeys(fn (CoreSetting $row) => [$row->key => $row->value])->all())
        ->toBe($before, 'a rejected write changed the stored company default');

    expect(UiTheme::resolve(null)['scheme'])->toBe('emerald');
});

test('the shell paints the stored scheme before the first frame', function () {
    $blade = tpRead(TP_BLADE);

    // Server-rendered, so the very first pixel is the right palette.
    $this->assertStringContainsString('data-theme="{{ $ui[\'scheme\'] }}"', $blade, 'the shell does not render the resolved scheme onto <html>');
    $this->assertStringContainsString("'dark' => \$ui['dark']", $blade, 'the shell does not render the resolved mode as the dark class');

    // And the local override is settled before paint, inside a nonce'd script: the CSP this
    // application ships has no 'unsafe-inline' for scripts, so an un-nonced bootstrap is a
    // bootstrap that never runs, which shows up as a flash of the wrong palette.
    $this->assertStringContainsString('nonce="{{ $cspNonce }}"', $blade, 'the pre-paint script is not nonced, so the CSP blocks it');
    $this->assertStringContainsString('prefers-color-scheme: dark', $blade, 'the bootstrap cannot settle `system` mode before paint');
    $this->assertStringContainsString('localStorage', $blade, 'the bootstrap ignores the local override, so a reload flashes the company scheme');
});

test('the browser and the server read the same storage key', function () {
    $blade = tpRead(TP_BLADE);
    $composable = tpRead(TP_THEME_TS);

    // Two literals in two languages that must not drift: if they do, the pre-paint bootstrap reads
    // nothing and every reload flashes the default palette before Vue corrects it.
    expect($blade)->toContain("localStorage.getItem('core.ui.v1')")
        ->and($composable)->toContain("export const UI_STORAGE_KEY = 'core.ui.v1';");
});

test('the resolver accepts any authenticatable user, not only the module subclass', function () {
    // Two classes extend the framework's authenticatable here: `Modules\CORE\Models\User`, which the
    // auth provider resolves at runtime, and the starter kit's `App\Models\User`, which the kit's own
    // auth tests still sign in with. Hinting the subclass turned every Inertia response for the
    // parent into a 500 TypeError — the logout test failed on an assertGuest() that never ran,
    // because the request died before the redirect.
    $parent = AppUser::factory()->create();

    $resolved = UiTheme::resolve($parent);

    expect($resolved['scheme'])->toBe(UiTheme::DEFAULT_SCHEME)
        ->and($resolved['can_manage_company'])->toBeFalse('a user without Spatie roles holds no permissions, and asking must not throw')
        ->and($resolved['user_scheme'])->toBeNull('the parent model has no stored preference, which must read as "no opinion"');

    // And the trimmed shape shared on every request accepts it too, since that is the call site
    // that actually threw.
    expect(UiTheme::share($parent)['schemes'])->toHaveCount(8);
});
