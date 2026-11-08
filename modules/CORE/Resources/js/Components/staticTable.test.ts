import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { nextTick } from 'vue';

import StaticTable from './StaticTable.vue';

/**
 * The static sibling of DataTable, and the reason 70 hand-rolled tables can stop each inventing their
 * own padding.
 *
 * What this can and cannot prove: jsdom applies no stylesheet, so the `:deep()` rules that impose the
 * cell metrics are not observable here. What is asserted instead are the hooks those rules key off —
 * `st-sticky`, `st-dense`, the scroll box — plus the structure a page depends on. A test that claimed
 * to check the padding would pass for a reason unrelated to the padding.
 */
describe('StaticTable', () => {
    it('renders the headings it is given, and honours a column that asks to be right-aligned', () => {
        const wrapper = mount(StaticTable, {
            props: {
                columns: [
                    { label: 'Account' },
                    { label: 'Debit', align: 'right' },
                    { label: 'Status', align: 'center' },
                ],
            },
        });

        const heads = wrapper.findAll('thead th');
        expect(heads.map((h) => h.text())).toEqual(['Account', 'Debit', 'Status']);
        expect(heads[1].classes()).toContain('text-right');
        expect(heads[2].classes()).toContain('text-center');
        expect(heads[0].classes()).not.toContain('text-right');
        expect(wrapper.find('table').exists(), 'it renders a real table, not a grid of divs').toBe(true);
    });

    it('lets a page write its own head and rows, and still wraps them in the house table', () => {
        const wrapper = mount(StaticTable, {
            slots: {
                head: '<thead><tr><th>Posted by</th><th>Amount</th></tr></thead>',
                default: '<tbody><tr><td>JV-001</td><td>1,250.00</td></tr></tbody>',
            },
        });

        // Most of the 70 tables have headers carrying sort hints or units, so the page keeps writing
        // them; the component only has to not get in the way.
        expect(wrapper.findAll('thead th').map((h) => h.text())).toEqual(['Posted by', 'Amount']);
        expect(wrapper.findAll('tbody td').map((t) => t.text())).toEqual(['JV-001', '1,250.00']);
        expect(wrapper.findAll('tbody tr')).toHaveLength(1);
    });

    it('does not put the page\'s own row groups inside a row group of its own', () => {
        // Every page this component replaced passes a whole <thead> and <tbody>, and several pass a
        // <tfoot> of totals. The first version wrapped the slot in a <tbody>, which nested a tbody in
        // a tbody and left the thead's parent element a TBODY — a render-level defect that ESLint, the
        // build, vue-tsc and the tests above all passed, because the test above fed it bare rows
        // instead of what a real page feeds it.
        const wrapper = mount(StaticTable, {
            slots: {
                default:
                    '<thead><tr><th>Account</th></tr></thead>' +
                    '<tbody><tr><td>1001 Cash</td></tr></tbody>' +
                    '<tfoot><tr><td>Total</td></tr></tfoot>',
            },
        });

        expect(wrapper.findAll('tbody'), 'the page supplied one tbody; the component must not add another').toHaveLength(1);
        expect(wrapper.find('thead').element.parentElement?.tagName, 'a thead inside a tbody loses its semantics').toBe('TABLE');
        expect(wrapper.find('tfoot').exists(), 'a totals footer has to survive the move').toBe(true);
        expect(wrapper.find('tfoot').element.parentElement?.tagName).toBe('TABLE');
    });

    it('supplies the row group only for the columns shorthand, which takes bare rows', () => {
        const wrapper = mount(StaticTable, {
            props: { columns: [{ label: 'Day' }, { label: 'Hours' }] },
            slots: { default: '<tr><td>Mon</td><td>8</td></tr>' },
        });

        expect(wrapper.findAll('tbody')).toHaveLength(1);
        expect(wrapper.find('tbody').classes()).toContain('divide-y');
        expect(wrapper.findAll('tbody td').map((t) => t.text())).toEqual(['Mon', '8']);
    });

    it('asks for the scroll box that makes a sticky header possible, and drops it when not sticky', () => {
        const sticky = mount(StaticTable, { props: { sticky: true, columns: [{ label: 'A' }] } });
        const plain = mount(StaticTable, { props: { columns: [{ label: 'A' }] } });

        // `position: sticky` resolves against the nearest scrolling ancestor, so the box has to be the
        // one DataTable uses; an `overflow-x-auto` wrapper would leave the header inert. Read the box
        // off the table's own parent rather than a `div > div` selector, which matches the root too.
        const stickyBox = sticky.find('table').element.parentElement as HTMLElement;
        const plainBox = plain.find('table').element.parentElement as HTMLElement;

        expect(sticky.find('table').classes()).toContain('st-sticky');
        expect(stickyBox.className).toContain('max-h-[72vh]');
        expect(stickyBox.className).toContain('overflow-auto');

        expect(plain.find('table').classes()).not.toContain('st-sticky');
        expect(plainBox.className).toContain('overflow-x-auto');
        expect(plainBox.className).not.toContain('max-h-[72vh]');
    });

    it('tightens the cells when a grid is dense', () => {
        const dense = mount(StaticTable, { props: { dense: true, columns: [{ label: 'A' }] } });
        const normal = mount(StaticTable, { props: { columns: [{ label: 'A' }] } });

        expect(dense.find('table').classes()).toContain('st-dense');
        expect(normal.find('table').classes()).not.toContain('st-dense');
        // Both have to be asserted, and they have to differ: `px-3` alone is the default too, so
        // checking only the dense table would pass even if `dense` did nothing at all.
        expect(dense.find('thead th').classes()).toContain('px-2');
        expect(normal.find('thead th').classes()).toContain('px-3');
        expect(normal.find('thead th').classes()).not.toContain('px-2');
    });

    it('spans the empty state across every column, and says what would fill it', () => {
        const wrapper = mount(StaticTable, {
            props: {
                columns: [{ label: 'Day' }, { label: 'Shift' }, { label: 'Hours' }],
                empty: true,
                emptyTitle: 'No duties rostered',
                emptyDescription: 'Pick a week to roster it.',
            },
        });

        const cell = wrapper.find('tbody td');
        expect(cell.attributes('colspan'), 'a narrow empty cell would look broken under three columns').toBe('3');
        expect(cell.text()).toContain('No duties rostered');
        expect(cell.text()).toContain('Pick a week to roster it.');
    });

    it('measures the span of the empty state off the header a page wrote, colspan groups included', async () => {
        // A page that writes its own <thead> should not have to count its columns: a cell spanning
        // one column of four would push the empty state into the first column.
        const wrapper = mount(StaticTable, {
            props: { empty: true },
            slots: {
                default:
                    '<thead><tr><th>Employee</th><th colspan="2">Week</th><th>Total</th></tr></thead>' +
                    '<tbody></tbody>',
            },
        });
        await nextTick();

        const cell = wrapper.find('[data-st-empty] td');
        expect(cell.attributes('colspan'), 'Employee + a two-column group + Total is four columns').toBe('4');
        expect(cell.element.closest('tbody')?.parentElement?.tagName).toBe('TABLE');
    });

    it('draws no card of its own when flush inside a panel that already has one', () => {
        const flush = mount(StaticTable, { props: { flush: true }, slots: { default: '<tbody><tr><td>1</td></tr></tbody>' } });
        const card = mount(StaticTable, { slots: { default: '<tbody><tr><td>1</td></tr></tbody>' } });

        for (const chrome of ['border', 'rounded-xl', 'shadow-sm', 'bg-card']) {
            expect(card.classes(), `the standalone table keeps its ${chrome}`).toContain(chrome);
            expect(flush.classes(), `a flush table must not draw a second ${chrome}`).not.toContain(chrome);
        }
        expect(flush.find('table td').text()).toBe('1');
    });

    it('sizes a dense grid down as a whole, not just its padding', () => {
        // Seven of the ten dense grids were text-xs on their own <table>, a class the conversion
        // dropped; the component's table is the only place that size can now come from.
        const dense = mount(StaticTable, { props: { dense: true }, slots: { default: '<tbody><tr><td>1</td></tr></tbody>' } });
        const normal = mount(StaticTable, { slots: { default: '<tbody><tr><td>1</td></tr></tbody>' } });

        expect(dense.find('table').classes()).toContain('text-xs');
        expect(normal.find('table').classes()).toContain('text-sm');
        expect(normal.find('table').classes()).not.toContain('text-xs');
    });

    it('takes an explicit colspan for a page that writes its own head', () => {
        const wrapper = mount(StaticTable, {
            props: { empty: true, colspan: 5 },
            slots: { head: '<thead><tr><th>a</th><th>b</th><th>c</th><th>d</th><th>e</th></tr></thead>' },
        });

        expect(wrapper.find('tbody td').attributes('colspan')).toBe('5');
    });

    it('names itself for a screen reader when the table has no visible caption', () => {
        const wrapper = mount(StaticTable, { props: { label: 'VAT summary', columns: [{ label: 'A' }] } });
        expect(wrapper.find('table').attributes('aria-label')).toBe('VAT summary');
    });
});
