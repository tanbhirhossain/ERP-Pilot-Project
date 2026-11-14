<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Modules\CORE\Support\Locale;
use Modules\CORE\Support\UiTheme;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        [$message, $author] = str(Inspiring::quotes()->random())->explode('-');

        // Registration is opt-in (see core.auth.allow_registration). When it is
        // off the `register` route is not registered at all, so the front end
        // must not build links to it — Ziggy throws on an unknown route name.
        $canRegister = (bool) config('core.auth.allow_registration');

        return array_merge(parent::share($request), [
            'name' => config('app.name'),
            'quote' => ['message' => trim($message), 'author' => trim($author)],
            'canRegister' => $canRegister,
            // Colour scheme and mode, resolved once per request and memoised:
            // user override, else company default, else the catalogue default.
            // Trimmed shape — see UiTheme::share(). The shell reads it to set
            // `data-theme` on <html>, and app.blade.php renders the same value
            // server-side so the first paint is already correct.
            'ui' => UiTheme::share($request->user()),
            // U19: active UI language + direction + the switcher's option list. Resolved by the
            // SetLocale middleware that runs before this one, so `dir` here always matches the
            // `dir` the server rendered into <html>.
            'locale' => Locale::share(),
            'auth' => [
                // Whitelisted: the full User model carries every column and any
                // loaded relation into the browser on every request.
                'user' => $request->user() ? [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                    'email' => $request->user()->email,
                    'email_verified_at' => $request->user()->email_verified_at,
                    'created_at' => $request->user()->created_at,
                    'updated_at' => $request->user()->updated_at,
                ] : null,
            ],
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                'warning' => $request->session()->get('warning'),
                'info' => $request->session()->get('info'),
                'preview' => $request->session()->get('preview'),
                'result' => $request->session()->get('result'),
            ],
            // Keep legacy root props available to existing Inertia pages.
            'preview' => $request->session()->get('preview'),
            'result' => $request->session()->get('result'),
        ]);
    }
}
