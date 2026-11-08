<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline HTTP security headers.
 *
 * Everything is driven by `config('core.security_headers')` so a deployment can
 * tighten or relax a single directive without a code change. The defaults are
 * chosen to be safe for this app as it stands — see the notes on CSP below.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $config = config('core.security_headers', []);

        if (($config['enabled'] ?? true) === false) {
            return $next($request);
        }

        /*
         * The nonce has to be minted BEFORE $next(), because that is where the
         * response body is rendered. Ziggy's `@routes` directive and Vite both
         * emit inline scripts and stamp them with whatever nonce is current at
         * render time; a nonce applied only to the header afterwards would
         * match nothing and the SPA would not boot.
         *
         * Vite owns the value so there is a single source of truth for the
         * header, the asset tags and the Blade directive.
         */
        $nonce = null;
        if (is_string($config['content_security_policy'] ?? null) && $config['content_security_policy'] !== '') {
            // Facade call: forwards to the bound Illuminate\Foundation\Vite
            // singleton. Resolving `Vite::class` here would build the *facade*
            // class itself, which has no instance methods.
            $nonce = Vite::useCspNonce();
            $request->attributes->set('csp_nonce', $nonce);
        }

        /** @var Response $response */
        $response = $next($request);

        // Never advertise the stack.
        $response->headers->remove('X-Powered-By');

        // Stops MIME-sniffing: a user-uploaded file served back with the wrong
        // type must not be reinterpreted as script by the browser.
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // This is an authenticated back office — it has no business being
        // framed by another origin (clickjacking).
        $response->headers->set('X-Frame-Options', $config['frame_options'] ?? 'SAMEORIGIN');

        $response->headers->set('Referrer-Policy', $config['referrer_policy'] ?? 'strict-origin-when-cross-origin');

        // The ERP needs none of these; denying them shrinks the browser attack
        // surface on shared office machines.
        $response->headers->set(
            'Permissions-Policy',
            $config['permissions_policy'] ?? 'camera=(), microphone=(), geolocation=(), browsing-topics=()'
        );

        // Deliberately 0. The legacy XSS auditor is itself a vulnerability
        // vector and is removed from modern browsers; CSP is the real control.
        $response->headers->set('X-XSS-Protection', '0');

        if ($request->secure() && ($hsts = $config['hsts_max_age'] ?? 31536000)) {
            $directive = 'max-age='.$hsts;
            if ($config['hsts_include_subdomains'] ?? true) {
                $directive .= '; includeSubDomains';
            }
            if ($config['hsts_preload'] ?? false) {
                $directive .= '; preload';
            }
            $response->headers->set('Strict-Transport-Security', $directive);
        }

        /*
         * Content-Security-Policy.
         *
         * `style-src` keeps 'unsafe-inline' on purpose: every Vue page in this
         * codebase binds element styles (`:style="{ width: pct + '%' }"`) for
         * progress bars and charts, and CSP treats a style attribute as inline.
         * Removing it would need a refactor to CSS custom properties — tracked
         * as follow-up work, not something to break the UI over silently.
         *
         * `script-src` does NOT allow 'unsafe-inline'. Vite emits hashed files
         * and Inertia's client-side navigation is script-free, so this holds.
         *
         * Set CORE_CSP_REPORT_ONLY=true to ship the policy in report-only mode
         * while you watch violations before enforcing.
         */
        if ($csp = $this->resolveCsp($config, $nonce)) {
            $header = ($config['csp_report_only'] ?? false)
                ? 'Content-Security-Policy-Report-Only'
                : 'Content-Security-Policy';
            $response->headers->set($header, $csp);
        }

        return $response;
    }

    /**
     * Pick the policy to send.
     *
     * When `npm run dev` is serving assets, they come from the Vite dev server
     * on another origin and hot-module-reload needs a websocket. The production
     * policy would block both and leave a developer staring at a blank page, so
     * the dev origins are added only while the dev server is actually hot —
     * never in a real deployment, where Vite::isRunningHot() is false.
     *
     * @param  array<string, mixed>  $config
     */
    private function resolveCsp(array $config, ?string $nonce = null): ?string
    {
        $csp = $config['content_security_policy'] ?? null;

        if (! is_string($csp) || $csp === '') {
            return null;
        }

        if ($nonce !== null && $nonce !== '') {
            $csp = $this->applyNonce($csp, $nonce);
        }

        if (! Vite::isRunningHot()) {
            return $csp;
        }

        // public/hot holds the dev server URL while `npm run dev` is up.
        $hotFile = public_path('hot');
        $origin = is_readable($hotFile) ? trim((string) file_get_contents($hotFile)) : '';
        if ($origin === '' || ! preg_match('#^https?://#', $origin)) {
            $origin = 'http://localhost:5173';
        }
        $origin = rtrim($origin, '/');
        $wsOrigin = preg_replace('#^http#', 'ws', $origin) ?? $origin;

        $dev = [
            "script-src 'self' 'unsafe-inline' {$origin}",
            "connect-src 'self' {$origin} {$wsOrigin}",
        ];

        // Replace the strict production directives rather than appending, so a
        // duplicated key does not make browsers fall back to the tighter one.
        foreach ($dev as $directive) {
            $key = explode(' ', $directive)[0];
            $csp = preg_replace('#(?:^|;\s*)'.preg_quote($key, '#').'[^;]*#', '', $csp) ?? $csp;
        }

        return trim(preg_replace('#;{2,}#', ';', $csp) ?? $csp, '; ').'; '.implode('; ', $dev);
    }

    /**
     * Append the per-request nonce to the script directives.
     *
     * Only script-src / script-src-elem are touched. Adding a nonce to style-src
     * would make CSP level 2+ browsers discard its 'unsafe-inline', which the
     * Vue `:style` bindings in this codebase depend on.
     */
    private function applyNonce(string $csp, string $nonce): string
    {
        $token = "'nonce-".$nonce."'";

        $result = preg_replace_callback(
            '#(?:^|;\\s*)(script-src(?:-elem)?)([^;]*)#',
            function (array $m) use ($token): string {
                return '; '.$m[1].$m[2].(str_contains($m[2], $token) ? '' : ' '.$token);
            },
            $csp
        );

        if (! is_string($result)) {
            return $csp;
        }

        // The callback always prefixes '; ', so strip the leading separator.
        return ltrim($result, '; ');
    }
}
