<?php

namespace Tests\Feature\CORE;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\CORE\Models\SsoIdentity;
use Modules\CORE\Models\User;
use Modules\CORE\Services\SsoService;
use Tests\TestCase;

/**
 * SSO — the external login path, end to end (Phase 3).
 *
 * `Http::fake()` stands in for the identity provider, which is the point of the userinfo-based design: no
 * key material to fabricate, no JWKS to serve, and every assertion is about a decision this application
 * makes rather than about a signature library's test vectors.
 *
 * The tests below are grouped by the question each one answers: what may be offered at all (config), what
 * the round trip will accept (state, refusal, verification, expiry), who a returned identity becomes
 * (provision, adopt, domain gate), and what may be taken away (unlink, with the lockout rule).
 */
class Phase3SsoTest extends TestCase
{
    use RefreshDatabase;

    /** The profile the stubbed provider returns next, and the status its token endpoint answers with. */
    private array $userinfo = [];

    private int $tokenStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();

        // One fake for the whole test, dispatching on the URL and reading mutable state. Re-calling
        // `Http::fake()` with URL keys does not reliably replace a stub already registered for the same
        // URL, and a test that silently keeps the *previous* profile looks exactly like a bug in the
        // identity-matching code — which is how this suite started, and why it is written this way.
        Http::fake(['*' => function ($request) {
            // `$request->url()`, not the closure's second argument: the stub callback is invoked as
            // `($request, $options)` and the second parameter is the request options array.
            $url = $request->url();

            if (str_contains($url, '/token')) {
                if ($this->tokenStatus !== 200) {
                    return Http::response(['error' => 'invalid_grant'], $this->tokenStatus);
                }

                return Http::response(['access_token' => 'at-'.Str::random(8), 'token_type' => 'Bearer']);
            }

            return Http::response($this->userinfo);
        }]);
    }

    // ------------------------------------------------------------- what makes a button

    public function test_sso_is_off_by_default_and_the_round_trip_answers_404(): void
    {
        $this->assertFalse((bool) config('core.sso.enabled'), 'shipping the feature switched on would be wrong');

        // The routes always exist, so `route()` in the login page cannot 500 — but nothing is reachable.
        $this->get('/core/sso/google/redirect')->assertNotFound();
        $this->get('/core/sso/google/callback?code=x&state=y')->assertNotFound();

        $this->assertSame([], app(SsoService::class)->availableProviders());
    }

    public function test_a_provider_needs_both_credentials_before_it_is_offered(): void
    {
        $this->enableSso(creds: false);

        // Enabled, but with no client id or secret there is nothing to advertise.
        $this->assertSame([], app(SsoService::class)->availableProviders());

        Config::set('core.sso.providers.google.client_id', 'cid-123');
        $this->assertSame([], app(SsoService::class)->availableProviders(), 'a client id without a secret cannot complete a token exchange');

        Config::set('core.sso.providers.google.client_secret', 'secret-456');
        $this->assertSame(['google' => 'Google Workspace'], app(SsoService::class)->availableProviders());
    }

    public function test_the_login_page_offers_the_buttons_only_when_they_would_work(): void
    {
        $this->get('/login')->assertOk()->assertInertia(fn ($page) => $page
            ->where('ssoProviders', [])
        );

        $this->enableSso(['core.sso.providers.google.client_id' => 'cid', 'core.sso.providers.google.client_secret' => 'sec']);

        $this->get('/login')->assertOk()->assertInertia(fn ($page) => $page
            ->has('ssoProviders', 1)
            ->where('ssoProviders.google', 'Google Workspace')
        );
    }

    public function test_the_redirect_leg_sends_the_provider_a_state_and_a_return_uri(): void
    {
        $this->enableSso([
            'core.sso.providers.google.client_id' => 'cid',
            'core.sso.providers.google.client_secret' => 'sec',
        ]);

        $response = $this->get('/core/sso/google/redirect');
        $response->assertRedirect();

        $target = $response->headers->get('location');
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $target);
        $query = [];
        parse_str((string) parse_url($target, PHP_URL_QUERY), $query);

        $this->assertSame('cid', $query['client_id']);
        $this->assertSame('code', $query['response_type']);
        // The provider must be told the exact URL we will answer on: an origin mismatch is the most common
        // way a real IdP refuses, and it is invisible until you compare it against the route.
        $this->assertSame(route('core.sso.callback', 'google'), $query['redirect_uri']);
        $this->assertSame('openid email profile', $query['scope']);
        $this->assertNotEmpty($query['state']);

        $pending = session()->get('sso.pending');
        $this->assertSame('google', $pending['provider']);
        $this->assertSame($query['state'], $pending['state'], 'the state that leaves must be the state we check on return');
    }

    public function test_a_multi_tenant_provider_resolves_its_tenant_placeholder(): void
    {
        $this->enableSso([
            'core.sso.providers.microsoft.client_id' => 'cid',
            'core.sso.providers.microsoft.client_secret' => 'sec',
            'core.sso.providers.microsoft.tenant' => 'contoso.onmicrosoft.com',
        ]);

        $target = $this->get('/core/sso/microsoft/redirect')->headers->get('location');

        $this->assertStringContainsString('login.microsoftonline.com/contoso.onmicrosoft.com/oauth2/v2.0/authorize', (string) $target);
        $this->assertStringNotContainsString('{tenant}', (string) $target);
    }

    // ------------------------------------------------------- what the round trip accepts

    public function test_a_callback_without_the_state_we_minted_is_refused(): void
    {
        $this->enableSso();
        $this->fakeProvider(['sub' => 'g-1', 'email' => 'a@corp.test', 'email_verified' => true]);

        session()->put('sso.pending', ['provider' => 'google', 'state' => 'real-state', 'intent' => 'login', 'at' => time()]);

        $this->get('/core/sso/google/callback?code=abc&state=forged')
            ->assertRedirect(route('login'))
            ->assertSessionHas('error');

        $this->assertGuest();
        $this->assertSame(0, SsoIdentity::count());
        $this->assertSame(0, User::count(), 'a forged state must not even reach the point of provisioning');
    }

    public function test_the_callback_url_cannot_be_used_twice(): void
    {
        $this->enableSso();
        $this->fakeProvider(['sub' => 'g-1', 'email' => 'a@corp.test', 'email_verified' => true]);

        $url = $this->callbackUrl('g-1', 'a@corp.test');

        // First use: a login.
        $this->get($url)->assertRedirect(route('core.dashboard'));
        $this->assertAuthenticated();

        // Someone presses Back and replays it, or forwards the URL to a colleague. The state was deleted at
        // the top of `complete()` — before any check — so the second visit is inert even though every
        // parameter on the URL still reads as valid.
        Auth::logout();
        $this->get($url)
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', fn (string $m): bool => str_contains($m, 'not started here'));

        $this->assertGuest();
        $this->assertSame(1, SsoIdentity::count(), 'the replay created nothing new');
    }

    public function test_a_stale_attempt_expires(): void
    {
        $this->enableSso(['core.sso.state_ttl' => 600]);
        $this->fakeProvider(['sub' => 'g-1', 'email' => 'a@corp.test', 'email_verified' => true]);

        session()->put('sso.pending', ['provider' => 'google', 'state' => 's', 'intent' => 'login', 'at' => time() - 3600]);

        $this->get('/core/sso/google/callback?code=abc&state=s')
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', fn (string $m): bool => str_contains($m, 'too long'));

        $this->assertGuest();
    }

    public function test_the_provider_refusing_the_sign_in_is_reported_rather_than_thrown(): void
    {
        $this->enableSso();

        // The provider said no before we ever exchanged anything.
        session()->put('sso.pending', ['provider' => 'google', 'state' => 's', 'intent' => 'login', 'at' => time()]);

        $this->get('/core/sso/google/callback?error=access_denied&error_description=User+closed+the+window&state=s')
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', fn (string $m): bool => str_contains($m, 'User closed the window'));

        $this->assertGuest();
        Http::assertNothingSent();
    }

    public function test_a_broken_token_exchange_names_the_http_status(): void
    {
        $this->enableSso([
            'core.sso.providers.google.client_id' => 'cid',
            'core.sso.providers.google.client_secret' => 'sec',
        ]);

        $this->tokenStatus = 400;

        session()->put('sso.pending', ['provider' => 'google', 'state' => 's', 'intent' => 'login', 'at' => time()]);

        $this->get('/core/sso/google/callback?code=abc&state=s')
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', fn (string $m): bool => str_contains($m, 'HTTP 400') && str_contains($m, 'invalid_grant'));

        $this->assertGuest();
    }

    public function test_a_profile_without_a_subject_is_refused_rather_than_mis_matched(): void
    {
        $this->enableSso();

        // Some gateways strip `sub` when the scope list was wrong. Creating an identity keyed on the empty
        // string would let the *next* malformed answer log in as it, so it has to be refused here.
        $this->userinfo = ['email' => 'a@corp.test', 'email_verified' => true];
        session()->put('sso.pending', ['provider' => 'google', 'state' => 's', 'intent' => 'login', 'at' => time()]);

        $this->get('/core/sso/google/callback?code=abc&state=s')
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', fn (string $m): bool => str_contains($m, 'usable address or subject'));

        $this->assertSame(0, SsoIdentity::count());
        $this->assertSame(0, User::count());
    }

    // -------------------------------------------- who a returned identity becomes

    public function test_a_verified_identity_gets_an_account_that_cannot_be_signed_into_with_a_password(): void
    {
        $this->enableSso();
        $this->fakeProvider(['sub' => 'g-99', 'email' => 'Priya@Corp.test', 'name' => 'Priya R.', 'email_verified' => true]);

        $this->signInViaSso('g-99', 'priya@corp.test');

        $user = User::where('email', 'priya@corp.test')->firstOrFail();

        $this->assertSame('Priya R.', $user->name);
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame(0, $user->roles()->count(), 'an auto-provisioned account must arrive with no permissions');
        $this->assertFalse(Hash::check('password', $user->password), 'the provisioning password must not be guessable');
        $this->assertFalse(Hash::check('', $user->password));
        // Driver-agnostic: it must be a real password hash (not plaintext, not null), because `users.password`
        // is NOT NULL and provisioning has to write *something*.
        $this->assertNotSame(0, password_get_info((string) $user->password)['algo'], 'a hash, not plaintext noise');

        $identity = SsoIdentity::sole();
        $this->assertSame('google', $identity->provider);
        $this->assertSame('g-99', $identity->provider_user_id);
        $this->assertSame($user->id, $identity->user_id);
        $this->assertNotNull($identity->last_login_at);
    }

    public function test_an_unverified_address_is_refused_even_when_an_account_exists(): void
    {
        $this->enableSso();
        $user = $this->localUser('priya@corp.test');
        $this->fakeProvider(['sub' => 'g-99', 'email' => 'priya@corp.test', 'email_verified' => false]);

        $this->get($this->callbackUrl('g-99'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', fn (string $m): bool => str_contains($m, 'not verified'));

        $this->assertGuest();
        $this->assertSame(0, SsoIdentity::count());
        $this->assertSame(1, User::count(), 'a refusal must not touch the account it was about');
    }

    public function test_the_subject_is_the_identity_and_the_address_is_only_a_label(): void
    {
        $this->enableSso();

        $this->fakeProvider(['sub' => 'g-7', 'email' => 'old@corp.test', 'name' => 'Old Name', 'email_verified' => true]);
        $this->signInViaSso('g-7', 'old@corp.test');
        $user = User::where('email', 'old@corp.test')->firstOrFail();

        // Same person, address changed by IT. The link follows them; no second account appears.
        $this->fakeProvider(['sub' => 'g-7', 'email' => 'new@corp.test', 'name' => 'New Name', 'email_verified' => true]);
        $this->signInViaSso('g-7', 'new@corp.test');

        $this->assertSame(1, User::count());
        $this->assertSame(1, SsoIdentity::count());
        $this->assertSame('new@corp.test', SsoIdentity::sole()->email);
        $this->assertSame($user->id, SsoIdentity::sole()->user_id);
        $this->assertFalse(User::where('email', 'new@corp.test')->exists(), 'the address moved, the account did not');
    }

    public function test_an_existing_local_account_is_adopted_rather_than_duplicated(): void
    {
        $this->enableSso();
        $user = $this->localUser('priya@corp.test');

        $this->fakeProvider(['sub' => 'g-1', 'email' => 'priya@corp.test', 'name' => 'Different Name', 'email_verified' => true]);
        $this->signInViaSso('g-1', 'priya@corp.test');

        $this->assertSame(1, User::count());
        $this->assertSame($user->id, SsoIdentity::sole()->user_id);
        $this->assertSame($user->name, $user->fresh()->name, 'an SSO display name must not overwrite the directory');
        $this->assertTrue(Hash::check('password', $user->fresh()->password), 'the local password still works');
    }

    public function test_the_domain_gate_applies_to_provisioning_and_to_adoption_alike(): void
    {
        $this->enableSso(['core.sso.allowed_domains' => ['corp.test']]);
        $this->fakeProvider(['sub' => 'g-1', 'email' => 'priya@other.test', 'email_verified' => true]);

        $this->get($this->callbackUrl('g-1', 'priya@other.test'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', fn (string $m): bool => str_contains($m, 'corp.test'));

        $this->assertSame(0, User::count());

        // An existing local account is not a loophole.
        $this->localUser('priya@other.test');
        $this->get($this->callbackUrl('g-1', 'priya@other.test'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error');
        $this->assertGuest();
        $this->assertSame(0, SsoIdentity::count());
    }

    public function test_provisioning_off_admits_a_known_account_and_refuses_a_stranger(): void
    {
        $this->enableSso(['core.sso.auto_provision' => false]);
        $user = $this->localUser('priya@corp.test');

        $this->fakeProvider(['sub' => 'g-1', 'email' => 'priya@corp.test', 'email_verified' => true]);
        $this->get($this->callbackUrl('g-1', 'priya@corp.test'))->assertRedirect(route('core.dashboard'));
        $this->assertAuthenticatedAs($user);

        $this->fakeProvider(['sub' => 'g-2', 'email' => 'new@corp.test', 'email_verified' => true]);
        // Back on the accounts page rather than the login form: this person is signed in already, which is
        // exactly why the attempt started from there — a failure has to return to where the user is standing.
        $this->get($this->callbackUrl('g-2', 'new@corp.test', 's2'))
            ->assertRedirect(route('core.sso.accounts'))
            ->assertSessionHas('error', fn (string $m): bool => str_contains($m, 'does not create them on the fly'));
    }

    // ----------------------------------------------------------------- linking & unlinking

    public function test_the_accounts_page_lists_links_and_only_offers_unused_providers(): void
    {
        $this->enableSso(['core.sso.providers.google.client_id' => 'cid', 'core.sso.providers.google.client_secret' => 'sec']);
        $user = $this->localUser('priya@corp.test');

        SsoIdentity::create([
            'provider' => 'microsoft', 'provider_user_id' => 'm-1', 'email' => 'priya@corp.test',
            'name' => 'Priya', 'user_id' => $user->id, 'last_login_at' => now(),
        ]);

        $this->actingAs($user)->get('/core/sso/accounts')->assertOk()->assertInertia(fn ($page) => $page
            ->component('CORE::Sso/Accounts')
            ->has('identities', 1)
            ->where('identities.0.provider', 'microsoft')
            ->where('identities.0.email', 'priya@corp.test')
            ->where('available', ['google' => 'Google Workspace'])
            ->where('enabled', true)
            ->where('needsPassword', true)
        );
    }

    public function test_a_signed_in_session_links_the_identity_instead_of_logging_anyone_in(): void
    {
        $this->enableSso();
        $user = $this->localUser('priya@corp.test');
        $this->fakeProvider(['sub' => 'g-5', 'email' => 'someone-else@corp.test', 'name' => 'Other', 'email_verified' => true]);

        $this->actingAs($user);
        session()->put('sso.pending', ['provider' => 'google', 'state' => 's', 'intent' => 'link', 'at' => time()]);

        $this->get('/core/sso/google/callback?code=abc&state=s')
            ->assertRedirect(route('core.sso.accounts'))
            ->assertSessionHas('success');

        $identity = SsoIdentity::sole();
        $this->assertSame($user->id, $identity->user_id);
        $this->assertSame('someone-else@corp.test', $identity->email);
        $this->assertSame(1, User::count(), 'linking must never provision a second account');
    }

    public function test_an_identity_another_account_holds_cannot_be_stolen(): void
    {
        $this->enableSso();
        $other = $this->localUser('other@corp.test');
        $me = $this->localUser('priya@corp.test');

        SsoIdentity::create(['provider' => 'google', 'provider_user_id' => 'g-1', 'email' => 'other@corp.test', 'user_id' => $other->id]);

        $this->fakeProvider(['sub' => 'g-1', 'email' => 'other@corp.test', 'email_verified' => true]);
        $this->actingAs($me);
        session()->put('sso.pending', ['provider' => 'google', 'state' => 's', 'intent' => 'link', 'at' => time()]);

        $this->get('/core/sso/google/callback?code=abc&state=s')
            ->assertRedirect(route('core.sso.accounts'))
            ->assertSessionHas('error', fn (string $m): bool => str_contains($m, 'another account'));

        $this->assertSame($other->id, SsoIdentity::sole()->user_id);
    }

    public function test_the_last_link_needs_the_password_and_a_wrong_one_removes_nothing(): void
    {
        $this->enableSso();
        $user = $this->localUser('priya@corp.test');
        $identity = SsoIdentity::create(['provider' => 'google', 'provider_user_id' => 'g-1', 'email' => 'priya@corp.test', 'user_id' => $user->id]);

        $this->actingAs($user);

        $this->delete('/core/sso/identities/'.$identity->id)
            ->assertRedirect()->assertSessionHas('error', fn (string $m): bool => str_contains($m, 'only linked sign-in'));
        $this->assertDatabaseHas('sso_identities', ['id' => $identity->id]);

        $this->json('DELETE', '/core/sso/identities/'.$identity->id, ['password' => 'not-it'])
            ->assertSessionHas('error');
        $this->assertDatabaseHas('sso_identities', ['id' => $identity->id]);

        $this->json('DELETE', '/core/sso/identities/'.$identity->id, ['password' => 'password'])
            ->assertSessionHas('success');
        $this->assertSame(0, SsoIdentity::count());
    }

    public function test_a_second_link_can_be_removed_without_interrogation(): void
    {
        $this->enableSso();
        $user = $this->localUser('priya@corp.test');
        $google = SsoIdentity::create(['provider' => 'google', 'provider_user_id' => 'g-1', 'email' => 'priya@corp.test', 'user_id' => $user->id]);
        SsoIdentity::create(['provider' => 'microsoft', 'provider_user_id' => 'm-1', 'email' => 'priya@corp.test', 'user_id' => $user->id]);

        $this->actingAs($user)
            ->delete('/core/sso/identities/'.$google->id)
            ->assertSessionHas('success');

        $this->assertSame(['microsoft'], SsoIdentity::pluck('provider')->all());
    }

    public function test_a_link_cannot_be_removed_from_someone_elses_account(): void
    {
        $this->enableSso();
        $other = $this->localUser('other@corp.test');
        $me = $this->localUser('priya@corp.test');
        $identity = SsoIdentity::create(['provider' => 'google', 'provider_user_id' => 'g-1', 'email' => 'other@corp.test', 'user_id' => $other->id]);

        $this->actingAs($me)
            ->json('DELETE', '/core/sso/identities/'.$identity->id, ['password' => 'password'])
            ->assertSessionHas('error', fn (string $m): bool => str_contains($m, 'belong to you'));

        $this->assertDatabaseHas('sso_identities', ['id' => $identity->id, 'user_id' => $other->id]);
    }

    public function test_the_service_refuses_a_provider_that_is_not_configured(): void
    {
        $this->enableSso();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('is not configured');

        app(SsoService::class)->begin($this->app['request'], 'acme');
    }

    public function test_the_handshake_is_registered_but_throttled(): void
    {
        // The routes always exist (the login page builds their URLs unconditionally); what keeps an
        // anonymous visitor from turning this installation into a relay against the identity provider is
        // the limiter, so it is pinned here rather than trusted to the file.
        foreach (['core.sso.redirect', 'core.sso.callback'] as $name) {
            $route = app('router')->getRoutes()->getByName($name);

            $this->assertNotNull($route, "{$name} is not registered — the login page would throw on every visit");
            $this->assertContains('throttle:15,1', $route->gatherMiddleware(), "{$name} can be hit without limit");
        }
    }

    public function test_the_guard_configuration_is_the_web_session_not_a_stateless_token(): void
    {
        // The pin that stops the next refactor from quietly making SSO logins stateless.
        $this->assertSame('web', config('auth.defaults.guard'));
        $this->assertSame('session', config('auth.guards.web.driver'));
        $this->assertSame(User::class, config('auth.providers.users.model'));
    }

    // ------------------------------------------------------------------------- helpers

    private function enableSso(array $overrides = [], bool $creds = true): void
    {
        Config::set('core.sso.enabled', true);
        Config::set('core.sso.auto_provision', true);
        Config::set('core.sso.allowed_domains', []);

        if ($creds) {
            Config::set('core.sso.providers.google.client_id', 'client-id');
            Config::set('core.sso.providers.google.client_secret', 'client-secret');
        }

        foreach ($overrides as $key => $value) {
            Config::set($key, $value);
        }
    }

    /** Queue the profile the provider will hand back on the next request. */
    private function fakeProvider(array $userinfo): void
    {
        $this->userinfo = $userinfo;
    }

    /**
     * Open a pending round trip and hand back the URL the provider would redirect to.
     *
     * The payload is *merged* rather than replaced, so a test that queued `email_verified => false` or a
     * display name keeps it and this only fills in what that test did not care about. Replacing it here was a
     * real trap: the assertion about the provisioned name read the fallback instead of the provider's value,
     * which is the difference between testing `displayName()` and testing nothing.
     */
    private function callbackUrl(string $sub = '', string $email = '', string $state = 's'): string
    {
        $this->userinfo = array_merge(
            ['sub' => $sub, 'email' => $email, 'email_verified' => true],
            $this->userinfo,
        );

        session()->put('sso.pending', ['provider' => 'google', 'state' => $state, 'intent' => 'login', 'at' => time()]);

        return '/core/sso/google/callback?code=abc&state='.$state;
    }

    private function localUser(string $email): User
    {
        return User::factory()->create(['email' => $email, 'password' => 'password']);
    }

    private function signInViaSso(string $sub = '', string $email = ''): void
    {
        $this->get($this->callbackUrl($sub, $email))
            ->assertRedirect(route('core.dashboard'))
            ->assertSessionHas('success');

        $this->assertAuthenticated();
    }
}
