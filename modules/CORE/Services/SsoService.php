<?php

namespace Modules\CORE\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\CORE\Models\SsoIdentity;
use Modules\CORE\Models\User;

/**
 * SSO — the whole external-login path (Phase 3).
 *
 * The flow is authorization-code → `userinfo`, and every decision that could let someone into the ERP is
 * made here rather than in the controller, so the API login door (F13) and the web login share one set of
 * rules if they ever grow one.
 *
 * Three rules the rest of the class exists to enforce:
 *  - the provider's *subject id* is the identity, never the address;
 *  - an unverified address is not a login, whatever the issuer says next to it;
 *  - the round trip is single-use: state is minted on the redirect, checked with `hash_equals`, and
 *    deleted before anything else happens, so a callback URL in someone's history is inert.
 */
class SsoService
{
    private const SESSION_KEY = 'sso.pending';

    /**
     * Providers the login page may offer: enabled, configured, and nothing else.
     *
     * @return array<string, string> provider key => label
     */
    public function availableProviders(): array
    {
        if (! $this->enabled()) {
            return [];
        }

        $out = [];

        foreach ((array) config('core.sso.providers', []) as $key => $cfg) {
            if ($this->isConfigured((string) $key)) {
                $out[(string) $key] = (string) ($cfg['label'] ?? Str::headline((string) $key));
            }
        }

        return $out;
    }

    public function enabled(): bool
    {
        return (bool) config('core.sso.enabled', false);
    }

    public function isConfigured(string $provider): bool
    {
        $cfg = $this->provider($provider);

        return $cfg !== null
            && $this->enabled()
            && $cfg['client_id'] !== ''
            && $cfg['client_secret'] !== ''
            && $cfg['token_url'] !== ''
            && $cfg['userinfo_url'] !== '';
    }

    /**
     * @return array<string,mixed>|null
     */
    public function provider(string $provider): ?array
    {
        $cfg = config("core.sso.providers.{$provider}");

        if (! is_array($cfg)) {
            return null;
        }

        $tenant = (string) ($cfg['tenant'] ?? '');

        return [
            'label' => (string) ($cfg['label'] ?? $provider),
            'authorize_url' => $this->applyTenant((string) ($cfg['authorize_url'] ?? ''), $tenant),
            'token_url' => $this->applyTenant((string) ($cfg['token_url'] ?? ''), $tenant),
            'userinfo_url' => $this->applyTenant((string) ($cfg['userinfo_url'] ?? ''), $tenant),
            'client_id' => (string) ($cfg['client_id'] ?? ''),
            'client_secret' => (string) ($cfg['client_secret'] ?? ''),
            'scopes' => (array) ($cfg['scopes'] ?? ['openid', 'email', 'profile']),
        ];
    }

    /**
     * The URL the browser is sent to, with the state that makes the answer ours.
     */
    public function begin(Request $request, string $provider, string $intent = 'login'): string
    {
        if (! $this->isConfigured($provider)) {
            throw new \DomainException("Single sign-on with \"{$provider}\" is not configured on this installation.");
        }

        $state = Str::random(40);

        $request->session()->put(self::SESSION_KEY, [
            'provider' => $provider,
            'state' => $state,
            'intent' => $intent === 'link' ? 'link' : 'login',
            // Recorded, not compared: a login is only half an audit event without knowing where it came from.
            'at' => time(),
        ]);

        return $this->provider($provider)['authorize_url'].'?'.http_build_query([
            'client_id' => $this->provider($provider)['client_id'],
            'redirect_uri' => route('core.sso.callback', $provider),
            'response_type' => 'code',
            'scope' => implode(' ', $this->provider($provider)['scopes']),
            'state' => $state,
            'prompt' => 'select_account',
        ]);
    }

