import vue from '@vitejs/plugin-vue';
import { fileURLToPath, URL } from 'node:url';
import { defineConfig } from 'vitest/config';

/**
 * Component-test configuration.
 *
 * Kept separate from `vite.config.ts` so the Laravel/Vite plugin (which needs
 * `vendor/` and the manifest) is never required just to run the unit tests.
 */
export default defineConfig({
    plugins: [vue()],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    test: {
        environment: 'jsdom',
        include: ['resources/js/**/*.test.ts', 'modules/**/Resources/js/**/*.test.ts'],
        globals: true,
    },
});
