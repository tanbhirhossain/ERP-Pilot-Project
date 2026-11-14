import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

import { directionFor, translate, type LocaleCode } from '../i18n';

/**
 * The language engine — U19, the front-end mirror of `useUiTheme`.
 *
 * One owner for two coupled attributes on `<html>`: the language (`lang`) and, from it, `dir`. Because
 * `dir` is derived from the code (`directionFor`), a page can never be left showing Bangla in a
 * left-to-right layout or Latin in a right-to-left one — the two are set together or not at all.
 *
 * The server is the source of truth: `SetLocale` middleware bakes `lang`/`dir` into the first paint and
 * shares the resolved `code`/`dir`, and `translate` on the client uses the same code. A change posts
 * to `core.locale.update` (which writes the session) and reloads the page props, so the next render
 * — including the server-rendered `<html>` on a full load — agrees with the shell. Nothing is stored
 * in `localStorage` on purpose: unlike the palette, the language lives in the session, so the server
 * always knows it and there is no second place that could disagree.
 */

export interface LocaleOption {
    id: string;
    label: string;
    native: string;
}

export interface LocaleProps {
    code: LocaleCode;
    dir: string;
    company: LocaleCode;
    locales: LocaleOption[];
}

/** Paint both attributes now, so the switch feels instant before the reload lands. */
export function applyDirection(code: string): void {
    if (typeof document === 'undefined') return;

    const root = document.documentElement;
    const dir = directionFor(code);

    root.setAttribute('dir', dir);
    root.setAttribute('lang', code);
}

export function useLocale() {
    const page = usePage<{ locale?: LocaleProps }>();

    const locale = computed<LocaleProps | undefined>(() => page.props.locale);
    const code = computed<LocaleCode>(() => locale.value?.code ?? 'en');
    const dir = computed(() => locale.value?.dir ?? directionFor(code.value));
    const locales = computed<LocaleOption[]>(() => locale.value?.locales ?? []);
    const companyCode = computed<LocaleCode>(() => locale.value?.company ?? 'en');
    const isRtl = computed(() => dir.value === 'rtl');

    /** `t('tax.vatReturn')` or `t('Dashboard')` — resolves via the pure kernel against the active code. */
    function t(key: string, params?: Record<string, string | number>): string {
        return translate(key, { locale: code.value }, params);
    }

    function setLocale(next: string): void {
        if (next === code.value) return;

        applyDirection(next); // optimistic mirror so the layout flips on the click, not after a reload
        // The PUT only persists the choice to the session; the visible flip is the line above, and
        // the next full load re-renders <html dir> server-side from the same session value.
        router.put(route('core.locale.update'), { locale: next }, { preserveScroll: true, preserveState: true });
    }

    return { code, dir, locales, companyCode, isRtl, t, setLocale };
}
