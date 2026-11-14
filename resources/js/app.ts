import '../css/app.css';

import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import type { DefineComponent } from 'vue';
import { createApp, h } from 'vue';
// From the npm package, not a deep path into `vendor/`: the Composer directory
// is gitignored and regenerated, so importing through it made `npm run build`
// depend on `composer install` having already run. `ziggy-js` is already a
// direct dependency and exports the same ZiggyVue plugin.
import { ZiggyVue } from 'ziggy-js';
import { installPrintSupport } from '@core/Composables/usePrint';
import { initializeUiTheme } from '@core/Composables/useUiTheme';

// Extend ImportMeta interface for Vite...
declare module 'vite/client' {
    interface ImportMetaEnv {
        readonly VITE_APP_NAME: string;
        [key: string]: string | boolean | undefined;
    }

    interface ImportMeta {
        readonly env: ImportMetaEnv;
        readonly glob: <T>(pattern: string) => Record<string, () => Promise<T>>;
    }
}

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';
const appPages = import.meta.glob<DefineComponent>('./pages/**/*.vue');
const modulePages = import.meta.glob<DefineComponent>('../../modules/*/Resources/js/Pages/**/*.vue');

function resolveInertiaPage(name: string) {
    const modulePage = name.match(/^([^:]+)::(.+)$/);

    if (modulePage) {
        const [, moduleName, pageName] = modulePage;

        return resolvePageComponent(`../../modules/${moduleName}/Resources/js/Pages/${pageName}.vue`, modulePages);
    }

    return resolvePageComponent(`./pages/${name}.vue`, appPages);
}

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => resolveInertiaPage(name),
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: {
        color: '#e01f29',
    },
});

/*
 * U21 — the installable shell.
 *
 * Registered here rather than from an inline script for one reason: the app's Content-Security-Policy
 * allows `script-src 'self'` with a per-request nonce, and a nonce is only minted for what the server
 * knows about. A module script is 'self' and needs no nonce; a service worker also has to be served
 * from the origin root, which `public/sw.js` is.
 *
 * Production only. In development, Vite's HMR rewrites module URLs constantly and a cached shell
 * fighting a hot-reloading server produces the most confusing class of bug there is — a page that is
 * stale in a way nobody can see.
 */
if (import.meta.env.PROD && 'serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker
            .register('/sw.js', { scope: '/' })
            // A failure to register must not look like a failure of the app: the till still queues
            // sales (that is IndexedDB, not the worker), it just cannot cold-start offline.
            .catch(() => undefined);
    });
}

document.documentElement.classList.add('page-ready');
router.on('start', () => document.documentElement.classList.add('inertia-nav'));
router.on('finish', () => document.documentElement.classList.remove('inertia-nav'));

// Palette and light/dark mode on page load. The inline bootstrap in
// app.blade.php has already painted both before first frame; this reconciles
// them with the account's stored preference and starts following the OS while
// the mode is `system`. `useAppearance.initializeTheme()` is a shim over it.
initializeUiTheme();

// Global print hooks: any `window.print()` in the codebase now prints the
// document rather than a blank sheet (see usePrint for why this is global).
installPrintSupport();