    /**
     * Exchange the code, read the identity, and decide who — if anyone — this is.
     *
     * On success the caller (the controller) performs `Auth::login`; the service deliberately does not, so
     * the same resolution can be used to *link* an identity to a session that is already signed in, where
     * logging anyone in would be wrong.
     *
     * @return array{user:User, identity:SsoIdentity, intent:string}
     */
    public function complete(Request $request, string $provider): array
    {
        $pending = $request->session()->pull(self::SESSION_KEY);

        // Consumed above, before any check: a callback that fails must still be dead.
        if (! is_array($pending) || ($pending['provider'] ?? null) !== $provider) {
            throw new \DomainException('That sign-in attempt was not started here. Use the button on the login page.');
        }

        if (! hash_equals((string) $pending['state'], (string) $request->query('state', ''))) {
            throw new \DomainException('That sign-in link is stale or was tampered with. Start again from the login page.');
        }

        if (time() - (int) ($pending['at'] ?? 0) > (int) config('core.sso.state_ttl', 600)) {
            throw new \DomainException('That sign-in attempt took too long. Start again — the link is single-use.');
        }

        if ($request->query('error')) {
            throw new \DomainException('The identity provider refused the sign-in: '.$request->query('error_description', $request->query('error')));
        }

        $code = (string) $request->query('code', '');

        if ($code === '') {
            throw new \DomainException('The identity provider returned no authorisation code.');
        }

        $cfg = $this->provider($provider);
        $raw = $this->fetchUserinfo($provider, $cfg, $code);

        $subject = (string) ($raw['sub'] ?? $raw['id'] ?? $raw['oid'] ?? '');
        $email = strtolower(trim((string) ($raw['email'] ?? $raw['upn'] ?? '')));

        if ($subject === '' || $email === '') {
            throw new \DomainException('The identity provider did not return a usable address or subject — check the scopes requested (this one asked for "'.implode(' ', $cfg['scopes']).'").');
        }

        // Only an explicit `false` is a refusal: Google and Entra both send the flag, Keycloak realms
        // often omit it entirely, and treating "absent" as "false" locks everyone out of an on-prem IdP.
        if (array_key_exists('email_verified', $raw) && ! filter_var($raw['email_verified'], FILTER_VALIDATE_BOOLEAN)) {
            throw new \DomainException("The address {$email} is not verified at {$cfg['label']}, so it cannot open an account here.");
        }

        $this->assertDomainAllowed($email);

        $identity = SsoIdentity::where('provider', $provider)->where('provider_user_id', $subject)->first();
        $intent = ($pending['intent'] ?? 'login') === 'link' ? 'link' : 'login';

        if ($intent === 'link') {
            return [
                'user' => $this->linkToCurrentUser($request, $identity, $provider, $subject, $email, $raw),
                'identity' => $identity ?? SsoIdentity::query()->where('provider', $provider)->where('provider_user_id', $subject)->firstOrFail(),
                'intent' => 'link',
            ];
        }

        return $this->resolveLogin($identity, $provider, $subject, $email, $raw);
    }

    /**
     * Remove a link, refusing the one removal that would lock a person out of their own account.
     *
     * The password is not a courtesy check: an SSO-provisioned account holds 64 random bytes in its
     * password column that nobody has ever seen, and "hash of noise" is indistinguishable from "hash of a
     * real password" — that is the entire point of hashing. So the server cannot answer "does this account
     * have a way in without that link?" by inspection. The owner can, by typing it: the check below
     * requires it exactly when the removal would leave a single sign-in path, and skips it when other
     * links remain, so a person with three providers is never interrogated for unlinking one.
     *
     * @throws \DomainException
     */
    public function unlink(User $user, SsoIdentity $identity, ?string $passwordProof = null): void
    {
        if ((int) $identity->user_id !== (int) $user->id) {
            throw new \DomainException('That linked account does not belong to you.');
        }

        $others = $user->ssoIdentities()->whereKeyNot($identity->getKey())->count();

        if ($others === 0) {
            if ($passwordProof === null || $passwordProof === '' || ! Hash::check($passwordProof, (string) $user->password)) {
                throw new \DomainException(
                    'This is the only linked sign-in on the account, so removing it needs your password to '
                    .'prove the account has another way in. If it never had one, set a password through '
                    .'“Forgot password?” first — or link a second provider.'
                );
            }
        }

        $identity->delete();
    }

    /**
     * The identity rows behind a user, shaped for the account page.
     *
     * @return list<array<string,mixed>>
     */
    public function identitiesFor(User $user): array
    {
        return $user->ssoIdentities()->get()->map(fn (SsoIdentity $i): array => [
            'id' => $i->id,
            'provider' => $i->provider,
            'label' => $this->provider($i->provider)['label'] ?? Str::headline($i->provider),
            'email' => $i->email,
            'name' => $i->name,
            'last_login_at' => $i->last_login_at?->diffForHumans(),
        ])->all();
    }

    /**
     * Providers this account could still add: configured, and not already linked.
     *
     * @return array<string,string>
     */
    public function linkableProviders(User $user): array
    {
        $used = $user->ssoIdentities()->pluck('provider')->all();

        return array_filter(
            $this->availableProviders(),
            static fn (string $label, string $key): bool => ! in_array($key, $used, true),
            ARRAY_FILTER_USE_BOTH
        );
    }

    /**
     * @param  array<string,mixed>  $cfg
     * @param  array<string,mixed>  $raw
     * @return array{user:User, identity:SsoIdentity, intent:string}
     */
    private function resolveLogin(?SsoIdentity $identity, string $provider, string $subject, string $email, array $raw): array
    {
        if ($identity !== null) {
            $user = $identity->user()->firstOrFail();
            $identity->forceFill(['last_login_at' => now(), 'email' => $email])->save();

            return ['user' => $user, 'identity' => $identity, 'intent' => 'login'];
        }

        // No link row yet: an existing local account with this address is adopted rather than duplicated,
        // which is the whole reason IT departments can turn SSO on for a live ERP.
        $user = User::whereRaw('LOWER(email) = ?', [$email])->first();

        if ($user === null) {
            $user = $this->provision($email, $raw);
        }

        $identity = SsoIdentity::create([
            'provider' => $provider,
            'provider_user_id' => $subject,
            'email' => $email,
            'name' => $this->displayName($raw, $email),
            'avatar_url' => isset($raw['picture']) ? (string) $raw['picture'] : (isset($raw['photo']) ? (string) $raw['photo'] : null),
            'user_id' => $user->id,
            'last_login_at' => now(),
        ]);

        return ['user' => $user, 'identity' => $identity, 'intent' => 'login'];
    }

