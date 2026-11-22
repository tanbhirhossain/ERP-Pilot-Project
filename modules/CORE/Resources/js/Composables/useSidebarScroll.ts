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
 *
 * Restore the prior offset without moving the window. The scroll host must use instant scrolling.
 */
let rememberedScrollTop = 0;

/** Forget the remembered offset, as a fresh page load would. Used by tests and by nothing in the app. */
export function resetSidebarScroll(): void {
    rememberedScrollTop = 0;
}

export interface SidebarScroll {
    /** Restore the offset as the list grows after mounting. */
    settle: () => void;
    /** Save the final offset and stop listening. Call on unmount, before the new shell takes over. */
    teardown: () => void;
}

/**
 * @param host        Resolves the scrolling element; the sidebar renders it inside a kit component, so it
 *                    is not always mounted yet when this is set up.
 */
export function useSidebarScroll(
    host: () => HTMLElement | null,
): SidebarScroll {
    let frame = 0;
    let listening: HTMLElement | null = null;
    let restoring = false;
    let timers: number[] = [];
    function cancelRestore() {
        timers.forEach(clearTimeout);
        timers = [];
        restoring = false;
    }
    function onUserInteraction() { cancelRestore(); }

    // Coalesced into one animation frame: `scroll` fires far more often than the list moves meaningfully,
    // and this listener sits on an element people drag a thumb across.
    function onScroll(event: Event) {
        const el = event.target as HTMLElement | null;

        if (!el || frame || restoring) return;

        frame = requestAnimationFrame(() => {
            frame = 0;
            rememberedScrollTop = el.scrollTop;
        });
    }

    function listen(el: HTMLElement) {
        if (listening === el) return;

        listening = el;
        el.addEventListener('scroll', onScroll, { passive: true });
        el.addEventListener('wheel', onUserInteraction, { passive: true });
        el.addEventListener('pointerdown', onUserInteraction, { passive: true });
        el.addEventListener('keydown', onUserInteraction);
    }

    function settle() {
        const el = host();

        if (!el) return;

        cancelRestore();
        const target = rememberedScrollTop;
        if (frame) { cancelAnimationFrame(frame); frame = 0; }
        listen(el);
        if (target === 0) { el.scrollTop = 0; return; }
        restoring = true;
        el.scrollTop = target;
        // Radix mounts nested branches asynchronously: an early write can be clamped against a
        // temporarily short list. Retry after it grows, without recording those temporary offsets.
        for (const delay of [100, 350, 700]) {
            timers.push(window.setTimeout(() => {
                el.scrollTop = target;
                if (delay === 700) { restoring = false; timers = []; rememberedScrollTop = el.scrollTop; }
            }, delay));
        }
    }

    function teardown() {
        const el = host();

        if (el) {
            // The frame above may not have fired yet — a click that navigates can land in the same frame as
            // the scroll before it — so read the element one last time. This is also the write that matters:
            // Vue unmounts the old page before it mounts the new one, so the next shell reads this value.
            if (!restoring) rememberedScrollTop = el.scrollTop;
            el.removeEventListener('scroll', onScroll);
            el.removeEventListener('wheel', onUserInteraction);
            el.removeEventListener('pointerdown', onUserInteraction);
            el.removeEventListener('keydown', onUserInteraction);
        }

        cancelRestore();
        listening = null;

        if (frame) {
            cancelAnimationFrame(frame);
            frame = 0;
        }


    }

    onBeforeUnmount(teardown);

    return { settle, teardown };
}
