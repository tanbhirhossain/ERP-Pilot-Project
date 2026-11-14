import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted } from 'vue';
import { useConfirm } from './useConfirm';

/**
 * Unsaved-changes guard (Phase 2 — U11).
 *
 * Pass a getter that reports whether the form is dirty (e.g. `() => form.isDirty`
 * from Inertia's useForm). The guard then:
 *
 *   - warns on tab close / refresh via `beforeunload`;
 *   - intercepts Inertia *link* navigation (GET visits) and asks before discarding.
 *
 * It deliberately does NOT block non-GET visits, so the form's own save/submit
 * (POST/PUT/PATCH/DELETE) always goes through — the guard protects against leaving
 * the page, not against saving it. After a confirmed discard the original target
 * is re-visited with the guard bypassed, and the listener is torn down on unmount.
 */
export function useUnsavedGuard(isDirty: () => boolean, message?: string): void {
    const { confirm } = useConfirm();
    let bypass = false;

    const onBeforeUnload = (e: BeforeUnloadEvent): void => {
        if (isDirty() && !bypass) {
            e.preventDefault();
            // Chrome requires returnValue to be set to trigger the native prompt.
            e.returnValue = '';
        }
    };

    const removeListener = router.on('before', (event) => {
        const visit = event.detail.visit;

        // Only guard link navigation; never block the form's own save submission.
        if (visit.method !== 'get' || bypass || !isDirty()) {
            return;
        }

        event.preventDefault();

        void confirm({
            title: 'Discard unsaved changes?',
            description: message ?? 'You have unsaved changes on this page. If you leave now they will be lost.',
            confirmLabel: 'Discard & leave',
            cancelLabel: 'Stay on page',
            danger: true,
        }).then((ok) => {
            if (ok) {
                bypass = true;
                router.visit(visit.url);
            }
        });
    });

    onMounted(() => window.addEventListener('beforeunload', onBeforeUnload));

    onBeforeUnmount(() => {
        window.removeEventListener('beforeunload', onBeforeUnload);
        removeListener();
    });
}
