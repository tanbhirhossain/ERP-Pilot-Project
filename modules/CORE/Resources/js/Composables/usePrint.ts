import { readonly, reactive } from 'vue';

/**
 * Print support for the whole shell — item 43.
 *
 * The defect this replaces: `AdminLayout.vue` carried a global `@media print`
 * rule that hid `body *` and revealed only `[data-core-print]`. Exactly one
 * component set that attribute, so every page without a DataTable printed a
 * blank sheet — the buttons worked, the stylesheet erased the page.
 *
 * The architecture now:
 *
 *   - `resources/css/print.css` owns every print rule. Region isolation is
 *     scoped to `html.is-printing-region`, which is only ever set when a
 *     `[data-core-print]` element actually exists on the page. No region, no
 *     isolation: the content column prints as authored.
 *   - `installPrintSupport()` is called once from `app.ts` and hooks
 *     `beforeprint`/`afterprint`, so *any* `window.print()` call in the
 *     codebase — including the ones in pages nobody migrates — gets the
 *     correct state. That is deliberate: a fix that depends on 40 files being
 *     edited is a fix that decays.
 *   - `printDocument()` is the explicit API for pages that want control
 *     (portrait documents, waiting for web fonts before the sheet renders).
 *   - `meta` is reactive sheet furniture: the timestamp is refreshed at print
 *     time, not at mount time, so a tab left open overnight does not print
 *     yesterday's date.
 */

/** The attribute a page sets on the one region that should reach paper. */
export const PRINT_REGION_SELECTOR = '[data-core-print]';

export interface PrintMeta {
    /** Locale timestamp of the current/last print, empty until one happens. */
    at: string;
    /** Sheet title, taken from the document title minus the app suffix. */
    title: string;
    /** True while a print is in flight. */
    printing: boolean;
    /** True when the page opted into region isolation. */
    region: boolean;
}

const state = reactive<PrintMeta>({ at: '', title: '', printing: false, region: false });

let installed = false;

function formatTimestamp(): string {
    try {
        return new Date().toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
    } catch {
        // A locale the engine cannot format must not break printing.
        return new Date().toISOString().slice(0, 16).replace('T', ' ');
    }
}

function sheetTitle(): string {
    return document.title.replace(/\s*[-|–]\s*[^-|–]*$/, '').trim() || 'Report';
}

function enterPrint(): void {
    const root = document.documentElement;
    const hasRegion = root.querySelector(PRINT_REGION_SELECTOR) !== null;

    root.classList.toggle('is-printing-region', hasRegion);
    root.classList.add('is-printing');

    state.at = formatTimestamp();
    state.title = sheetTitle();
    state.printing = true;
    state.region = hasRegion;
}

function leavePrint(): void {
    const root = document.documentElement;

    root.classList.remove('is-printing-region', 'is-printing', 'is-printing-portrait');

    state.printing = false;
}

/**
 * Global hooks, installed once from `app.ts`.
 *
 * `beforeprint` fires before the preview is painted, which is early enough to
 * add the isolation class and late enough that the page has rendered. Safari
 * only gained reliable `beforeprint`/`afterprint` recently, so `printDocument`
 * below applies the same state synchronously as well — the two paths agree,
 * and `enterPrint()` is idempotent when both run.
 */
export function installPrintSupport(): void {
    if (installed || typeof window === 'undefined') return;

    window.addEventListener('beforeprint', enterPrint);
    window.addEventListener('afterprint', leavePrint);

    installed = true;
}

export interface PrintOptions {
    /** Portrait sheet: payslips, leave applications, employee profiles. */
    portrait?: boolean;
    /**
     * Force region isolation on or off instead of detecting it. Useful for a
     * page that marks a region but sometimes wants the whole column.
     */
    region?: boolean;
}

export function usePrint() {
    function print(options: PrintOptions = {}): void {
        if (typeof window === 'undefined') return;

        enterPrint();

        const root = document.documentElement;

        if (options.portrait) root.classList.add('is-printing-portrait');
        if (options.region !== undefined) {
            root.classList.toggle('is-printing-region', options.region);
            state.region = options.region;
        }

        let settled = false;

        const finish = (): void => {
            if (settled) return;
            settled = true;
            window.removeEventListener('afterprint', finish);
            leavePrint();
        };

        window.addEventListener('afterprint', finish);

        const run = (): void => {
            window.print();
            // Belts and braces: an engine that never fires `afterprint` would
            // otherwise leave the shell stuck in its printing state, hidden
            // chrome and all.
            window.setTimeout(finish, 1000);
        };

        // Web fonts change metrics; printing before they settle reflows the
        // sheet after the preview was captured.
        const fonts = (document as Document & { fonts?: { ready: Promise<unknown> } }).fonts;

        if (fonts?.ready) {
            fonts.ready.then(() => window.requestAnimationFrame(run)).catch(run);
        } else {
            window.requestAnimationFrame(run);
        }
    }

    return { print, meta: readonly(state) };
}

/** One-call helper for `PageHeader` action arrays and inline handlers. */
export function printDocument(options: PrintOptions = {}): void {
    usePrint().print(options);
}

/** Reactive sheet furniture for the layout's letterhead and colophon. */
export function usePrintMeta() {
    return readonly(state);
}
