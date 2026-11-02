import '../css/app.css';

import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import type { DefineComponent } from 'vue';
import { createApp, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import { initializeTheme } from './composables/useAppearance';

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

document.documentElement.classList.add('page-ready');
router.on('start', () => document.documentElement.classList.add('inertia-nav'));
router.on('finish', () => document.documentElement.classList.remove('inertia-nav'));

// This will set light / dark mode on page load...
initializeTheme();
