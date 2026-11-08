/*
 * Tests for the theme engine's DOM and storage half — item 42.
 *
 * `useUiTheme()` itself needs an Inertia page context, and its server-side half (precedence,
 * validation, persistence) is covered by ThemePaletteTest and UiSettingsTest in PHP. What only a
 * browser can answer is the part that runs before Vue mounts: which two attributes end up on <html>,
 * what shape lands in localStorage, and whether an upgrade from the starter kit's single `appearance`
 * key still respects the dark mode somebody had already chosen.
 *
 * The storage shape is a cross-language contract — the inline bootstrap in app.blade.php reads the
 * same key and the same field names, and ThemePaletteTest pins its side of it.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
    applyUi,
    DEFAULT_MODE,
    DEFAULT_SCHEME,
    initializeUiTheme,
    setUiLocally,
    UI_STORAGE_KEY,
} from './useUiTheme';

/** jsdom has no matchMedia; the engine guards for it, and `system` mode needs a controllable one. */
function stubMedia(prefersDark: boolean): void {
    Object.defineProperty(window, 'matchMedia', {
        writable: true,
        configurable: true,
        value: vi.fn().mockImplementation((query: string) => ({
            matches: query.includes('dark') ? prefersDark : false,
            media: query,
            addEventListener: vi.fn(),
            removeEventListener: vi.fn(),
            addListener: vi.fn(),
            removeListener: vi.fn(),
            onchange: null,
            dispatchEvent: vi.fn(),
        })),
    });
}

describe('useUiTheme', () => {
    beforeEach(() => {
        document.documentElement.className = '';
        document.documentElement.removeAttribute('data-theme');
        localStorage.clear();
        stubMedia(false);
    });

    afterEach(() => {
        document.documentElement.className = '';
        localStorage.clear();
        vi.restoreAllMocks();
    });

    it('paints both attributes from one call', () => {
        applyUi('rose', 'dark');

        expect(document.documentElement.getAttribute('data-theme')).toBe('rose');
        expect(document.documentElement.classList.contains('dark')).toBe(true);

        applyUi('teal', 'light');

        expect(document.documentElement.getAttribute('data-theme')).toBe('teal');
        expect(document.documentElement.classList.contains('dark')).toBe(false);
    });

    it('follows the operating system while the mode is system', () => {
        stubMedia(true);
        applyUi('indigo', 'system');
        expect(document.documentElement.classList.contains('dark')).toBe(true);

        stubMedia(false);
        applyUi('indigo', 'system');
        expect(document.documentElement.classList.contains('dark')).toBe(false);
    });

    it('never leaves <html> without a palette', () => {
        applyUi('', 'light');

        expect(document.documentElement.getAttribute('data-theme')).toBe(DEFAULT_SCHEME);
    });

    it('writes the exact storage shape the blade bootstrap reads', () => {
        setUiLocally({ scheme: 'emerald', mode: 'dark' });

        const raw = localStorage.getItem(UI_STORAGE_KEY);

        expect(raw).not.toBeNull();
        expect(JSON.parse(raw as string)).toEqual({ scheme: 'emerald', mode: 'dark' });
        expect(UI_STORAGE_KEY).toBe('core.ui.v1');
        expect(DEFAULT_MODE).toBe('system');
    });

    it('keeps the other half of the preference when only one changes', () => {
        setUiLocally({ scheme: 'amber' });
        setUiLocally({ mode: 'dark' });

        expect(JSON.parse(localStorage.getItem(UI_STORAGE_KEY) as string)).toEqual({
            scheme: 'amber',
            mode: 'dark',
        });
        expect(document.documentElement.getAttribute('data-theme')).toBe('amber');
        expect(document.documentElement.classList.contains('dark')).toBe(true);
    });

    it('adopts a dark-mode choice made before the palette existed', () => {
        // The starter kit stored a bare 'light' | 'dark' | 'system' string under `appearance`.
        localStorage.setItem('appearance', 'dark');

        initializeUiTheme();

        expect(JSON.parse(localStorage.getItem(UI_STORAGE_KEY) as string).mode).toBe('dark');
        expect(document.documentElement.classList.contains('dark')).toBe(true);
    });

    it('prefers a stored scheme over the one the server rendered', () => {
        document.documentElement.setAttribute('data-theme', 'royal');
        setUiLocally({ scheme: 'graphite' });

        initializeUiTheme();

        expect(document.documentElement.getAttribute('data-theme')).toBe('graphite');
    });

    it('falls back to the server-rendered scheme when nothing is stored', () => {
        document.documentElement.setAttribute('data-theme', 'violet');

        initializeUiTheme();

        expect(document.documentElement.getAttribute('data-theme')).toBe('violet');
    });

    it('ignores a corrupt stored preference instead of failing the paint', () => {
        localStorage.setItem(UI_STORAGE_KEY, '{not json');
        document.documentElement.setAttribute('data-theme', 'teal');

        expect(() => initializeUiTheme()).not.toThrow();
        expect(document.documentElement.getAttribute('data-theme')).toBe('teal');
    });
});
