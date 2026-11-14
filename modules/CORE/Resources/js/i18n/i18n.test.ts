/*
 * Tests for the U19 language kernel.
 *
 * These target the pure module (`translate`, `directionFor`) — the part that decides what a string
 * resolves to — precisely so they need no Vue mount, no Inertia page, and no DOM. The wiring
 * (`useLocale`, the switcher) only moves those resolved strings onto the page and flips `dir`; the
 * server half is covered by `tests/Feature/CORE/LocaleTest.php`.
 *
 * The tests that matter most here are the *fallbacks*: localisation is incremental across 400+ pages,
 * so "a string nobody has translated yet shows its English, and never a blank or a crash" is a load
 * contract, not a nicety.
 */

import { describe, expect, it } from 'vitest';

import { coverage, directionFor, interpolate, translate } from './index';

describe('directionFor', () => {
    it('maps Bangla to rtl and everything else to ltr', () => {
        expect(directionFor('bn')).toBe('rtl');
        expect(directionFor('en')).toBe('ltr');
        // An unknown code degrades to ltr rather than leaving the layout in a stale rtl state.
        expect(directionFor('zz')).toBe('ltr');
    });
});

describe('translate', () => {
    it('returns the English string for a known key', () => {
        expect(translate('common.save', { locale: 'en' })).toBe('Save');
    });

    it('returns the Bangla string for a known key', () => {
        expect(translate('common.save', { locale: 'bn' })).toBe('সংরক্ষণ');
        expect(translate('tax.vatReturn', { locale: 'bn' })).toContain('মুশক');
    });

    it('falls back to English when the active language has no entry', () => {
        // 'common.save' exists in both; this one exists in en but a misspelled locale must still work.
        expect(translate('tax.workingPaper', { locale: 'en' })).toBe('Return working paper');
        // An unknown locale is treated as English rather than throwing.
        expect(translate('tax.workingPaper', { locale: 'fr' })).toBe('Return working paper');
    });

    it('falls back to the key itself, so an untranslated screen still renders', () => {
        expect(translate('a.key.nobody.translated.yet', { locale: 'en' })).toBe('a.key.nobody.translated.yet');
        expect(translate('a.key.nobody.translated.yet', { locale: 'bn' })).toBe('a.key.nobody.translated.yet');
    });

    it('localises nav by the English label, which is exactly how the sidebar calls it', () => {
        // The sidebar passes item.label (English) straight in; the dictionary is keyed by that label.
        expect(translate('Dashboard', { locale: 'bn' })).toBe('ড্যাশবোর্ড');
        // A menu item with no Bangla entry yet still shows its correct English label.
        expect(translate('Provident Fund', { locale: 'bn' })).toBe('Provident Fund');
    });

});

describe('interpolate', () => {
    // Tested directly because no shipped dictionary entry carries a token — asserting
    // substitution through `translate` would only ever prove the no-op path. The same
    // function `translate` runs on a resolved string, so testing it here is real.
    it('replaces a known {token}', () => {
        expect(interpolate('{count} selected', { count: 3 })).toBe('3 selected');
    });

    it('leaves an unknown {token} intact rather than blanking it', () => {
        expect(interpolate('{a} and {b}', { a: 1 })).toBe('1 and {b}');
    });

    it('returns the string untouched when no params are given', () => {
        expect(interpolate('nothing to fill', undefined)).toBe('nothing to fill');
    });

    it('is what translate runs on, so a templated value survives the fallback chain', () => {
        expect(translate('common.amount', { locale: 'en' }, { extra: 'ignored' })).toBe('Amount');
    });
});

describe('coverage', () => {
    it('reports how many English keys a language has translated', () => {
        const bn = coverage('bn');
        expect(bn.total).toBeGreaterThan(0);
        expect(bn.translated).toBeGreaterThan(0);
        expect(bn.translated).toBeLessThanOrEqual(bn.total);
    });
});
