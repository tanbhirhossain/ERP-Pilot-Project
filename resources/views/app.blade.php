<!DOCTYPE html>
@php
    // One resolver, two consumers: the same array paints the <html> element here and is shared with
    // Inertia by HandleInertiaRequests. UiTheme memoises the settings read, so this is not a second
    // query — and rendering it server-side is what keeps the first paint in the right palette.
    $ui = \Modules\CORE\Support\UiTheme::share(request()->user());
@endphp
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="{{ $ui['scheme'] }}" @class(['dark' => $ui['dark']])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />

        {{-- Ziggy emits the route table as an inline script, which the CSP blocks
             unless it carries this request's nonce. Vite mints the nonce in the
             SecurityHeaders middleware, so reading it back keeps one source of
             truth. It is null when security headers are disabled, and Ziggy then
             simply omits the attribute. --}}
        @php($cspNonce = \Illuminate\Support\Facades\Vite::cspNonce())
        @routes(null, $cspNonce)
        @vite(['resources/js/app.ts'])

        {{-- Pre-paint theme bootstrap.
             The attributes on <html> already cover a stored light/dark choice. Two cases they
             cannot: `system` mode, where the OS preference only exists in the browser, and a
             choice made in this browser that has not reached the account yet. Settling both here
             is what removes the flash of the wrong palette. The nonce comes from the same place
             Ziggy's does; if a stricter policy ever blocks the inline script, the server-rendered
             attributes above are the fallback and the page still paints in the stored scheme. --}}
        <script @if($cspNonce) nonce="{{ $cspNonce }}" @endif>
            (function () {
                var root = document.documentElement;
                var stored = null;

                try {
                    stored = JSON.parse(localStorage.getItem('core.ui.v1') || 'null');
                } catch (e) {
                    stored = null;
                }

                var scheme = (stored && stored.scheme) || root.getAttribute('data-theme') || 'indigo';
                var mode = (stored && stored.mode) || @json($ui['mode']);

                root.setAttribute('data-theme', scheme);

                var dark = mode === 'dark'
                    || (mode !== 'light' && window.matchMedia('(prefers-color-scheme: dark)').matches);

                root.classList.toggle('dark', dark);
            })();
        </script>

        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
