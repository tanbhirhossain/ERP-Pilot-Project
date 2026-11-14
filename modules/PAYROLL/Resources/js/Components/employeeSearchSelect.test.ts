/*
 * Tests for `EmployeeSearchSelect`, the picker behind seven payroll forms (tax override, loans, PF
 * allocation, increments, settlements, adjustments).
 *
 * The reported symptom was "I cannot see all employees in the Employee Tax override form and it's not
 * selectable", and the cause is a watcher that fought the user: it watched `[modelValue, options,
 * remoteOptions]` together, and `remoteOptions` is reassigned on every keystroke — so every keystroke woke
 * the watcher, whose "nothing is selected yet" branch then cleared `query` and aborted the search it had
 * just scheduled. The box erased itself as you typed. What remained was the page's seeded list, sliced to
 * 50, which for any company whose first 50 names (alphabetically) do not include yours reads as a picker
 * that cannot reach — let alone select — the employee you need.
 *
 * Mounts are real and assertions read the rendered listbox, because the defect lives in the interaction
 * between two watchers, not in either one.
 */

import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import EmployeeSearchSelect from './EmployeeSearchSelect.vue';

interface FetchCall {
    url: string;
}

const calls: FetchCall[] = [];

function optionList(count: number, offset = 1) {
    return Array.from({ length: count }, (_, i) => ({
        value: `EMP-${offset + i}`,
        label: `Employee ${offset + i} (EMP-${offset + i})`,
        description: `EMP-${offset + i}`,
    }));
}

/** Resolve `items` for every request; the browse request (no term) gets the long roster. */
function stubFetch(items: () => { value: string; label: string }[]) {
    calls.length = 0;

    vi.stubGlobal('fetch', vi.fn(async (url: string) => {
        calls.push({ url: String(url) });

        return {
            ok: true,
            status: 200,
            json: async () => ({ items: items() }),
        } as unknown as Response;
    }));
}

async function until(condition: () => boolean, ms = 2000): Promise<void> {
    const started = Date.now();

    while (!condition()) {
        if (Date.now() - started > ms) {
            throw new Error('the debounced search never settled');
        }

        await new Promise((resolve) => setTimeout(resolve, 20));
    }
}

function rows(wrapper: ReturnType<typeof mount>) {
    return wrapper.findAll('[role="option"]');
}

beforeEach(() => {
    vi.stubGlobal('route', vi.fn(() => '/hrm/api/options/employees'));
});

afterEach(() => {
    vi.unstubAllGlobals();
    calls.length = 0;
});

describe('typing reaches the server', () => {
    it('keeps what the user typed and searches for it', async () => {
        stubFetch(() => [{ value: 'EMP-412', label: 'Rafiqul Islam (EMP-412)' }]);

        const wrapper = mount(EmployeeSearchSelect, { props: { modelValue: '', options: optionList(120) } });

        await wrapper.find('input').trigger('focus');
        await wrapper.find('input').setValue('rafiq');

        // Before the fix this was '' by the next tick: the shared watcher wiped the query mid-keystroke.
        expect(wrapper.find('input').element.value).toBe('rafiq');

        await until(() => calls.some((c) => c.url.includes('search=rafiq')));

        expect(calls.some((c) => c.url.includes('search=rafiq'))).toBe(true);
    });

    it('searches from a single character, because names are not always two letters long', async () => {
        stubFetch(() => []);

        const wrapper = mount(EmployeeSearchSelect, { props: { modelValue: '', options: [] } });

        await wrapper.find('input').trigger('focus');
        await wrapper.find('input').setValue('l');
        await until(() => calls.some((c) => c.url.includes('search=l')));

        expect(calls.some((c) => c.url.includes('search=l'))).toBe(true);
    });

    it('does not fire a request per keystroke beyond the debounce', async () => {
        stubFetch(() => []);

        const wrapper = mount(EmployeeSearchSelect, { props: { modelValue: '', options: [] } });

        await wrapper.find('input').trigger('focus');

        for (const value of ['a', 'ab', 'abc']) {
            await wrapper.find('input').setValue(value);
        }

        await until(() => calls.some((c) => c.url.includes('search=abc')));

        expect(calls.filter((c) => c.url.includes('search=a&') || c.url.includes('search=ab&'))).toHaveLength(0);
    });
});

