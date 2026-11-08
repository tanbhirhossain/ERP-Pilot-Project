<?php

/**
 * The UI-settings endpoints — item 42.
 *
 * Two write endpoints with deliberately different gates, because they serve two different people:
 *
 *   - choosing for yourself needs only `auth`. A preference is not a privilege; gating it would
 *     leave a warehouse operator with whichever scheme an administrator happened to like.
 *   - setting the company default needs `settings.appearance.manage`, enforced in route middleware
 *     so the check happens before validation and a 403 is a 403 whatever the payload says.
 *
 * The precedence between them is the only real logic here, and it is the part that is easy to get
 * subtly wrong: a company default must reach everyone who has not chosen, must not reach anyone who
 * has, and `null` — not an empty string, not the default id — is how a user goes back to following
 * the company. The last of those is what makes "Reset to company default" possible at all.
 *
 * Helpers are prefixed `uis` because every Feature file loads into one process.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\CORE\Database\Seeders\CoreSeeder;
use Modules\CORE\Models\CoreSetting;
use Modules\CORE\Models\User;
use Modules\CORE\Support\UiTheme;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(CoreSeeder::class);

    // The resolver memoises the company default per process, so a test that sets one leaks into
    // every test after it unless the cache is dropped.
    UiTheme::flush();
});

/**
 * A signed-in user who can see the dashboard, and who may or may not hold the company permission.
 *
 * `dashboard.view` is granted because `core.dashboard` redirects without it, and a redirect makes
 * `Inertia::component()` throw "The response is not a view" instead of failing on the prop. The
 * counter is static: a fixed email collides the moment a test needs two people, and SQLite reports
 * that as a bare UNIQUE constraint failure with nothing to say which test caused it.
 */
function uisUser(bool $canManage = false): User
{
    static $sequence = 0;

    $sequence++;

    $role = Role::create(['name' => "UiSettings Role {$sequence}", 'guard_name' => 'web']);
    $role->givePermissionTo('dashboard.view');

    if ($canManage) {
        $role->givePermissionTo('settings.appearance.manage');
    }

    $user = User::create([
        'name' => "Ui Settings {$sequence}",
        'email' => "ui-settings-{$sequence}@example.test",
        'password' => bcrypt('not-the-default'),
        'email_verified_at' => now(),
    ]);

    $user->assignRole($role);

    return $user;
}

/** The `ui` prop from a request, or null if the response was not an Inertia page. */
function uisProp(User $user, string $route, string $key = 'ui'): mixed
{
    return test()->actingAs($user)->get(route($route))->viewData('page')['props'][$key] ?? null;
}

test('a guest is sent to the login page', function () {
    $this->get(route('core.settings.appearance'))->assertRedirect(route('login'));
});

test('the settings page renders the appearance screen with the whole catalogue', function () {
    $user = uisUser();

    $response = $this->actingAs($user)->get(route('core.settings.appearance'));

    $response->assertOk();

    expect($response->viewData('page')['component'])->toBe('CORE::Settings/Appearance');

    $ui = $response->viewData('page')['props']['ui'];

    expect($ui['scheme'])->toBe(UiTheme::DEFAULT_SCHEME)
        ->and($ui['mode'])->toBe(UiTheme::DEFAULT_MODE)
        ->and($ui['source'])->toBe('default')
        ->and($ui['schemes'])->toHaveCount(8)
        ->and($ui['modes'])->toBe(UiTheme::MODES)
        ->and($ui['can_manage_company'])->toBeFalse();

    // The page explains each palette under its chip, so the description has to arrive.
    foreach ($ui['schemes'] as $scheme) {
        $this->assertArrayHasKey('description', $scheme, 'the picker cannot explain a scheme it was not given a description for');
        $this->assertNotSame('', $scheme['description']);
    }
});

test('the shell shares only what the header switcher needs', function () {
    $ui = uisProp(uisUser(), 'core.dashboard');

    expect($ui)->not->toBeNull('the shell no longer shares the resolved theme, so the header switcher has nothing to render');

    // Shared on every request in the application, so it carries the chip and nothing else.
    expect(array_keys($ui['schemes'][0]))->toBe(['id', 'label', 'swatch'])
        ->and($ui['schemes'])->toHaveCount(8)
        ->and($ui)->toHaveKey('scheme')
        ->and($ui)->toHaveKey('mode')
        ->and($ui)->toHaveKey('dark');
});

test('choosing a scheme persists and is what comes back', function () {
    $user = uisUser();

    $this->actingAs($user)
        ->put(route('core.settings.appearance.update'), ['scheme' => 'rose'])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($user->fresh()->ui_scheme)->toBe('rose');

    $ui = uisProp($user, 'core.settings.appearance');

    expect($ui['scheme'])->toBe('rose')
        ->and($ui['source'])->toBe('user')
        ->and($ui['user_scheme'])->toBe('rose');
});

test('the mode is a separate choice from the scheme', function () {
    $user = uisUser();

    $this->actingAs($user)->put(route('core.settings.appearance.update'), ['mode' => 'dark'])->assertRedirect();

    $fresh = $user->fresh();

    expect($fresh->ui_mode)->toBe('dark')
        ->and($fresh->ui_scheme)->toBeNull('choosing a mode must not silently choose a scheme too');

    $ui = uisProp($user, 'core.settings.appearance');

    expect($ui['dark'])->toBeTrue()
        ->and($ui['mode'])->toBe('dark')
        ->and($ui['scheme'])->toBe(UiTheme::DEFAULT_SCHEME)
        ->and($ui['source'])->toBe('user');
});

