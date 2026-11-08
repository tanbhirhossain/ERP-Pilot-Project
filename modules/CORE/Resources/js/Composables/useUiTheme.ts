import { router, usePage } from '@inertiajs/vue3';
import { computed, readonly, ref, watch } from 'vue';

/**
 * The colour-scheme and mode engine — item 42.
 *
 * One owner for two attributes on `<html>`: `data-theme`, which selects a palette from
 * `resources/css/themes.css`, and the `dark` class, which selects the mode. Everything else in the
 * application paints from tokens, so those two attributes are the whole switching mechanism.
 *
 * Persistence is deliberately two-layered:
 *
 *   - `localStorage['core.ui.v1']` makes the next paint instant, including the pre-hydration
 *     bootstrap in `app.blade.php`, which reads the same key and shape.
 *   - the account (`users.ui_scheme` / `users.ui_mode`) makes the choice follow the person to
 *     another machine, and lets the company default apply to everyone who has no opinion.
 *
 * Writing both on every change is not redundant: local-only would reset on a new device, and
 * server-only would flash the stored scheme for one frame on every load while the request is in
 * flight.
 */

export type UiMode = 'light' | 'dark' | 'system';

export interface UiSchemeOption {
    id: string;
    label: string;
    swatch: string;
    description?: string;
}

export interface UiProps {
    scheme: string;
    mode: UiMode;
    /** Which layer produced the effective scheme: the user, the company, or the catalogue default. */
    source: 'user' | 'company' | 'default';
    company_scheme: string;
    company_mode: UiMode;
    user_scheme: string | null;
    user_mode: string | null;
    dark: boolean;
    can_manage_company: boolean;
    schemes: UiSchemeOption[];
    modes: string[];
}

interface StoredUi {
    scheme?: string;
    mode?: UiMode;
}

/** Must match the key read by the inline bootstrap in `resources/views/app.blade.php`. */
export const UI_STORAGE_KEY = 'core.ui.v1';

export const DEFAULT_SCHEME = 'indigo';
export const DEFAULT_MODE: UiMode = 'system';

function readStored(): StoredUi {
    if (typeof window === 'undefined') return {};

    try {
        const raw = window.localStorage.getItem(UI_STORAGE_KEY);

        return raw ? (JSON.parse(raw) as StoredUi) : {};
    } catch {
        // Unreadable or corrupt storage is not worth failing a paint over.
        return {};
    }
}

function writeStored(next: StoredUi): void {
    if (typeof window === 'undefined') return;

    try {
        window.localStorage.setItem(UI_STORAGE_KEY, JSON.stringify(next));
    } catch {
        /* private mode — the account copy still holds the choice */
    }
}

/** True when the OS prefers dark. Only meaningful in a browser. */
function systemPrefersDark(): boolean {
    if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') return false;

    return window.matchMedia('(prefers-color-scheme: dark)').matches;
}

/** The key the starter kit's appearance toggle used, migrated on first run. */
const LEGACY_MODE_KEY = 'appearance';

/** Paint both attributes. Idempotent, and safe to call before hydration. */
export function applyUi(scheme: string, mode: UiMode): void {
    if (typeof document === 'undefined') return;

    const root = document.documentElement;

    root.setAttribute('data-theme', scheme || DEFAULT_SCHEME);
    root.classList.toggle('dark', mode === 'dark' || (mode === 'system' && systemPrefersDark()));
}

/**
 * Apply and remember locally, without writing to the account.
 *
 * Two callers need this: the legacy appearance shim (`useAppearance`, still used by the starter
 * kit's tabs) and any preview that must not persist — hovering a swatch in the picker should show
 * the palette, not commit it.
 */
export function setUiLocally(next: { scheme?: string; mode?: UiMode }): void {
    if (typeof window === 'undefined') return;

    const merged: StoredUi = { ...readStored(), ...next };

    writeStored(merged);
    applyUi(
        merged.scheme || document.documentElement.getAttribute('data-theme') || DEFAULT_SCHEME,
        merged.mode || DEFAULT_MODE,
    );
}

let mediaListenerAttached = false;

/**
 * Boot hook, called once from `app.ts`.
 *
 * Reconciles the three places a preference can live — the server-rendered attributes, localStorage
 * and the account — and then follows the OS while the mode is `system`. Without the listener a
 * laptop lid opening in a dark room leaves the shell in light mode until the next navigation.
 */
