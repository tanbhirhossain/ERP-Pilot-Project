<?php

namespace Modules\CORE\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Modules\CORE\Models\SsoIdentity;
use Modules\CORE\Services\SsoService;

/**
 * SSO — the two legs of the round trip, plus the account page that manages the links (Phase 3).
 *
 * The routes are always registered and every action asks the service whether SSO is enabled, so turning
 * the feature on is an env change and a `config:cache`, never a deploy that half-wires the login page to a
 * missing route. Disabled responses are 404 rather than 403: an installation that has not opted in should
 * not advertise an endpoint at all.
 */
class SsoController extends Controller
{
    public function __construct(private readonly SsoService $svc) {}

    /**
     * Send the browser to the provider. Used by the login page (guest) and by the account page (link).
     */
    public function redirect(Request $request, string $provider): RedirectResponse
    {
        $this->assertEnabled($provider);

        try {
            // The intent rides in the session, not in a query string a user might copy — it decides
            // whether the callback logs someone in or merely attaches a provider to the account already
            // signed in, and a URL is not a place to carry that choice.
            return redirect()->away($this->svc->begin($request, $provider, $request->user() ? 'link' : 'login'));
        } catch (\DomainException $e) {
            return $this->failure($request, $e);
        }
    }

    /**
     * The provider's answer. Everything that could reject this person happens inside the service.
     */
    public function callback(Request $request, string $provider): RedirectResponse
    {
        $this->assertEnabled($provider);

        try {
            $result = $this->svc->complete($request, $provider);
        } catch (\DomainException $e) {
            return $this->failure($request, $e);
        }

        if ($result['intent'] === 'link') {
            return redirect()
                ->route('core.sso.accounts')
                ->with('success', "Linked {$result['identity']->email} from {$provider} to this account.");
        }

        Auth::login($result['user'], true);
        $request->session()->regenerate();

        return redirect()
            ->intended(route('core.dashboard'))
            ->with('success', "Signed in as {$result['user']->name}.");
    }

    /**
     * The list of what is linked, and the only door to unlinking it.
     */
    public function accounts(Request $request): Response
    {
        $user = $request->user()->loadMissing('ssoIdentities');

        return Inertia::render('CORE::Sso/Accounts', [
            'identities' => $this->svc->identitiesFor($user),
            // What could still be linked: configured providers this account does not already use. Sent as
            // a map, not a list — the key is what the redirect route needs, and a label alone cannot
            // rebuild it.
            'available' => $this->svc->linkableProviders($user),
            'enabled' => $this->svc->enabled(),
            // Drives the form's shape, not a permission: one link and no proof of a password is the one
            // removal that can strand an account, so the page has to ask for the password.
            'needsPassword' => $user->ssoIdentities->count() <= 1,
        ]);
    }

    /**
     * Drop a link.
     *
     * The password arrives in the request body rather than through the `password.confirm` middleware, and
     * that is a deliberate trade: the middleware redirects to a confirmation page and then *intends* back
     * to the original URL, which for a DELETE is a 405 (a replayed GET does not carry the verb). Asking in
     * the form keeps the whole interaction inside one request, lets the service skip the question when
     * other links remain, and lets a wrong answer produce a readable flash instead of a bounce.
     */
    public function unlink(Request $request, SsoIdentity $identity): RedirectResponse
    {
        $request->validate(['password' => ['nullable', 'string', 'max:255']]);

        try {
            $this->svc->unlink($request->user(), $identity, $request->input('password'));

            return back()->with('success', 'Linked sign-in removed.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    private function assertEnabled(string $provider): void
    {
        abort_unless($this->svc->isConfigured($provider), 404);
    }

    /**
     * A refused SSO attempt reads as a flash on whichever page started it, never as a stack trace: the
     * person standing there is halfway through a redirect from a third party and has no context to parse
     * one. Guests land back on the login form; linking users land back on their accounts page.
     */
    private function failure(Request $request, \DomainException $e): RedirectResponse
    {
        $target = $request->user() ? route('core.sso.accounts') : route('login');

        return redirect()->to($target)->with('error', $e->getMessage());
    }
}
