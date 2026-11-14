import { onBeforeUnmount } from 'vue';

/**
 * Keeps the sidebar's scroll position across navigations.
 *
 * Why this exists at all: every page renders its own `AdminLayout`, so an Inertia visit tears the whole
 * shell down and mounts a new one — and a freshly created scroll container always begins at
 * `scrollTop: 0`. The reported symptom was that the menu "jumps back to the top after a click, which is
 * very disturbing": you scroll to a section near the bottom, click it, and lose your place in the list you
 * were just using. The open/closed state of the branches was already remembered in `localStorage`; the
 * offset was the half nobody saved.
 *
 * The value lives at module scope, deliberately not in `localStorage`: it has to survive client-side
 * navigation (the module is evaluated once per load) and reset on a real reload, which is the one case
 * where scrolling back to the top is correct. Anything persisted would also mean a fresh morning opens at
 * wherever yesterday's session happened to stop.
 */
let rememberedScrollTop = 0;

/** Forget the remembered offset, as a fresh page load would. Used by tests and by nothing in the app. */
export function resetSidebarScroll(): void {
    rememberedScrollTop = 0;
}

export interface SidebarScroll {
    /** Restore the offset and bring the current item back into view. Call once the list has its final height. */
    settle: () => void;
    /** Save the final offset and stop listening. Call on unmount, before the new shell takes over. */
    teardown: () => void;
}

/**
 * @param host        Resolves the scrolling element; the sidebar renders it inside a kit component, so it
 *                    is not always mounted yet when this is set up.
 * @param activeSelector The link that represents the page you are on, so the nudge knows what to keep visible.
 */
export function useSidebarScroll(
    host: () => HTMLElement | null,
    activeSelector = '[data-nav-target][data-active="true"]',
): SidebarScroll {
    let frame = 0;
    let listening: HTMLElement | null = null;

    // Coalesced into one animation frame: `scroll` fires far more often than the list moves meaningfully,
    // and this listener sits on an element people drag a thumb across.
    function onScroll(event: Event) {
        const el = event.target as HTMLElement | null;

        if (!el || frame) return;

        frame = requestAnimationFrame(() => {
            frame = 0;
            rememberedScrollTop = el.scrollTop;
        });
    }

    function listen(el: HTMLElement) {
        if (listening === el) return;

        listening = el;
        el.addEventListener('scroll', onScroll, { passive: true });
    }

    function settle() {
        const el = host();

        if (!el) return;

        el.scrollTop = rememberedScrollTop;
        listen(el);

        // Restoring alone is not enough when the click *opened* a branch above the target — reaching
        // `Administration › Audit Trail` from a closed section draws rows that push the item down by its
        // own height, and an offset that was right a moment ago now shows the wrong part of the list. So
        // nudge by the minimum that brings the current item inside the box. That is why this is hand-rolled
        // rather than `scrollIntoView`, which would also scroll the *window* when the sidebar is not fully
        // in view — the page jump belongs to Inertia, not to the menu.
        const active = el.querySelector<HTMLElement>(activeSelector);

        if (!active) return;

        const box = el.getBoundingClientRect();
        const item = active.getBoundingClientRect();

        if (item.height === 0 || box.height === 0) return;

        if (item.top < box.top) {
            el.scrollTop -= box.top - item.top + 8;
        } else if (item.bottom > box.bottom) {
            el.scrollTop += item.bottom - box.bottom + 8;
        }
    }

    function teardown() {
        const el = host();

        if (el) {
            // The frame above may not have fired yet — a click that navigates can land in the same frame as
            // the scroll before it — so read the element one last time. This is also the write that matters:
            // Vue unmounts the old page before it mounts the new one, so the next shell reads this value.
            rememberedScrollTop = el.scrollTop;
            el.removeEventListener('scroll', onScroll);
        }

        listening = null;

        if (frame) {
            cancelAnimationFrame(frame);
            frame = 0;
        }
    }

    onBeforeUnmount(teardown);

    return { settle, teardown };
}
