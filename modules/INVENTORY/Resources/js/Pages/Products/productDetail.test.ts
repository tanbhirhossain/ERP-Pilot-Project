import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import ProductShow from './Show.vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    usePage: () => ({ props: { auth: { user: null }, acl: { permissions: [], roles: [] } } }),
    router: { on: () => () => {} },
}));

describe('product detail movements', () => {
    it('renders the controller moves shape and shows outbound quantity as negative', () => {
        const wrapper = mount(ProductShow, {
            props: {
                product: {
                    id: 1, name: 'Sample product', sku: 'SKU-1', type: 'stockable', status: 'active',
                    is_active: true, is_featured: false, is_serialized: false, is_batch_tracked: false,
                    cost_price: '10', selling_price: '20', stocks: [],
                    moves: [{ id: 7, reference_no: 'SALE-7', reference_type: 'sale', direction: 'out', qty: '2.0000', moved_at: '2026-01-02', created_at: '2026-01-02' }],
                },
                totalStock: 0, totalValue: 0,
            },
            global: {
                mocks: { route: () => '#' },
                stubs: {
                    AdminLayout: { template: '<main><slot /></main>' },
                    PageHeader: { template: '<section><slot name="badge"/><slot name="actions"/><slot/></section>' },
                },
            },
        });
        expect(wrapper.text()).toContain('SALE-7');
        expect(wrapper.text()).toContain('−2.0000');
        expect(wrapper.text()).toContain('No stock yet');
    });
});
