import { SidebarProvider, SidebarTrigger } from '@/components/ui/sidebar';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { h, nextTick } from 'vue';
import { sidebarMenu } from '../Config/menu';
import TreeSidebar from './TreeSidebar.vue';

/**
 * Which page the sidebar thinks it is on, and who is signed in. `vi.hoisted` because the mock factory
 * below is hoisted above the imports and would otherwise read these before they exist.
 */
const pageState = vi.hoisted(() => ({
    url: '/core/users',
    user: null as { name: string; email: string } | null,
}));

vi.mock('@inertiajs/vue3', async () => {
    const { h: createVNode } = await import('vue');

    return {
        Link: {
            name: 'Link',
            props: { href: { type: String, default: '#' } },
            // `VNode`, not `VNodeChild`: the latter admits null, which createVNode's children do not.
            setup: (props: { href: string }, { slots }: { slots: Record<string, () => import('vue').VNode> }) =>
                () => createVNode('a', { href: props.href }, slots.default?.()),
        },
        usePage: () => ({
            url: pageState.url,
            props: {
                name: 'ERP Pilot',
                auth: { user: pageState.user },
                acl: { permissions: ['*'], roles: [], is_super_admin: true },
                flash: {},
            },
        }),
        router: { on: () => () => {}, get: () => {}, reload: () => {} },
    };
});

/**
 * The sidebar polish, pinned (report item 39).
 *
 * `treeSidebar.test.ts` guards the structure — that the kit's `<Sidebar>` is really rendered, that
 * the trigger collapses it, that the rail exists. This file guards the things that made it unpleasant
 * to use, each of which is a behaviour that could silently regress:
 *
 *  - sections opening all at once, which turned the first screen into a hundred rows of small text;
 *  - sections in module-build order rather than work order, with no banding;
 *  - a footer that advertised the product instead of saying who was signed in;
 *  - a ⌘K hint that nothing listened for;
 *  - four labels carrying an emoji that no other label did.
 *
 * A cosmetic change is still a behaviour change if somebody can undo it without a test failing.
 *
 * The component is imported per test rather than at the top of the file: `vi.doMock` only applies to
 * modules resolved after it, and a static import would have loaded the sidebar against the real
 * Inertia router before any mock existed.
 */
const route = (name: string) => `/${name.replace(/\./g, '/')}`;

/**
 * Mount the sidebar as the shell does.
 *
 * The component is imported statically, not per test: `vi.resetModules()` plus a dynamic import gives
 * the sidebar its *own* copy of `@/components/ui/sidebar`, and the `SidebarContext` symbol it injects
 * is then not the one this file's `SidebarProvider` provides — every mount dies with "Component must
 * be used within Sidebar". Per-test state belongs in the mock, not in a second module graph.
 */
function mountSidebar(url: string, user: { name: string; email: string } | null) {
    pageState.url = url;
    pageState.user = user;

    const wrapper = mount(
        {
            render() {
                return h(SidebarProvider, { class: 'core-shell' }, { default: () => [h(TreeSidebar), h(SidebarTrigger)] });
            },
        },
        {
            attachTo: document.body,
            global: { mocks: { $page: { props: { name: 'ERP Pilot' } }, route } },
        },
    );

    // Two ticks: Radix's presence wrapper mounts collapsible content on the tick *after* the open
    // state changes, so one tick sees the section as open but its items are not in the DOM yet.
    return nextTick().then(() => nextTick()).then(() => wrapper);
}

beforeEach(() => {
    (window as unknown as { route: unknown }).route = route;
    document.body.innerHTML = '';
    window.localStorage.clear();
    pageState.url = '/core/users';
    pageState.user = null;
});

