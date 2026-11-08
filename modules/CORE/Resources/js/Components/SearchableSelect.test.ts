/*
 * Regression tests for the shared typeahead.
 *
 * Mounts the real component and drives it through a mocked `fetch`, because the
 * defect this guards against lived entirely in the seam between the JSON an
 * endpoint returns and what the component renders. Every vendor / warehouse /
 * product / account picker in the ERP showed "No matching results" while the
 * server was answering 200 with real rows — a pure unit test of the parsing
 * helper cannot catch the component failing to *use* it.
 */

import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import SearchableSelect from './SearchableSelect.vue';

const vendors = [
    { value: 11, label: 'Amparo Lang Enterprise', description: '01886025123', meta: 'VEN-9595' },
    { value: 12, label: 'Apex Retail Chain', description: '01248270737', meta: 'CUS-3469' },
];

/** A minimal stand-in for the Response the component actually reads. */
function jsonResponse(body: unknown, status = 200) {
    return Promise.resolve({
        ok: status >= 200 && status < 300,
        status,
        json: () => Promise.resolve(body),
    } as unknown as Response);
}

function optionLabels(wrapper: ReturnType<typeof mount>): string[] {
    return wrapper.findAll('[role="option"]').map((node) => node.text());
}

let fetchMock: ReturnType<typeof vi.fn>;

beforeEach(() => {
    // Only the timers the debounce uses — faking requestAnimationFrame as well
    // would stall the focus call in toggleOpen().
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
    fetchMock = vi.fn(() => jsonResponse({ data: vendors }));
    vi.stubGlobal('fetch', fetchMock);
});

afterEach(() => {
    vi.unstubAllGlobals();
    vi.useRealTimers();
});

async function openEndpointSelect(props: Record<string, unknown> = {}) {
    const wrapper = mount(SearchableSelect, {
        props: { endpoint: '/partner/api/options/vendors', ...props },
        attachTo: document.body,
    });

    await wrapper.find('button[role="combobox"]').trigger('click');
    await vi.advanceTimersByTimeAsync(0);
    await flushPromises();

    return wrapper;
}

describe('SearchableSelect · remote mode', () => {
    it('renders rows from the {data:[…]} envelope used by PARTNER, INVENTORY, SALES and ACCOUNTS', async () => {
        // THE regression. The component read only `{items:[…]}`, so these four
        // modules rendered an empty list despite answering 200 with real rows.
        const wrapper = await openEndpointSelect();

        expect(wrapper.findAll('[role="option"]')).toHaveLength(2);
        expect(optionLabels(wrapper)[0]).toContain('Amparo Lang Enterprise');
    });

    it('renders rows from the {items:[…]} envelope used by CORE and HRM', async () => {
        fetchMock.mockImplementation(() => jsonResponse({ items: vendors }));

        const wrapper = await openEndpointSelect();

        expect(wrapper.findAll('[role="option"]')).toHaveLength(2);
    });

    it('renders rows from a bare array and from a paginated resource', async () => {
        fetchMock.mockImplementationOnce(() => jsonResponse(vendors));
        expect((await openEndpointSelect()).findAll('[role="option"]')).toHaveLength(2);

        fetchMock.mockImplementationOnce(() =>
            jsonResponse({ data: { data: vendors, meta: { current_page: 1, total: 2 } } }),
        );
        expect((await openEndpointSelect()).findAll('[role="option"]')).toHaveLength(2);
    });

    it('shows the option metadata badge and description', async () => {
        const wrapper = await openEndpointSelect();
        const first = wrapper.findAll('[role="option"]')[0];

        expect(first.text()).toContain('VEN-9595');
        expect(first.text()).toContain('01886025123');
    });

    it('debounces typing and passes the term as ?search=', async () => {
        const wrapper = await openEndpointSelect();
        expect(fetchMock).toHaveBeenCalledTimes(1);

        await wrapper.find('input[type="text"]').setValue('amp');

        // Not fired yet — still inside the 280ms debounce window.
        expect(fetchMock).toHaveBeenCalledTimes(1);

        await vi.advanceTimersByTimeAsync(300);
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(String(fetchMock.mock.calls[1][0])).toContain('search=amp');
    });

    it('distinguishes a failed request from an empty result set', async () => {
        // A 403 or 500 used to render exactly like "no matches", which is why
        // this shipped broken with nothing in the UI to explain it.
        fetchMock.mockImplementation(() => jsonResponse({ message: 'Forbidden' }, 403));

        const wrapper = await openEndpointSelect();

        expect(wrapper.text()).toContain('Couldn’t load options');
        expect(wrapper.text()).not.toContain('No matching results');
        expect(wrapper.find('button[type="button"]:last-of-type').exists()).toBe(true);
    });

    it('says "No matching results" when the server legitimately returns none', async () => {
        fetchMock.mockImplementation(() => jsonResponse({ data: [] }));

        const wrapper = await openEndpointSelect();

        expect(wrapper.text()).toContain('No matching results');
        expect(wrapper.text()).not.toContain('Couldn’t load options');
    });

    it('emits the option value, not its label', async () => {
        const wrapper = await openEndpointSelect();

        await wrapper.findAll('[role="option"]')[1].trigger('click');

        expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([12]);
    });

    it('keeps the chosen label after the remote list is replaced', async () => {
        const wrapper = await openEndpointSelect();

        await wrapper.findAll('[role="option"]')[0].trigger('click');
        expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([11]);

        // The component is controlled: a real page binds v-model, so the value
        // has to come back down as a prop before the label can render.
        await wrapper.setProps({ modelValue: 11 });
        expect(wrapper.find('button[role="combobox"]').text()).toContain('Amparo Lang Enterprise');

        // The next search returns a completely different page of results. The
        // chosen item is no longer in the list, so without the seen-cache the
        // closed control would degrade to showing the raw value "11".
        fetchMock.mockImplementation(() => jsonResponse({ data: [{ value: 99, label: 'Someone Else' }] }));

        await wrapper.find('button[role="combobox"]').trigger('click');
        await wrapper.find('input[type="text"]').setValue('zzz');
        await vi.advanceTimersByTimeAsync(300);
        await flushPromises();
        await wrapper.find('button[role="combobox"]').trigger('click');

        expect(wrapper.find('button[role="combobox"]').text()).toContain('Amparo Lang Enterprise');
        expect(wrapper.find('button[role="combobox"]').text()).not.toMatch(/\b11\b/);
    });

    it('navigates with the keyboard and selects on Enter', async () => {
        const wrapper = await openEndpointSelect();
        const input = wrapper.find('input[type="text"]');

        await input.trigger('keydown', { key: 'ArrowDown' });
        await input.trigger('keydown', { key: 'ArrowDown' });
        await input.trigger('keydown', { key: 'Enter' });

        expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([12]);
    });

    it('highlights the matched part of the label', async () => {
        const wrapper = await openEndpointSelect();

        await wrapper.find('input[type="text"]').setValue('Apex');
        await vi.advanceTimersByTimeAsync(300);
        await flushPromises();

        expect(wrapper.find('mark').exists()).toBe(true);
        expect(wrapper.find('mark').text()).toBe('Apex');
    });
});

