/* Unwrapping for the typeahead endpoints. */

import type { SelectOption } from '../types';

/**
 * The keys an options endpoint may nest its rows under.
 *
 * The modules never agreed on one: PARTNER, INVENTORY, SALES and ACCOUNTS
 * return `{data:[…]}`, CORE and HRM return `{items:[…]}`, and a paginated
 * Laravel resource nests it again as `{data:{data:[…]}}`. Reading only a single
 * shape yields an empty list, which is indistinguishable from "no matches" to
 * whoever is filling in the form — that is how every vendor / warehouse /
 * product picker in the ERP came to look broken at once.
 */
const ENVELOPE_KEYS = ['data', 'items', 'options', 'results'] as const;

function isOptionArray(value: unknown): value is SelectOption[] {
    if (!Array.isArray(value)) {
        return false;
    }

    // An empty array is still a valid answer — "no matches" is not an error.
    return value.every(
        (row) =>
            row !== null &&
            typeof row === 'object' &&
            'value' in (row as Record<string, unknown>) &&
            'label' in (row as Record<string, unknown>),
    );
}

/**
 * Pull the option rows out of whatever an endpoint actually returned.
 *
 * Deliberately tolerant: an unrecognised shape returns `[]` rather than
 * throwing, so a new module cannot take the whole picker down. Callers pair
 * this with the HTTP status to tell "empty" apart from "failed".
 */
export function unwrapOptions(payload: unknown): SelectOption[] {
    if (isOptionArray(payload)) {
        return payload;
    }

    if (!payload || typeof payload !== 'object') {
        return [];
    }

    const record = payload as Record<string, unknown>;

    for (const key of ENVELOPE_KEYS) {
        const value = record[key];

        if (isOptionArray(value)) {
            return value;
        }

        // Paginated: { data: { data: […], meta: {…} } }
        if (value && typeof value === 'object' && isOptionArray((value as Record<string, unknown>).data)) {
            return (value as Record<string, unknown>).data as SelectOption[];
        }
    }

    return [];
}
