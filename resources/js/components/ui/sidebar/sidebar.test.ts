import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it } from 'vitest';
import { defineComponent, h, nextTick, ref } from 'vue';
import {
    Sidebar,
    SidebarContent,
    SidebarGroup,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarProvider,
    SidebarTrigger,
} from '@/components/ui/sidebar';

/**
 * Regression tests for the collapsible sidebar.
 *
 * Mounts the real kit components (no stubs) and drives the actual trigger,
 * so the DOM contract the CSS relies on — `data-state` + `data-collapsible`
 * on the `.group` wrapper — is verified rather than assumed.
 */
const Harness = {
    render() {
        return h(SidebarProvider, null, {
            default: () => [
                h(
                    Sidebar,
                    { collapsible: 'icon' },
                    {
                        default: () =>
                            h(SidebarContent, null, {
                                default: () =>
                                    h(SidebarGroup, null, {
                                        default: () =>
                                            h(SidebarMenu, null, {
                                                default: () =>
                                                    h(SidebarMenuItem, null, {
                                                        default: () =>
                                                            h(SidebarMenuButton, null, {
                                                                default: () => 'Dashboard',
                                                            }),
                                                    }),
                                            }),
                                    }),
                            }),
                    },
                ),
                h(SidebarTrigger),
            ],
        });
    },
};

/**
 * Mirrors how `AdminLayout` wires the provider: `:open` bound to a local ref
 * plus `@update:open`, i.e. the *controlled* configuration the app actually
 * ships (the uncontrolled harness above only proves the kit default).
 */
const ControlledHarness = defineComponent({
    setup() {
        const open = ref(true);

        return () =>
            h(
                SidebarProvider,
                { open: open.value, 'onUpdate:open': (value: boolean) => (open.value = value) },
                {
                    default: () => [
                        h(Sidebar, { collapsible: 'icon' }, { default: () => h(SidebarGroup, null, { default: () => 'Nav' }) }),
                        h(SidebarTrigger),
                    ],
                },
            );
    },
});

function mountSidebar() {
    return mount(Harness, { attachTo: document.body });
}

describe('collapsible sidebar', () => {
    beforeEach(() => {
        document.cookie = 'sidebar:state=; path=/; max-age=0';
        document.body.innerHTML = '';
    });

    it('starts expanded', () => {
        const wrapper = mountSidebar();

        expect(wrapper.find('[data-state="expanded"]').exists()).toBe(true);
        expect(wrapper.find('[data-state="collapsed"]').exists()).toBe(false);
    });

    it('collapses when the trigger is clicked', async () => {
        const wrapper = mountSidebar();
        const trigger = wrapper.find('[data-sidebar="trigger"]');

        expect(trigger.exists()).toBe(true);

        await trigger.trigger('click');
        await nextTick();

        expect(wrapper.find('[data-state="collapsed"]').exists()).toBe(true);
        expect(wrapper.find('[data-state="expanded"]').exists()).toBe(false);
        expect(wrapper.find('[data-collapsible="icon"]').exists()).toBe(true);
    });

    it('expands again on a second click', async () => {
        const wrapper = mountSidebar();
        const trigger = wrapper.find('[data-sidebar="trigger"]');

        await trigger.trigger('click');
        await trigger.trigger('click');
        await nextTick();

        expect(wrapper.find('[data-state="expanded"]').exists()).toBe(true);
        expect(wrapper.find('[data-collapsible="icon"]').exists()).toBe(false);
    });

    it('collapses in controlled mode (the configuration AdminLayout uses)', async () => {
        const wrapper = mount(ControlledHarness, { attachTo: document.body });
        const trigger = wrapper.find('[data-sidebar="trigger"]');

        expect(wrapper.find('[data-state="expanded"]').exists()).toBe(true);

        await trigger.trigger('click');
        await nextTick();

        expect(wrapper.find('[data-state="collapsed"]').exists()).toBe(true);
        expect(wrapper.find('[data-collapsible="icon"]').exists()).toBe(true);

        await trigger.trigger('click');
        await nextTick();

        expect(wrapper.find('[data-state="expanded"]').exists()).toBe(true);
    });

    it('persists the chosen state to the cookie', async () => {
        const wrapper = mountSidebar();

        await wrapper.find('[data-sidebar="trigger"]').trigger('click');
        await nextTick();

        expect(document.cookie).toContain('sidebar:state=false');
    });
});
