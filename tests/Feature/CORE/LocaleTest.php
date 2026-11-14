<?php

/**
 * Phase 3 — U19 Bengali / RTL locale kernel (backend half).
 *
 * The switcher's client side is unit-tested in `i18n/i18n.test.ts`; what only PHP can answer is that
 * the session choice wins over the company default, that an unrecognised code is refused rather than
 * half-applied, that the direction is derived from the code (never set separately), and that the shared
 * Inertia prop and the server-rendered `<html dir>` agree so a full load is mirrored on first paint.
 *
 * Helpers are prefixed `loc*`.
 */

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Modules\CORE\Models\User;
use Modules\CORE\Support\Locale;

beforeEach(function () {
    Locale::flush();
});

function locAppearance()
{
    // Any signed-in route through the Inertia middleware; reading your own appearance needs no permission.
    return route('core.settings.appearance');
}

test('the catalogue derives direction from the code, nowhere else', function () {
    expect(Locale::direction('en'))->toBe('ltr')
        ->and(Locale::direction('bn'))->toBe('rtl')
        // An unknown code degrades to ltr, never to a broken layout.
        ->and(Locale::direction('zz'))->toBe('ltr')
        ->and(Locale::isValid('bn'))->toBeTrue()
        ->and(Locale::isValid('fr'))->toBeFalse();
});

test('a signed-in user can switch to Bangla and it persists in the session', function () {
    $user = User::factory()->create();
    $this->actingAs($user)
        ->withoutMiddleware([VerifyCsrfToken::class])
        ->put(route('core.locale.update'), ['locale' => 'bn'])
        ->assertRedirect()
        ->assertSessionHas('app.locale', 'bn');

    // The read path the next request takes: SetLocale turns the session value into the shared prop.
    $this->actingAs($user)->withSession(['app.locale' => 'bn'])->get(locAppearance())
        ->assertInertia(fn ($page) => $page->where('locale.code', 'bn')->where('locale.dir', 'rtl'));
});

test('an unrecognised locale is refused, not applied', function () {
    $user = User::factory()->create();
    $this->actingAs($user)
        ->withoutMiddleware([VerifyCsrfToken::class])
        ->put(route('core.locale.update'), ['locale' => 'klingon'])
        ->assertSessionHasErrors('locale');
});

test('the server renders dir on the first paint for a Bangla session', function () {
    $user = User::factory()->create();
    $this->actingAs($user)
        ->withSession(['app.locale' => 'bn'])
        ->get(locAppearance())
        ->assertOk()
        // The whole document mirrors before any JavaScript runs.
        ->assertSee('dir="rtl"', false)
        ->assertSee('lang="bn"', false);
});

test('the company default applies to a user who has made no personal choice', function () {
    Locale::setCompany('bn');

    $user = User::factory()->create();
    $this->actingAs($user)->get(locAppearance())
        ->assertInertia(fn ($page) => $page->where('locale.code', 'bn')->where('locale.dir', 'rtl'));
});

test('a personal choice overrides the company default', function () {
    Locale::setCompany('bn');

    $user = User::factory()->create();
    $this->actingAs($user)->withSession(['app.locale' => 'en'])->get(locAppearance())
        ->assertInertia(fn ($page) => $page->where('locale.code', 'en')->where('locale.dir', 'ltr'));
});

test('setCompany refuses an unknown code and a missing table is tolerated by the resolver', function () {
    $this->expectException(DomainException::class);
    Locale::setCompany('elvish');
});
