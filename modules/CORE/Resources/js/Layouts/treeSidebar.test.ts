import { SidebarProvider, SidebarTrigger } from '@/components/ui/sidebar';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { h, nextTick } from 'vue';
import TreeSidebar from './TreeSidebar.vue';

/**
 * Regression guard for the CORE sidebar.
 *
 * `<Sidebar>` was once used in this component's template without being
 * imported. Vue rendered it as an unknown `<sidebar>` element, so the whole
 * collapsible wrapper (data-state / data-collapsible / width vars) never
 * existed — the trigger toggled state that nothing could react to, and the
 * failure was invisible outside a dev build.
 */
vi.mock('@inertiajs/vue3', async () => {
    const { h: createVNode } = await import('vue');

    return {
        Link: {
            name: 'Link',
            props: { href: { type: String, default: '#' } },
            setup:
                (props: { href: string }, { slots }: { slots: Record<string, () => unknown> }) =>
                () =>
                    createVNode('a', { href: props.href }, slots.default?.()),
        },
        usePage: () => ({
            url: '/core/users',
            props: {
                name: 'Laravel',
                auth: { user: null },
                acl: { permissions: ['*'], roles: [], is_super_admin: true },
                flash: {},
            },
        }),
        router: { on: () => () => {}, get: () => {}, reload: () => {} },
    };
});

const Harness = {
    render() {
        return h(
            SidebarProvider,
            { class: 'core-shell' },
            {
                default: () => [h(TreeSidebar), h(SidebarTrigger)],
            },
        );
    },
};

describe('TreeSidebar', () => {
    /**
     * Ziggy exposes `route` twice: on globalProperties (used by templates) and
     * on `window` via the `@routes` directive (used by plain script code such
     * as `hrefFor`). Mirror both so the component resolves exactly as it does
     * in the browser.
     */
    const route = (name: string) => `/${name.replace(/\./g, '/')}`;

    beforeEach(() => {
        (window as unknown as { route: unknown }).route = route;
        document.body.innerHTML = '';
        window.localStorage.clear();
    });

    function mountSidebar() {
        return mount(Harness, {
            attachTo: document.body,
            global: {
                mocks: {
                    $page: { props: { name: 'Laravel' } },
                    route,
                },
            },
        });
    }

    it('renders the kit Sidebar component (not an unknown <sidebar> element)', () => {
        const wrapper = mountSidebar();

        // Only the real component produces these hooks.
        expect(wrapper.find('[data-sidebar="sidebar"]').exists()).toBe(true);
        expect(wrapper.find('.group.peer[data-state]').exists()).toBe(true);
        expect(wrapper.element.querySelector('sidebar')).toBeNull();
    });

    it('collapses when the trigger is clicked', async () => {
        const wrapper = mountSidebar();

        expect(wrapper.find('[data-state="expanded"]').exists()).toBe(true);

        await wrapper.find('[data-sidebar="trigger"]').trigger('click');
        await nextTick();

        expect(wrapper.find('[data-state="collapsed"]').exists()).toBe(true);
        expect(wrapper.find('[data-collapsible="icon"]').exists()).toBe(true);
    });

    it('renders a draggable rail and hides nested sub-menus in icon mode', () => {
        const wrapper = mountSidebar();

        expect(wrapper.find('[data-sidebar="rail"]').exists()).toBe(true);
        expect(wrapper.html()).toContain('group-data-[collapsible=icon]:hidden');
    });
});