describe('the whole roster is reachable', () => {
    it('asks the endpoint for the browse list on open, not just the page seed', async () => {
        stubFetch(() => optionList(200, 121));

        const wrapper = mount(EmployeeSearchSelect, { props: { modelValue: '', options: optionList(20) } });

        await wrapper.find('input').trigger('focus');
        await until(() => calls.length > 0);

        expect(calls[0].url).toContain('search=');
        expect(calls[0].url).toContain('limit=200');
    });

    it('shows more than 50 rows at once and offers the rest', async () => {
        stubFetch(() => []);

        const wrapper = mount(EmployeeSearchSelect, { props: { modelValue: '', options: optionList(200) } });

        await wrapper.find('input').trigger('focus');
        await wrapper.vm.$nextTick();

        // 80 up front instead of a silent 50-row ceiling.
        expect(rows(wrapper)).toHaveLength(80);
        expect(wrapper.text()).toContain('Show 120 more');

        await wrapper.findAll('button').find((b) => b.text().startsWith('Show '))!.trigger('mousedown');
        await wrapper.vm.$nextTick();

        expect(rows(wrapper)).toHaveLength(200);
        expect(wrapper.text()).toContain('200 employees listed');
    });

    it('selects an employee who only exists in the fetched page, not the seed', async () => {
        stubFetch(() => [{ value: 'EMP-9001', label: 'Person Far Down The List (EMP-9001)' }]);

        const wrapper = mount(EmployeeSearchSelect, { props: { modelValue: '', options: optionList(5) } });

        await wrapper.find('input').trigger('focus');
        await until(() => rows(wrapper).some((r) => r.text().includes('EMP-9001')));

        const target = rows(wrapper).find((r) => r.text().includes('EMP-9001'))!;

        target.trigger('mousedown');
        await wrapper.vm.$nextTick();

        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['EMP-9001']);
        expect(wrapper.text()).toContain('Selected:');
    });

    it('says so when the browse page is full and searching is required', async () => {
        stubFetch(() => optionList(200, 1));

        const wrapper = mount(EmployeeSearchSelect, { props: { modelValue: '', options: [] } });

        await wrapper.find('input').trigger('focus');
        await until(() => wrapper.text().includes('type to search all of them'));

        expect(wrapper.text()).toContain('Showing the first 200 employees');
    });
});

describe('the parent stays in charge of the selection', () => {
    it('resolves a label for a value the page set without user input', async () => {
        stubFetch(() => []);

        const wrapper = mount(EmployeeSearchSelect, {
            props: { modelValue: 'EMP-3', options: optionList(5) },
        });

        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('Selected: Employee 3 (EMP-3)');
    });

    it('shows a raw ID rather than a stale name when the parent switches employees', async () => {
        stubFetch(() => []);

        const wrapper = mount(EmployeeSearchSelect, {
            props: { modelValue: 'EMP-3', options: optionList(2) },
        });

        await wrapper.setProps({ modelValue: 'EMP-77' });
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('Selected: EMP-77');
        expect(wrapper.text()).not.toContain('Employee 2 (EMP-2)');
    });

    it('clears the box when the parent clears the value, and only then', async () => {
        stubFetch(() => []);

        const wrapper = mount(EmployeeSearchSelect, {
            props: { modelValue: 'EMP-2', options: optionList(5) },
        });

        await wrapper.find('input').setValue('emp-4');
        expect(wrapper.find('input').element.value).toBe('emp-4');

        await wrapper.setProps({ modelValue: '' });
        await wrapper.vm.$nextTick();

        expect(wrapper.find('input').element.value).toBe('');
        expect(wrapper.text()).not.toContain('Selected:');
    });
});
