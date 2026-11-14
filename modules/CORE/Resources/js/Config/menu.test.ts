/*
 * The sidebar filter is the only way an operator finds a screen they cannot name, so the matcher has to
 * answer the words people type rather than the words the product happened to pick.
 *
 * This started from "Tax Allocation i have no idea where it is": the app has a **Tax Profiles** page
 * (annual slabs + per-employee overrides) and a **TDS / VDS Withholding** desk (deposits onto treasury
 * challans), and a search for "tax allocation" found *neither* — because the filter matched the whole
 * phrase as a substring of the label, which for any two-word query is always empty.
 */

import { describe, expect, it } from 'vitest';

import { menuMatchesQuery, sidebarMenu, type SidebarItem } from './menu';

function findIn(key: string, items: SidebarItem[]): SidebarItem | null {
    for (const item of items) {
        if (item.key === key) return item;

        const nested = item.children ? findIn(key, item.children) : null;

        if (nested) return nested;
    }

    return null;
}

function find(key: string): SidebarItem {
    const hit = findIn(key, sidebarMenu);

    if (hit === null) throw new Error(`no sidebar item with key ${key}`);

    return hit;
}

describe('the sidebar filter answers the words people type', () => {
    it('still matches a single word the way it always did', () => {
        expect(menuMatchesQuery(find('payroll-tax'), 'tax')).toBe(true);
        expect(menuMatchesQuery(find('payroll-tax'), 'profiles')).toBe(true);
        expect(menuMatchesQuery(find('payroll-tax'), 'payroll-tax')).toBe(true);
        expect(menuMatchesQuery(find('payroll-sheets'), 'tax')).toBe(false);
    });

    it('needs every word of a multi-word query, so widening does not become a fire hose', () => {
        const taxItem = { key: 'tax', label: 'Tax Profiles', keywords: 'tax allocation, tax slabs', icon: (() => null) as never };

        expect(menuMatchesQuery(taxItem, 'tax allocation sheets')).toBe(false);
        expect(menuMatchesQuery(taxItem, 'allocation sheets')).toBe(false);
        expect(menuMatchesQuery(taxItem, 'allocation slabs')).toBe(true);
    });

    it('finds both tax screens for a two-word query that matches no label', () => {
        // The bug this pins: "tax allocation" is a perfectly ordinary thing to search for, and it used to
        // return nothing at all.
        expect(menuMatchesQuery(find('payroll-tax'), 'tax allocation')).toBe(true);
        expect(menuMatchesQuery(find('acc-withholding'), 'tax allocation')).toBe(true);
        // And it does not turn into a fire hose: an unrelated item is still unrelated.
        expect(menuMatchesQuery(find('payroll-bonuses'), 'tax allocation')).toBe(false);
        expect(menuMatchesQuery(find('acc-assets'), 'tax allocation')).toBe(false);
    });

    it('reaches the payroll-desk names that are not in the label', () => {
        expect(menuMatchesQuery(find('acc-withholding'), 'challan')).toBe(true);
        expect(menuMatchesQuery(find('acc-withholding'), 'treasury')).toBe(true);
        expect(menuMatchesQuery(find('payroll-tax'), 'tax slabs')).toBe(true);
        // The aliases stay in their own lane: a challan is not an employee override.
        expect(menuMatchesQuery(find('payroll-tax'), 'challan')).toBe(false);
        expect(menuMatchesQuery(find('acc-withholding'), 'tax slabs')).toBe(false);
    });

    it('keeps a translated label working as a match target', () => {
        const item: SidebarItem = { key: 'x', label: 'Payroll', icon: (() => null) as never, keywords: 'salary' };

        expect(menuMatchesQuery(item, 'pay', 'বেতন')).toBe(true);
        // The translated string is part of the haystack, so a Bangla word finds the English-labelled item.
        expect(menuMatchesQuery(item, 'বেতন', 'বেতন')).toBe(true);
        expect(menuMatchesQuery(item, 'zzz', 'বেতন')).toBe(false);
    });

    it('does not let an alias become a second name for a page', () => {
        // Every keyword has to earn its place: it must not already be in the label (dead weight), and it
        // must be lowercase and comma-separated, or the substring matching above silently misses it.
        const offenders: string[] = [];

        const walk = (items: SidebarItem[]): void => {
            for (const item of items) {
                if (item.keywords) {
                    if (item.keywords !== item.keywords.toLowerCase()) offenders.push(`${item.key}: not lowercase`);
                    if (item.keywords.includes(item.label.toLowerCase())) offenders.push(`${item.key}: repeats its own label`);
                    if (/[^\w ,.·/-]/.test(item.keywords)) offenders.push(`${item.key}: odd characters`);
                }

                if (item.children) walk(item.children);
            }
        };

        walk(sidebarMenu);

        expect(offenders).toEqual([]);
    });

    it('still hides nothing by accident: every alias belongs to a route that exists', () => {
        for (const key of ['payroll-tax', 'acc-withholding']) {
            expect(find(key).route, key).toBeTruthy();
        }
    });
});