    /**
     * @param  array<string,mixed>  $raw
     */
    private function linkToCurrentUser(Request $request, ?SsoIdentity $identity, string $provider, string $subject, string $email, array $raw): User
    {
        $me = $request->user();

        if ($me === null) {
            throw new \DomainException('Sign in first, then link a provider from your account page.');
        }

        if ($identity !== null) {
            if ((int) $identity->user_id === (int) $me->id) {
                throw new \DomainException("That {$email} address is already linked to this account.");
            }

            throw new \DomainException('That identity is already linked to another account here. Ask an administrator to move it — the link is not yours to steal.');
        }

        SsoIdentity::create([
            'provider' => $provider,
            'provider_user_id' => $subject,
            'email' => $email,
            'name' => $this->displayName($raw, $email),
            'avatar_url' => isset($raw['picture']) ? (string) $raw['picture'] : null,
            'user_id' => $me->id,
            'last_login_at' => now(),
        ]);

        return $me;
    }

    /**
     * @param  array<string,mixed>  $raw
     */
    private function provision(string $email, array $raw): User
    {
        if (! (bool) config('core.sso.auto_provision', true)) {
            throw new \DomainException(
                "No account exists for {$email} and this installation does not create them on the fly. "
                .'Ask an administrator to create the account, then sign in again to link it.'
            );
        }

        // Random, long, and never shown to anyone: the column is NOT NULL, and the point is that this
        // account cannot be signed into with a password until its owner sets one via the reset flow.
        $password = Str::random(64);

        // `email_verified_at` is not in `User::$fillable` — the app model keeps it unfillable on purpose, so
        // no request can ever set it. `forceFill` is the only correct way for a server-side trust decision to
        // stamp it; passing it through `create()` would be silently dropped, leaving an account that is
        // linked to a verified provider and still reads as unverified to every other guard in the app.
        $user = new User([
            'name' => $this->displayName($raw, $email),
            'email' => $email,
            'password' => Hash::make($password),
        ]);

        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    /**
     * @param  array<string,mixed>  $cfg
     * @return array<string,mixed>
     */
    private function fetchUserinfo(string $provider, array $cfg, string $code): array
    {
        $token = Http::asForm()->timeout(15)->connectTimeout(8)->post($cfg['token_url'], [
            'grant_type' => 'authorization_code',
            'client_id' => $cfg['client_id'],
            'client_secret' => $cfg['client_secret'],
            'code' => $code,
            'redirect_uri' => route('core.sso.callback', $provider),
        ]);

        if ($token->failed()) {
            throw new \DomainException("{$cfg['label']} refused the authorisation code (HTTP {$token->status()}): "
                .($token->json('error_description') ?: $token->json('error') ?: 'check the client id, secret and the redirect URI registered at the provider'));
        }

        $accessToken = (string) ($token->json('access_token') ?? '');

        if ($accessToken === '') {
            throw new \DomainException("{$cfg['label']} answered the token request without an access token.");
        }

        $me = Http::withToken($accessToken)->timeout(15)->connectTimeout(8)->get($cfg['userinfo_url']);

        if ($me->failed()) {
            throw new \DomainException("{$cfg['label']} would not return the profile (HTTP {$me->status()}).");
        }

        $raw = $me->json();

        if (! is_array($raw)) {
            throw new \DomainException("{$cfg['label']} returned something that is not a profile document.");
        }

        return $raw;
    }

    private function assertDomainAllowed(string $email): void
    {
        $allowed = array_map(static fn ($d): string => strtolower((string) $d), (array) config('core.sso.allowed_domains', []));

        if ($allowed === []) {
            return;
        }

        $domain = strtolower((string) Str::afterLast($email, '@'));

        if (! in_array($domain, $allowed, true)) {
            throw new \DomainException(
                'Accounts here sign in only from '.implode(' or ', $allowed).". {$email} is not on one of those domains."
            );
        }
    }

    /**
     * @param  array<string,mixed>  $raw
     */
    private function displayName(array $raw, string $email): string
    {
        foreach (['name', 'given_name', 'displayName'] as $key) {
            if (isset($raw[$key]) && is_string($raw[$key]) && trim($raw[$key]) !== '') {
                return Str::limit(trim($raw[$key]), 120, '');
            }
        }

        return Str::headline(Str::before($email, '@'));
    }

    private function applyTenant(string $url, string $tenant): string
    {
        return $tenant === '' ? $url : str_replace('{tenant}', $tenant, $url);
    }
}
