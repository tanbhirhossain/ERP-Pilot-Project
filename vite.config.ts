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
});
