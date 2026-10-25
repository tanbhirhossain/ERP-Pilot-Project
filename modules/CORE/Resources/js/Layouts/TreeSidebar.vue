<script setup lang="ts">
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import {
    Sidebar,
    SidebarGroup,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
    SidebarRail,
} from '@/components/ui/sidebar';
import { Link, usePage } from '@inertiajs/vue3';
import { ChevronRight } from 'lucide-vue-next';
import { computed, onMounted, ref, watch } from 'vue';
import { useAcl } from '../Composables/useAcl';
import { sidebarMenu, type SidebarItem } from '../Config/menu';

/**
 * Collapsible tree sidebar.
 *
 * - Nested, animated tree nodes (accordion motion, chevron rotation)
 * - Expanded state persisted per browser (localStorage)
 * - Active branch auto-expands on load
 * - Permission-aware: nodes the user cannot use simply do not exist in the UI
 * - Lives entirely inside the CORE module (the app shell stays untouched)
 */
const STORAGE_KEY = 'core.sidebar.tree.v1';

const { can } = useAcl();
const page = usePage();

const openState = ref<Record<string, boolean>>({});
let hydrated = false;

function isAllowed(item: SidebarItem): boolean {
    if (item.children?.length) {
        return item.children.some((child) => isAllowed(child));
    }

    return !item.permission || can(item.permission);
}

const visibleMenu = computed<SidebarItem[]>(() =>
    sidebarMenu.filter(isAllowed).map((item) => (item.children?.length ? { ...item, children: item.children.filter(isAllowed) } : item)),
);

function hrefFor(item: SidebarItem): string {
    return item.route ? route(item.route) : '#';
}

function itemPath(item: SidebarItem): string | null {
    if (!item.route) return null;

    try {
        return new URL(route(item.route), window.location.origin).pathname;
    } catch {
        return null;
    }
}

function isActive(item: SidebarItem): boolean {
    const path = itemPath(item);

    return path !== null && (page.url === path || page.url.startsWith(`${path}/`) || page.url.startsWith(`${path}?`));
}

function hasActiveChild(item: SidebarItem): boolean {
    return (item.children ?? []).some((child) => isActive(child));
}

function expandActiveBranches(items: SidebarItem[] = visibleMenu.value) {
    for (const item of items) {
        if (item.children?.length && hasActiveChild(item)) {
            openState.value[item.key] = true;
        }
    }
}

/* ---------------------------- persistence ------------------------------- */

function loadState() {
    try {
        const raw = localStorage.getItem(STORAGE_KEY);

        if (raw) {
            openState.value = JSON.parse(raw) as Record<string, boolean>;
            hydrated = true;
            return;
        }
    } catch {
        /* corrupted state — fall through to defaults */
    }

    // Default: everything open for a friendly first run.
    const defaults: Record<string, boolean> = {};
    for (const item of sidebarMenu) {
        if (item.children?.length) defaults[item.key] = true;
    }
    openState.value = defaults;
    hydrated = true;
}

watch(
    openState,
    () => {
        if (!hydrated) return;

        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(openState.value));
        } catch {
            /* storage unavailable (private mode) — ignore */
        }
    },
    { deep: true },
);

onMounted(() => {
    loadState();
    expandActiveBranches();
});
</script>