export function initializeUiTheme(): void {
    if (typeof window === 'undefined') return;

    const root = document.documentElement;
    const stored = readStored();

    // Somebody who chose dark mode before item 42 chose it under the starter kit's key. Adopting it
    // is one read, and the alternative is every existing user being flipped to light on upgrade.
    if (stored.mode === undefined) {
        const legacy = window.localStorage.getItem(LEGACY_MODE_KEY) as UiMode | null;

        if (legacy === 'light' || legacy === 'dark' || legacy === 'system') {
            stored.mode = legacy;
            writeStored(stored);
        }
    }

    applyUi(stored.scheme || root.getAttribute('data-theme') || DEFAULT_SCHEME, stored.mode || DEFAULT_MODE);

    if (mediaListenerAttached || typeof window.matchMedia !== 'function') return;

    const query = window.matchMedia('(prefers-color-scheme: dark)');
    const onChange = (): void => {
        const mode = readStored().mode;

        // Only a `system` preference follows the OS; an explicit choice must not.
        if (mode === 'system' || mode === undefined) {
            root.classList.toggle('dark', query.matches);
        }
    };

    if (typeof query.addEventListener === 'function') query.addEventListener('change', onChange);
    else query.addListener(onChange);

    mediaListenerAttached = true;
}

export interface SetOptions {
    /** Write through to the account. Off for the company-default form, which has its own save. */
    persist?: boolean;
}

export function useUiTheme() {
    // The generic is the props type itself: wrapping it in `{ props: … }` types `page.props.ui` as
    // unknown, which is what the overload error on the computed below was reporting.
    const page = usePage<{ ui?: UiProps }>();

    const ui = computed<UiProps | undefined>(() => page.props.ui);

    const stored = readStored();
    const scheme = ref<string>(ui.value?.scheme ?? stored.scheme ?? DEFAULT_SCHEME);
    const mode = ref<UiMode>(ui.value?.mode ?? stored.mode ?? DEFAULT_MODE);

    // A successful save comes back with fresh props; the local refs follow so the page and the
    // shell never disagree about what is selected.
    watch(
        () => ui.value,
        (next) => {
            if (!next) return;
            scheme.value = next.scheme;
            mode.value = next.mode;
        },
    );

    const schemes = computed<UiSchemeOption[]>(() => ui.value?.schemes ?? []);
    const source = computed(() => ui.value?.source ?? 'default');
    const companyScheme = computed(() => ui.value?.company_scheme ?? DEFAULT_SCHEME);
    const companyMode = computed(() => ui.value?.company_mode ?? DEFAULT_MODE);
    const canManageCompany = computed(() => ui.value?.can_manage_company ?? false);
    const isOverridden = computed(
        () => Boolean(ui.value?.user_scheme) || Boolean(ui.value?.user_mode),
    );

    function labelFor(id: string): string {
        return schemes.value.find((option) => option.id === id)?.label ?? id;
    }

    function setScheme(id: string, options: SetOptions = {}): void {
        scheme.value = id;
        applyUi(id, mode.value);
        writeStored({ ...readStored(), scheme: id });

        if (options.persist === false) return;

        router.put(route('core.settings.appearance.update'), { scheme: id }, { preserveScroll: true, preserveState: true });
    }

    function setMode(next: UiMode, options: SetOptions = {}): void {
        mode.value = next;
        applyUi(scheme.value, next);
        writeStored({ ...readStored(), mode: next });

        if (options.persist === false) return;

        router.put(route('core.settings.appearance.update'), { mode: next }, { preserveScroll: true, preserveState: true });
    }

    /** Drop both overrides and fall back to the company default. */
    function resetToCompany(): void {
        writeStored({ scheme: companyScheme.value, mode: companyMode.value });
        applyUi(companyScheme.value, companyMode.value);

        router.put(
            route('core.settings.appearance.update'),
            { scheme: null, mode: null },
            { preserveScroll: true, preserveState: true },
        );
    }

    function saveCompany(nextScheme: string, nextMode: UiMode): void {
        router.put(
            route('core.settings.appearance.company'),
            { scheme: nextScheme, mode: nextMode },
            { preserveScroll: true },
        );
    }

    /** The dark-mode toggle in the shell header, expressed against this engine. */
    function toggleMode(): void {
        const dark = document.documentElement.classList.contains('dark');

        setMode(dark ? 'light' : 'dark');
    }

    return {
        scheme: readonly(scheme),
        mode: readonly(mode),
        schemes,
        source,
        companyScheme,
        companyMode,
        canManageCompany,
        isOverridden,
        labelFor,
        setScheme,
        setMode,
        toggleMode,
        resetToCompany,
        saveCompany,
        ui,
    };
}
