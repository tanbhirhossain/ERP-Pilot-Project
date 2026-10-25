/* Declarative collapsible-tree sidebar menu for the CORE admin shell. */
import type { LucideIcon } from 'lucide-vue-next';
import { History, KeyRound, LayoutDashboard, Palette, ScrollText, Shield, ShieldCheck, Users } from 'lucide-vue-next';

export interface SidebarItem {
    key: string;
    label: string;
    /** Ziggy route name — links are generated with route() at render time. */
    route?: string;
    /** Tree-shaken lucide icon component. */
    icon: LucideIcon;
    /** Client-side ACL hint (the server middleware remains the real gate). */
    permission?: string;
    children?: SidebarItem[];
}

export const sidebarMenu: SidebarItem[] = [
    {
        key: 'dashboard',
        label: 'Dashboard',
        route: 'core.dashboard',
        icon: LayoutDashboard,
        permission: 'dashboard.view',
    },
    {
        key: 'access-control',
        label: 'Access Control',
        icon: ShieldCheck,
        children: [
            { key: 'users', label: 'Users', route: 'core.users.index', icon: Users, permission: 'users.view' },
            { key: 'roles', label: 'Roles', route: 'core.roles.index', icon: Shield, permission: 'roles.view' },
            {
                key: 'permissions',
                label: 'Permissions',
                route: 'core.permissions.index',
                icon: KeyRound,
                permission: 'permissions.view',
            },
        ],
    },
    {
        key: 'design-system',
        label: 'Design System',
        route: 'core.ui-kit',
        icon: Palette,
        permission: 'dashboard.view',
    },
    {
        key: 'compliance',
        label: 'Audit & Compliance',
        icon: ScrollText,
        children: [
            {
                key: 'activities',
                label: 'Audit Trail',
                route: 'core.activities.index',
                icon: History,
                permission: 'audit.view',
            },
        ],
    },
];
