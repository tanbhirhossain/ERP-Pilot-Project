import type { AclProps, CoreSharedProps, FlashProps } from '../types';
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

/**
 * Permission-aware UI helpers backed by the props the CORE module shares
 * through Inertia::share(). Server-side middleware is always the real gate —
 * this only keeps the interface honest and clutter-free.
 */
export function useAcl() {
    const page = usePage<{ props: CoreSharedProps & Record<string, unknown> }>();

    const acl = computed<AclProps>(
        () =>
            page.props.acl ?? {
                permissions: [],
                roles: [],
                is_super_admin: false,
            },
    );

    const can = (permission: string): boolean =>
        acl.value.is_super_admin || acl.value.permissions.includes(permission);

    const canAny = (permissions: string[]): boolean =>
        acl.value.is_super_admin || permissions.some((permission) => acl.value.permissions.includes(permission));

    return { acl, can, canAny };
}

export function useFlash() {
    const page = usePage<{ props: CoreSharedProps & Record<string, unknown> }>();

    const flash = computed<FlashProps>(() => page.props.flash ?? {});

    return { flash };
}