describe('TreeSidebar polish', () => {
    it('opens with every section closed, so the first screen is a list of headings', async () => {
        const wrapper = await mountSidebar('/core/users', null);

        // The provider root is `expanded`; a section that has been opened is `open`. With nothing
        // matching the current URL, no section may be open.
        expect(wrapper.findAll('[data-state="open"]')).toHaveLength(0);
        expect(wrapper.findAll('[data-state="closed"]').length).toBeGreaterThan(5);

        // Collapsing is not hiding: every section heading is still on the screen.
        const html = wrapper.html();
        for (const section of sidebarMenu.filter((s) => s.children?.length)) {
            // `html()` is serialised markup, so an ampersand in a label arrives escaped.
            expect(html, `section ${section.label} disappeared`).toContain(section.label.replace(/&/g, '&amp;'));
        }
    });

    it('opens the branch that contains the current page', async () => {
        const wrapper = await mountSidebar('/hrm/employees/index', null);

        expect(wrapper.findAll('[data-state="open"]').length).toBeGreaterThan(0);
        expect(wrapper.html()).toContain('Employees');
    });

    it('bands the sections by workflow, and keeps each band contiguous', async () => {
        const wrapper = await mountSidebar('/core/users', null);

        const html = wrapper.html();
        for (const band of ['People', 'Supply &amp; Sales', 'Finance', 'Administration']) {
            expect(html, `band header ${band} was not drawn`).toContain(band);
        }

        // A band that is not contiguous gets its header drawn twice, which reads as two different
        // groups with the same name. This is the check that keeps the menu ordered by workflow.
        const seen: string[] = [];
        for (const item of sidebarMenu) {
            if (!item.group) continue;
            if (seen[seen.length - 1] !== item.group) {
                expect(seen, `band '${item.group}' is split across the menu`).not.toContain(item.group);
                seen.push(item.group);
            }
        }

        expect(seen).toEqual(['People', 'Supply & Sales', 'Finance', 'Administration']);
    });

    it('focuses the menu filter on Ctrl/⌘K, which the placeholder always claimed', async () => {
        await mountSidebar('/core/users', null);

        const filter = document.querySelector('input[type="search"]') as HTMLInputElement | null;
        expect(filter, 'the filter input must exist').not.toBeNull();
        expect(document.activeElement).not.toBe(filter);

        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'k', ctrlKey: true, bubbles: true }));
        await nextTick();

        expect(document.activeElement).toBe(filter);
    });

    it('says who is signed in instead of advertising the product', async () => {
        const wrapper = await mountSidebar('/core/users', { name: 'Rina Akter', email: 'rina@example.com' });

        const html = wrapper.html();
        expect(html).toContain('Rina Akter');
        expect(html).toContain('rina@example.com');

        for (const marketing of ['Production ready', 'Secure session', 'audit on', '>PRO<']) {
            expect(html, `the sidebar still advertises: ${marketing}`).not.toContain(marketing);
        }
    });

    it('draws every leaf in the menu, including the ones three levels down', async () => {
        // Ten items were unreachable: `Accounts › Financial Reports` and `Inventory › Inventory
        // Reports` are groups, and the renderer treated a group as a leaf linking to '#'. Opening
        // every branch first, so this judges reachability and not merely the collapsed state.
        const state: Record<string, boolean> = {};
        const walk = (items: typeof sidebarMenu) => {
            for (const item of items) {
                if (item.children?.length) {
                    state[item.key] = true;
                    walk(item.children);
                }
            }
        };
        walk(sidebarMenu);
        window.localStorage.setItem('core.sidebar.tree.v2', JSON.stringify(state));

        const wrapper = await mountSidebar('/core/users', null);
        await nextTick();
        const html = wrapper.html();

        const leaves: string[] = [];
        const collect = (items: typeof sidebarMenu) => {
            for (const item of items) {
                if (item.children?.length) collect(item.children);
                else leaves.push(item.label);
            }
        };
        collect(sidebarMenu);

        expect(leaves.length).toBeGreaterThan(90);

        const missing = leaves.filter((label) => !html.includes(label.replace(/&/g, '&amp;')));
        expect(missing, 'these menu items cannot be reached from the sidebar').toEqual([]);

        // The two report groups must be drawn as branches, not as dead links.
        expect(html).toContain('Financial Reports');
        expect(html).toContain('Trial Balance');
        expect(html).toContain('FIFO Layers');
    });

    it('opens down to the trial balance when that is the current page', async () => {
        const wrapper = await mountSidebar(route('accounts.reports.trial'), null);
        await nextTick();

        const html = wrapper.html();
        expect(html, 'Accounts did not open').toContain('Financial Reports');
        expect(html, 'the trial balance is not in the sidebar').toContain('Trial Balance');
        expect(wrapper.findAll('[data-state="open"]').length).toBeGreaterThan(1);
    });

    it('finds an item three levels down when filtering', async () => {
        const wrapper = await mountSidebar('/core/users', null);

        const filter = wrapper.find('input[type="search"]');
        expect(filter.exists()).toBe(true);

        await filter.setValue('trial');
        await nextTick();
        await nextTick();

        expect(wrapper.html()).toContain('Trial Balance');
    });

    it('refuses a menu deeper than the sidebar can draw', () => {
        // The renderer draws three levels. This is the check that keeps the config honest about it:
        // a fourth level would be silently unreachable, exactly as the third one was.
        const depth = (items: typeof sidebarMenu): number =>
            items.reduce((max, item) => Math.max(max, 1 + (item.children?.length ? depth(item.children) : 0)), 0);

        expect(depth(sidebarMenu)).toBeLessThanOrEqual(3);
    });

    it('keeps every label plain text — no emoji, no stray padding', () => {
        // Read from the config rather than the DOM, so a label hidden from this role is still judged.
        const labels: string[] = [];
        for (const item of sidebarMenu) {
            labels.push(item.label);
            for (const child of item.children ?? []) labels.push(child.label);
        }

        expect(labels.length).toBeGreaterThan(90);

        for (const label of labels) {
            expect(label, `label is not plain ASCII: ${label}`).toMatch(/^[\x20-\x7E]+$/);
            expect(label, `label has padding or a doubled space: ${label}`).toBe(label.trim().replace(/\s{2,}/g, ' '));
        }
    });
});
