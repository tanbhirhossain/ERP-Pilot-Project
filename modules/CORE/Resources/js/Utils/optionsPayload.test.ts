/*
 * The modules never agreed on a JSON envelope for their typeahead endpoints.
 *
 * PARTNER, INVENTORY, SALES and ACCOUNTS return `{data:[…]}`; CORE and HRM
 * return `{items:[…]}`. SearchableSelect originally read only `items`, so every
 * vendor / warehouse / product / account picker in the ERP silently rendered an
 * empty list — indistinguishable from "no matches", which is why it went
 * unnoticed. These pin every shape the component must accept.
 */

import { describe, expect, it } from 'vitest';

import { unwrapOptions } from './optionsPayload';

const rows = [
    { value: 1, label: 'Main Warehouse', meta: 'WH-001' },
    { value: 2, label: 'Chattogram DC', description: 'Chattogram' },
];

describe('unwrapOptions', () => {
    it('reads the {items:[…]} envelope used by CORE and HRM', () => {
        expect(unwrapOptions({ items: rows })).toEqual(rows);
    });

    it('reads the {data:[…]} envelope used by PARTNER, INVENTORY, SALES and ACCOUNTS', () => {
        // This is the exact regression: these four modules returned real rows
        // with HTTP 200 and the picker still showed nothing.
        expect(unwrapOptions({ data: rows })).toEqual(rows);
    });

    it('reads a bare array', () => {
        expect(unwrapOptions(rows)).toEqual(rows);
    });

    it('reads a paginated Laravel resource, {data:{data:[…],meta:{…}}}', () => {
        const paginated = {
            data: { data: rows, meta: { current_page: 1, total: 2, per_page: 25 } },
        };

        expect(unwrapOptions(paginated)).toEqual(rows);
    });

    it('reads {options:[…]} and {results:[…]} too', () => {
        expect(unwrapOptions({ options: rows })).toEqual(rows);
        expect(unwrapOptions({ results: rows })).toEqual(rows);
    });

    it('returns [] for an envelope it does not recognise, rather than throwing', () => {
        // A new module using `{rows:[…]}` must degrade to an empty picker, not
        // take the page down.
        expect(unwrapOptions({ rows })).toEqual([]);
        expect(unwrapOptions({ payload: rows })).toEqual([]);
    });

    it('returns [] for null, undefined and non-object payloads', () => {
        expect(unwrapOptions(null)).toEqual([]);
        expect(unwrapOptions(undefined)).toEqual([]);
        expect(unwrapOptions('nope')).toEqual([]);
        expect(unwrapOptions(42)).toEqual([]);
    });

    it('treats an empty result set as success, not as an unrecognised shape', () => {
        // "No matches" is a legitimate answer and must not be conflated with a
        // failed request — the component renders those two states differently.
        expect(unwrapOptions({ data: [] })).toEqual([]);
        expect(unwrapOptions({ items: [] })).toEqual([]);
        expect(unwrapOptions([])).toEqual([]);
    });

    it('ignores rows that are not options', () => {
        // Guards against a future endpoint returning model rows straight out of
        // Eloquent, which have no `value`/`label` and would render blank items.
        expect(unwrapOptions({ data: [{ id: 1, name: 'Raw model row' }] })).toEqual([]);
        expect(unwrapOptions({ data: [{ value: 1 }] })).toEqual([]);
        expect(unwrapOptions({ data: [null, 'x'] })).toEqual([]);
    });

    it('accepts a mixed bag as long as every row has value and label', () => {
        const mixed = [
            { value: 'VEN-1', label: 'Vendor One', description: '01711', meta: 'VEN-1', type: 'vendor' },
            { value: 7, label: '1001 — Cash in Hand' },
        ];

        expect(unwrapOptions({ data: mixed })).toEqual(mixed);
    });
});
