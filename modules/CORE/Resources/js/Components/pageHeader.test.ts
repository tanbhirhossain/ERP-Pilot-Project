import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import PageHeader from './PageHeader.vue';

describe('PageHeader', () => {
    it('renders page-owned actions inside the header card, beside the title', () => {
        // Pages that hand-rolled a title row kept their buttons verbatim — permission gates, click
        // handlers, custom classes — by moving them into this slot rather than the declarative
        // `actions` array, which can carry none of those.
        const wrapper = mount(PageHeader, {
            props: { title: 'Holiday Calendar', description: 'Public and company holidays.' },
            slots: { actions: '<button type="button" data-test="add">Add holiday</button>' },
            global: { stubs: { Link: true } },
        });

        const card = wrapper.find('h1').element.closest('.rounded-xl');
        expect(card, 'the header card').not.toBeNull();
        expect(card?.querySelector('[data-test="add"]'), 'the action belongs in the card, not under it').not.toBeNull();
        expect(wrapper.find('h1').text()).toContain('Holiday Calendar');
        expect(wrapper.text()).toContain('Public and company holidays.');
    });

    it('still renders its default slot below the card, as the 109 wrapping pages expect', () => {
        const wrapper = mount(PageHeader, {
            props: { title: 'Departments' },
            slots: { default: '<section data-test="body">rows</section>' },
            global: { stubs: { Link: true } },
        });

        const card = wrapper.find('h1').element.closest('.rounded-xl');
        const body = wrapper.find('[data-test="body"]').element;
        expect(card?.contains(body), 'page content must not end up inside the header card').toBe(false);
    });
});
