import { onMounted, ref } from 'vue';

import { DEFAULT_MODE, initializeUiTheme, setUiLocally, type UiMode } from '../../../modules/CORE/Resources/js/Composables/useUiTheme';

/**
 * Light/dark/system, kept as the API the shell header toggle already calls. The starter kit's
 * `AppearanceTabs.vue` and its `settings/Appearance` page were deleted at item 42 — `settings/
 * appearance` is a redirect to the ERP's own UI Settings now — so this file's only remaining
 * caller is `AdminLayout`, which keeps using it for the legacy `appearance` key.
 *
 * Item 42 moved the real work into `modules/CORE/Resources/js/Composables/useUiTheme.ts`, which
 * owns both attributes on `<html>` — the `dark` class *and* `data-theme`. Two engines writing the
 * same class would race, so this file now delegates: `updateTheme` is a local-only mode write, and
 * `initializeTheme` is the boot hook. Anything that needs to persist a choice to the account, or to
 * change palette, should use `useUiTheme` directly.
 */

type Appearance = UiMode;

export function updateTheme(value: Appearance): void {
    setUiLocally({ mode: value });
}

export function initializeTheme(): void {
    initializeUiTheme();
}

export function useAppearance() {
    const appearance = ref<Appearance>(DEFAULT_MODE);

    onMounted(() => {
        initializeUiTheme();

        const stored = window.localStorage.getItem('appearance') as Appearance | null;

        if (stored === 'light' || stored === 'dark' || stored === 'system') {
            appearance.value = stored;
        }
    });

    function updateAppearance(value: Appearance): void {
        appearance.value = value;

        // The legacy key stays written because this composable's own `ref` reads it back on mount,
        // and useUiTheme adopts it for accounts that have no stored mode of their own.
        try {
            window.localStorage.setItem('appearance', value);
        } catch {
            /* private mode */
        }

        setUiLocally({ mode: value });
    }

    return { appearance, updateAppearance };
}
