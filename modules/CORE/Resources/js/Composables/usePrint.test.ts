/*
 * Tests for the print engine — item 43.
 *
 * The defect these guard is a blank sheet. The old stylesheet hid `body *` and revealed only a
 * region attribute that one component set, so most pages printed nothing at all. The behaviour that
 * has to hold is therefore a negative one: with no region marked, the html element must NOT be put
 * into isolation, because that class is what blanks the page. Test 1 is the regression, and it is
 * written first because it is the one that was broken.
 *
 * jsdom has no print pipeline, so `window.print` is stubbed and the assertions are about the classes
 * the engine puts on <html> — which is exactly what resources/css/print.css keys off.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { installPrintSupport, printDocument, usePrint } from './usePrint';

/**
 * Wait for the engine's deferral.
 *
 * `print()` hands `window.print()` to `requestAnimationFrame`, so a `setTimeout(0)` flush is not
 * enough — jsdom schedules frames on a longer timer and the spy is still uncalled when the
 * assertion runs. Awaiting a real frame is both deterministic and the thing actually being waited on.
 */
async function flush(): Promise<void> {
    await new Promise((resolve) => requestAnimationFrame(() => resolve(null)));
    await new Promise((resolve) => setTimeout(resolve, 0));
}

describe('usePrint', () => {
    let printed: ReturnType<typeof vi.spyOn>;

    beforeEach(() => {
        document.documentElement.className = '';
        document.body.innerHTML = '<main><div class="content">data</div></main>';
        localStorage.clear();
        printed = vi.spyOn(window, 'print').mockImplementation(() => {});
    });

    afterEach(() => {
        printed.mockRestore();
        document.documentElement.className = '';
        document.body.innerHTML = '';
    });

    it('prints a page that never marked a region, instead of blanking it', async () => {
        printDocument();
        await flush();

        expect(printed).toHaveBeenCalledTimes(1);
        expect(document.documentElement.classList.contains('is-printing-region')).toBe(false);
        expect(document.documentElement.classList.contains('is-printing')).toBe(true);
    });

    it('isolates the region when the page marks one', async () => {
        document.body.innerHTML = '<main><div data-core-print><table><tr><td>rows</td></tr></table></div></main>';

        printDocument();
        await flush();

        expect(document.documentElement.classList.contains('is-printing-region')).toBe(true);
    });

    it('lets a document ask for the portrait sheet', async () => {
        printDocument({ portrait: true });
        await flush();

        expect(document.documentElement.classList.contains('is-printing-portrait')).toBe(true);
    });

    it('can force isolation off on a page that does mark a region', async () => {
        document.body.innerHTML = '<main><div data-core-print>x</div></main>';

        printDocument({ region: false });
        await flush();

        expect(document.documentElement.classList.contains('is-printing-region')).toBe(false);
    });

    it('restores the shell when the print dialog closes', async () => {
        document.body.innerHTML = '<main><div data-core-print>x</div></main>';

        printDocument({ portrait: true });
        await flush();

        expect(document.documentElement.classList.contains('is-printing')).toBe(true);

        window.dispatchEvent(new Event('afterprint'));

        expect(document.documentElement.classList.contains('is-printing')).toBe(false);
        expect(document.documentElement.classList.contains('is-printing-region')).toBe(false);
        expect(document.documentElement.classList.contains('is-printing-portrait')).toBe(false);
    });

    it('stamps the sheet furniture at print time, not at mount time', async () => {
        const { meta } = usePrint();

        // `meta` is module-level and every earlier test in this file has printed, so "starts empty"
        // is not a claim this test can make. The claim that matters is the mechanism: the timestamp
        // is *produced* by the print, which is what stops a tab left open overnight from printing
        // yesterday's date on the letterhead.
        const stamp = vi.spyOn(Date.prototype, 'toLocaleString');

        printDocument();
        await flush();

        expect(stamp).toHaveBeenCalled();
        expect(meta.at).not.toBe('');
        // A timestamp the letterhead can print, and that a reader can parse.
        expect(Number.isNaN(Date.parse(meta.at))).toBe(false);
        expect(meta.title).not.toBe('');

        stamp.mockRestore();
    });

    it('makes any window.print() behave, through the global hook', async () => {
        installPrintSupport();

        // A page nobody migrated: the hook decides from the DOM at print time.
        window.dispatchEvent(new Event('beforeprint'));
        expect(document.documentElement.classList.contains('is-printing-region')).toBe(false);

        document.body.innerHTML = '<main><div data-core-print>x</div></main>';

        window.dispatchEvent(new Event('beforeprint'));
        expect(document.documentElement.classList.contains('is-printing-region')).toBe(true);

        window.dispatchEvent(new Event('afterprint'));
        expect(document.documentElement.classList.contains('is-printing-region')).toBe(false);
    });
});