test('a scheme or mode that does not exist is refused and nothing is stored', function () {
    $user = uisUser();

    $this->actingAs($user)
        ->put(route('core.settings.appearance.update'), ['scheme' => 'chartreuse'])
        ->assertSessionHasErrors('scheme');

    $this->actingAs($user)
        ->put(route('core.settings.appearance.update'), ['mode' => 'midnight'])
        ->assertSessionHasErrors('mode');

    $fresh = $user->fresh();

    expect($fresh->ui_scheme)->toBeNull()
        ->and($fresh->ui_mode)->toBeNull();

    // And the interface still resolves to something paintable.
    expect(uisProp($user, 'core.settings.appearance')['scheme'])->toBe(UiTheme::DEFAULT_SCHEME);
});

test('null clears the override and the company default shows through again', function () {
    UiTheme::setCompany('teal', 'dark');
    UiTheme::flush();

    $user = uisUser();
    $user->forceFill(['ui_scheme' => 'rose', 'ui_mode' => 'light'])->save();

    expect(uisProp($user, 'core.settings.appearance')['scheme'])->toBe('rose');

    // This is the "follow company default" button. An empty string would fail Rule::in and leave
    // the override in place, which is the difference between a reset and a validation error.
    $this->actingAs($user)
        ->put(route('core.settings.appearance.update'), ['scheme' => null, 'mode' => null])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $fresh = $user->fresh();

    expect($fresh->ui_scheme)->toBeNull()
        ->and($fresh->ui_mode)->toBeNull();

    $ui = uisProp($user, 'core.settings.appearance');

    expect($ui['scheme'])->toBe('teal')
        ->and($ui['mode'])->toBe('dark')
        ->and($ui['source'])->toBe('company')
        ->and($ui['company_scheme'])->toBe('teal', 'the page has to keep offering the company value so a chooser can see what they would get');
});

test('the company default is a permission, and refusing it writes nothing', function () {
    $operator = uisUser(canManage: false);

    $this->actingAs($operator)
        ->put(route('core.settings.appearance.company'), ['scheme' => 'teal', 'mode' => 'dark'])
        ->assertForbidden();

    expect(CoreSetting::query()->count())->toBe(0, 'a refused company write still reached the table');

    $administrator = uisUser(canManage: true);

    $this->actingAs($administrator)
        ->put(route('core.settings.appearance.company'), ['scheme' => 'teal', 'mode' => 'dark'])
        ->assertRedirect()
        ->assertSessionHas('success');

    UiTheme::flush();

    expect(CoreSetting::query()->where('key', UiTheme::COMPANY_SCHEME_KEY)->value('value'))->toBe('teal')
        ->and(CoreSetting::query()->where('key', UiTheme::COMPANY_MODE_KEY)->value('value'))->toBe('dark')
        ->and(UiTheme::resolve(null)['scheme'])->toBe('teal');
});

test('the company default reaches everyone who has not chosen, and nobody who has', function () {
    $administrator = uisUser(canManage: true);

    $this->actingAs($administrator)->put(route('core.settings.appearance.company'), ['scheme' => 'amber', 'mode' => 'light']);
    UiTheme::flush();

    $follower = uisUser();
    $chooser = uisUser();
    $chooser->forceFill(['ui_scheme' => 'violet'])->save();

    $followed = uisProp($follower, 'core.settings.appearance');
    $chosen = uisProp($chooser, 'core.settings.appearance');

    expect($followed['scheme'])->toBe('amber')
        ->and($followed['source'])->toBe('company')
        ->and($chosen['scheme'])->toBe('violet')
        ->and($chosen['source'])->toBe('user')
        ->and($chosen['company_scheme'])->toBe('amber');
});

test('the company endpoint requires both fields', function () {
    $administrator = uisUser(canManage: true);

    // A scheme with no mode would leave the house default half-set, and the shell would resolve the
    // mode from a constant nobody chose.
    $this->actingAs($administrator)
        ->put(route('core.settings.appearance.company'), ['scheme' => 'teal'])
        ->assertSessionHasErrors('mode');

    $this->actingAs($administrator)
        ->put(route('core.settings.appearance.company'), ['mode' => 'dark'])
        ->assertSessionHasErrors('scheme');

    expect(CoreSetting::query()->count())->toBe(0);
});

test('the screen is reachable from the menu and the gate is declared, not implied', function () {
    $menu = (string) file_get_contents(base_path('modules/CORE/Resources/js/Config/menu.ts'));

    // Finding 45 was ten menu entries pointing at `#`; reachability is asserted, not assumed.
    $this->assertStringContainsString("key: 'ui-settings'", $menu, 'the UI settings entry is gone from the navigation');
    $this->assertStringContainsString("route: 'core.settings.appearance'", $menu, 'the menu entry no longer points at the settings screen');

    $route = app('router')->getRoutes()->getByName('core.settings.appearance.company');

    expect($route)->not->toBeNull('the company endpoint has no route');

    $middleware = implode('|', $route->gatherMiddleware());

    $this->assertStringContainsString('settings.appearance.manage', $middleware, 'the company endpoint is not gated in route middleware, so the check depends on the controller remembering');

    // A middleware string naming a permission nobody seeded is a 403 for everyone, forever.
    expect(Permission::query()->where('name', 'settings.appearance.manage')->exists())
        ->toBeTrue('the gate names a permission CoreSeeder does not create');
});

test('the shell header carries the quick switcher and a way to reach the full screen', function () {
    $layout = (string) file_get_contents(base_path('modules/CORE/Resources/js/Layouts/AdminLayout.vue'));

    $this->assertStringContainsString('useUiTheme', $layout, 'the shell no longer reads the resolved theme');
    $this->assertStringContainsString('setScheme(', $layout, 'the header switcher cannot change the scheme');
    $this->assertStringContainsString("route('core.settings.appearance')", $layout, 'there is no link from the shell to the full settings screen');
});