describe('SearchableSelect · static mode', () => {
    // Nine options: the search box only renders for a list long enough to need
    // one (endpoint mode, multiple mode, more than 8 options, or an active
    // query). Asserting against a 3-item list would test a box that isn't there.
    const manyOptions = [
        { value: 1, label: 'Admin' },
        { value: 2, label: 'Manager' },
        { value: 3, label: 'Auditor', description: 'read only' },
        { value: 4, label: 'Accountant' },
        { value: 5, label: 'Supervisor' },
        { value: 6, label: 'Operator' },
        { value: 7, label: 'Clerk' },
        { value: 8, label: 'Analyst' },
        { value: 9, label: 'Consultant' },
    ];

    function openStaticSelect(props: Record<string, unknown> = {}) {
        return mount(SearchableSelect, {
            props: { options: manyOptions, ...props },
            attachTo: document.body,
        });
    }

    it('hides the search box for a short static list', () => {
        // Pinned so the threshold is a decision, not an accident.
        const short = mount(SearchableSelect, {
            props: { options: manyOptions.slice(0, 3) },
            attachTo: document.body,
        });

        expect(short.find('input[type="text"]').exists()).toBe(false);

        const long = openStaticSelect();
        expect(long.find('input[type="text"]').exists()).toBe(true);
    });

    it('filters in the browser without any request', async () => {
        const wrapper = openStaticSelect();

        await wrapper.find('button[role="combobox"]').trigger('click');
        await flushPromises();

        expect(fetchMock).not.toHaveBeenCalled();
        expect(wrapper.findAll('[role="option"]')).toHaveLength(9);

        await wrapper.find('input[type="text"]').setValue('aud');
        await flushPromises();

        expect(wrapper.findAll('[role="option"]')).toHaveLength(1);
        expect(optionLabels(wrapper)[0]).toContain('Auditor');
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('matches on the description as well as the label', async () => {
        const wrapper = openStaticSelect();

        await wrapper.find('button[role="combobox"]').trigger('click');
        await wrapper.find('input[type="text"]').setValue('read only');
        await flushPromises();

        expect(wrapper.findAll('[role="option"]')).toHaveLength(1);
        expect(optionLabels(wrapper)[0]).toContain('Auditor');
    });

    it('adds and removes values in multiple mode', async () => {
        const wrapper = openStaticSelect({ multiple: true, modelValue: [] as number[] });

        await wrapper.find('button[role="combobox"]').trigger('click');
        await flushPromises();

        await wrapper.findAll('[role="option"]')[0].trigger('click');
        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([[1]]);

        await wrapper.findAll('[role="option"]')[2].trigger('click');
        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([[1, 3]]);

        // Clicking a selected row toggles it back off.
        await wrapper.findAll('[role="option"]')[0].trigger('click');
        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([[3]]);
    });

    it('clears on demand and reports invalid state for a11y', async () => {
        const wrapper = openStaticSelect({ modelValue: 2, invalid: true });

        expect(wrapper.find('button[role="combobox"]').attributes('aria-invalid')).toBe('true');
        expect(wrapper.find('button[role="combobox"]').text()).toContain('Manager');

        await wrapper.find('button[aria-label="Clear selection"]').trigger('click');

        expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([null]);
    });

    it('does not open or emit while disabled', async () => {
        const wrapper = openStaticSelect({ disabled: true });
        const combobox = wrapper.find('button[role="combobox"]');

        expect(combobox.attributes('disabled')).toBeDefined();

        await combobox.trigger('click');
        await flushPromises();

        // The panel is v-show, so its options remain in the DOM when closed —
        // aria-expanded and the inline display are the real signals.
        expect(combobox.attributes('aria-expanded')).toBe('false');
        // v-show sits on the panel div that wraps the listbox, not on the ul.
        const panel = wrapper.find('[role="listbox"]').element.parentElement as HTMLElement | null;
        expect(panel?.style.display).toBe('none');
        expect(wrapper.emitted('update:modelValue')).toBeUndefined();
    });
});