<template>
    <Sidebar collapsible="icon">
        <SidebarGroup class="border-sidebar-border/70 border-b px-3 py-3">
            <Link
                :href="route('core.dashboard')"
                class="group focus-visible:ring-sidebar-ring flex items-center gap-2.5 rounded-md outline-none focus-visible:ring-2"
            >
                <span
                    class="from-primary text-primary-foreground shadow-primary/30 grid size-8 shrink-0 place-items-center rounded-lg bg-gradient-to-br to-violet-600 shadow-sm"
                >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" class="size-4.5">
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            d="M4 6a2 2 0 0 1 2-2h4l2 2h6a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6z"
                        />
                    </svg>
                </span>
                <span class="flex min-w-0 flex-col leading-tight transition group-data-[collapsible=icon]:hidden">
                    <span class="text-sidebar-foreground truncate text-sm font-semibold">{{ $page.props.name }}</span>
                    <span class="text-sidebar-foreground/60 truncate text-[11px]">Admin Console</span>
                </span>
            </Link>
        </SidebarGroup>

        <SidebarGroup class="px-2 py-3">
            <p
                class="text-sidebar-foreground/50 px-2 pb-2 text-[10px] font-semibold tracking-[0.14em] uppercase group-data-[collapsible=icon]:hidden"
            >
                Navigation
            </p>
            <SidebarMenu>
                <template v-for="item in visibleMenu" :key="item.key">
                    <!-- Leaf -->
                    <SidebarMenuItem v-if="!item.children?.length">
                        <SidebarMenuButton as-child :is-active="isActive(item)" :tooltip="item.label" class="h-9">
                            <Link :href="hrefFor(item)">
                                <component :is="item.icon" class="text-sidebar-foreground/70 size-4" />
                                <span class="font-medium">{{ item.label }}</span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>

                    <!-- Branch (tree node) -->
                    <Collapsible v-else v-model:open="openState[item.key]" as-child>
                        <SidebarMenuItem>
                            <CollapsibleTrigger as-child>
                                <SidebarMenuButton :is-active="hasActiveChild(item)" :tooltip="item.label" class="h-9">
                                    <component :is="item.icon" class="text-sidebar-foreground/70 size-4" />
                                    <span class="font-medium">{{ item.label }}</span>
                                    <ChevronRight
                                        class="text-sidebar-foreground/50 ml-auto size-4 shrink-0 transition-transform duration-200 group-data-[state=open]:rotate-90"
                                    />
                                </SidebarMenuButton>
                            </CollapsibleTrigger>

                            <CollapsibleContent class="tree-content overflow-hidden">
                                <SidebarMenuSub class="border-sidebar-border/60 ml-3 border-l pr-0 pl-2 group-data-[collapsible=icon]:hidden">
                                    <SidebarMenuSubItem v-for="child in item.children" :key="child.key">
                                        <SidebarMenuSubButton as-child :is-active="isActive(child)" class="h-8">
                                            <Link :href="hrefFor(child)">
                                                <component :is="child.icon" class="size-3.5" />
                                                <span>{{ child.label }}</span>
                                            </Link>
                                        </SidebarMenuSubButton>
                                    </SidebarMenuSubItem>
                                </SidebarMenuSub>
                            </CollapsibleContent>
                        </SidebarMenuItem>
                    </Collapsible>
                </template>
            </SidebarMenu>
        </SidebarGroup>

        <div class="mt-auto">
            <SidebarGroup class="border-sidebar-border/70 border-t p-2">
                <div
                    class="bg-sidebar-accent/60 text-sidebar-foreground/70 flex items-center gap-2 rounded-lg px-2.5 py-2 text-xs group-data-[collapsible=icon]:hidden"
                >
                    <span class="relative flex size-2">
                        <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75" />
                        <span class="relative inline-flex size-2 rounded-full bg-emerald-500" />
                    </span>
                    Secure session · audit recording active
                </div>
            </SidebarGroup>
        </div>

        <!-- Click or drag the rail to collapse / expand -->
        <SidebarRail />
    </Sidebar>
</template>

<style scoped>
/* Accordion motion for tree branches (height animates to the measured
   content height provided by Radix Collapsible). */
.tree-content[data-state='open'] {
    animation: tree-expand 0.22s cubic-bezier(0.3, 0, 0.2, 1);
}

.tree-content[data-state='closed'] {
    animation: tree-collapse 0.18s cubic-bezier(0.3, 0, 0.2, 1);
}

@keyframes tree-expand {
    from {
        height: 0;
        opacity: 0.4;
    }
    to {
        height: var(--radix-collapsible-content-height);
        opacity: 1;
    }
}

@keyframes tree-collapse {
    from {
        height: var(--radix-collapsible-content-height);
        opacity: 1;
    }
    to {
        height: 0;
        opacity: 0.4;
    }
}
</style>
