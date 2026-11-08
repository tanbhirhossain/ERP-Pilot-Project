import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import path from 'path';
import tailwindcss from '@tailwindcss/vite';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/app.ts'],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        tailwindcss(),
    ],
    resolve: {
        alias: {
            '@': path.resolve(__dirname, './resources/js'),
            '@core': path.resolve(__dirname, './modules/CORE/Resources/js'),
            '@hrm': path.resolve(__dirname, './modules/HRM/Resources/js'),
            '@partner': path.resolve(__dirname, './modules/PARTNER/Resources/js'),
            '@inventory': path.resolve(__dirname, './modules/INVENTORY/Resources/js'),
            '@accounts': path.resolve(__dirname, './modules/ACCOUNTS/Resources/js'),
            '@sales': path.resolve(__dirname, './modules/SALES/Resources/js'),
            '@purchase': path.resolve(__dirname, './modules/PURCHASE/Resources/js'),
        },
    },
    build: {
        rollupOptions: {
            output: {
                /**
                 * Split third-party code into stable vendor chunks.
                 *
                 * Without this, Rollup hoists shared library code into whichever
                 * component first pulls it in — radix-vue, cva and the ui-kit all
                 * landed in a chunk named "Button.vue" that was 126 kB (38 kB
                 * gzipped) and had to be re-downloaded whenever Button changed.
                 * Naming vendor chunks explicitly makes them cache across deploys
                 * and keeps app code small.
                 */
                manualChunks(id: string): string | undefined {
                    if (!id.includes('node_modules')) {
                        return undefined;
                    }
                    if (id.includes('lucide-vue-next')) {
                        return 'vendor-icons';
                    }
                    if (id.includes('radix-vue')) {
                        return 'vendor-radix';
                    }
                    if (id.includes('@vue') || id.includes('/vue/') || id.includes('@inertiajs')) {
                        return 'vendor-vue';
                    }
                    return 'vendor';
                },
            },
        },
    },
});
