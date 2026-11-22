import { mount } from '@vue/test-utils';
import { defineComponent, h, onMounted } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { resetSidebarScroll, useSidebarScroll } from '../Composables/useSidebarScroll';

// Radix opens a persisted tree over several frames. The browser clamps a scroll assignment
// until the list is tall enough; a unit test must model that explicitly (jsdom has no layout).
describe('sidebar restoration while the tree grows', () => {
    let heightLimit = 5000;
    let stored = 0;
    const host = document.createElement('div');
    Object.defineProperty(host, 'scrollTop', {
        configurable: true,
        get: () => stored,
        set: (v: number) => { stored = Math.min(v, heightLimit); },
    });
    const Harness = defineComponent({
        setup() {
            const scroll = useSidebarScroll(() => host);
            onMounted(scroll.settle);
            return () => h('div');
        },
    });
    beforeEach(() => { vi.useFakeTimers(); resetSidebarScroll(); heightLimit = 5000; stored = 0; });
    afterEach(() => { vi.useRealTimers(); });

    it('reapplies the saved offset after the list grows', () => {
        const first = mount(Harness);
        host.scrollTop = 3500;
        first.unmount();
        heightLimit = 900;
        const second = mount(Harness);
        expect(host.scrollTop).toBe(900);
        heightLimit = 5000;
        vi.advanceTimersByTime(350);
        expect(host.scrollTop).toBe(3500);
        second.unmount();
    });

    it('stops restoring as soon as the user interacts', () => {
        const first = mount(Harness);
        host.scrollTop = 3500;
        first.unmount();
        heightLimit = 900;
        const second = mount(Harness);
        host.dispatchEvent(new Event('wheel'));
        host.scrollTop = 123;
        heightLimit = 5000;
        vi.advanceTimersByTime(800);
        expect(host.scrollTop).toBe(123);
        second.unmount();
    });
});
