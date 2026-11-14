import { SidebarProvider } from '@/components/ui/sidebar';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { h, nextTick } from 'vue';
import { resetSidebarScroll } from '../Composables/useSidebarScroll';
import TreeSidebar from './TreeSidebar.vue';

/**
 * The sidebar keeps its own scroll position across navigations.
 *
 * The report was that the menu "jumps to the top after a click, which is very disturbing" — and the cause
 * is structural rather than cosmetic: every page renders its own `AdminLayout`, so an Inertia visit tears
 * the shell down and mounts a new one, and a freshly created scroll container starts at `scrollTop: 0`.
 * The open/closed state of the branches was already remembered in storage; the offset was the half nobody
 * saved. These tests pin the saving and the restoring, because the symptom only appears in a real click
 * through the shell and nothing else would notice a regression here.
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
            // `core.users.index` is what the mocked `route()` turns into `/core/users/index`, so this is the
            // one url that makes the Users leaf report as the current page — which is what the
            // keep-in-view nudge looks for.
            url: '/core/users/index',
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

// jsdom performs no layout, so a real `scrollTop` assignment would be thrown away before this component
// ever reads it back. Give every element the property the browser gives it: writable, and stored per node.
beforeEach(() => {
    Object.defineProperty(HTMLElement.prototype, 'scrollTop', {
        configurable: true,
        get(this: HTMLElement) {
            return (this as unknown as { _testScrollTop?: number })._testScrollTop ?? 0;
        },
        set(this: HTMLElement, value: number) {
            (this as unknown as { _testScrollTop?: number })._testScrollTop = value;
        },
    });
});

const Harness = {
    render() {
        return h(SidebarProvider, { class: 'core-shell' }, { default: () => [h(TreeSidebar)] });
    },
};

const route = (name: string) => `/${name.replace(/\./g, '/')}`;

function mountSidebar() {
    return mount(Harness, {
        attachTo: document.body,
        global: { mocks: { $page: { props: { name: 'Laravel' } }, route } },
    });
}

function contentOf(wrapper: ReturnType<typeof mountSidebar>): HTMLElement {
    const el = wrapper.element.querySelector<HTMLElement>('[data-sidebar="content"]');

    if (!el) throw new Error('the sidebar list has no scroll container, so these assertions mean nothing');

    return el;
}

describe('TreeSidebar scroll stability', () => {
    beforeEach(() => {
        (window as unknown as { route: unknown }).route = route;
        document.body.innerHTML = '';
        window.localStorage.clear();
        resetSidebarScroll();
    });

    it('starts at the top on a fresh load, and nowhere remembers an offset from before that', async () => {
        const wrapper = mountSidebar();
        await nextTick();

        expect(contentOf(wrapper).scrollTop).toBe(0);

        wrapper.unmount();
    });

    it('restores the offset the next page mounts with, after a click that rebuilt the shell', async () => {
        const first = mountSidebar();
        await nextTick();

        contentOf(first).scrollTop = 240;
        first.unmount();

        const second = mountSidebar();
        await nextTick();

        expect(contentOf(second).scrollTop).toBe(240, 'the list snapped back to the top — the reported jump');

        second.unmount();
    });

    it('remembers while scrolling, not only at teardown', async () => {
        const first = mountSidebar();
        await nextTick();

        const el = contentOf(first);
        el.scrollTop = 96;
        el.dispatchEvent(new Event('scroll'));
        await new Promise((resolve) => requestAnimationFrame(resolve));

        first.unmount();

        const second = mountSidebar();
        await nextTick();

        expect(contentOf(second).scrollTop).toBe(96);

        second.unmount();
    });

    it('keeps working when the list is shorter than the remembered offset', async () => {
        const first = mountSidebar();
        await nextTick();

        contentOf(first).scrollTop = 4000;
        first.unmount();

        const second = mountSidebar();
        await nextTick();

        // No assertion about clamping — jsdom has no layout to clamp against. The point is that a stale
        // offset cannot break the mount: `settleSidebarScroll` must run without throwing, and the menu has
        // to be rendered and usable afterwards.
        expect(second.find('[data-sidebar="content"]').exists()).toBe(true);
        expect(second.findAll('[data-nav-target]').length).toBeGreaterThan(0);

        second.unmount();
    });

    it('marks the current leaf so the keep-in-view nudge can find it', async () => {
        const wrapper = mountSidebar();

        // The branch holding the current page opens inside `onMounted`, so the leaf is not in the tree on the
        // first tick — poll a bounded number of times rather than hard-code a tick count nobody could verify.
        let found = false;

        for (let attempt = 0; attempt < 8 && !found; attempt++) {
            await nextTick();
            found = wrapper.find('[data-nav-target][data-active="true"]').exists();
        }

        // `Users` is the page the mocked Inertia url points at. The pairing of the two attributes is the whole
        // contract: `data-nav-target` says "this is a navigable leaf", `data-active` is the kit's own current
        // -page flag, and the selector in `useSidebarScroll` is exactly that combination.
        expect(found).toBe(
            true,
            'no leaf pairs the marker with the active state, so a click that opened a branch above it would stay off-screen',
        );
        expect(wrapper.findAll('[data-nav-target]').length).toBeGreaterThan(1);

        wrapper.unmount();
    });
});
