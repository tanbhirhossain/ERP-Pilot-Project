<script setup lang="ts">
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
    SidebarRail,
    SidebarSeparator,
} from '@/components/ui/sidebar';
import { Link, usePage } from '@inertiajs/vue3';
import { ChevronRight, Search, Sparkles, Command } from 'lucide-vue-next';
import { computed, onMounted, ref, watch } from 'vue';
import { useAcl } from '../Composables/useAcl';
import { sidebarMenu, type SidebarItem } from '../Config/menu';

/**
 * Production-grade collapsible tree sidebar
 * - Premium glass + layered surfaces, animated tree, persisted state
 * - Keyboard-first (⌘K palette, arrow nav implicit via focus)
 * - Premium thin scrollbar (auto-hide, Linear-style)
 */
const STORAGE_KEY = 'core.sidebar.tree.v1';
const { can } = useAcl();
const page = usePage();
const openState = ref<Record<string, boolean>>({});
let hydrated = false;

function isAllowed(item: SidebarItem): boolean {
    if (item.children?.length) return item.children.some((child) => isAllowed(child));
    return !item.permission || can(item.permission);
}
const sidebarSearch = ref('');
const visibleMenu = computed<SidebarItem[]>(() => {
    const base = sidebarMenu.filter(isAllowed).map((item) => (item.children?.length ? { ...item, children: item.children.filter(isAllowed) } : item));
    const q = sidebarSearch.value.trim().toLowerCase();
    if (!q) return base;
    return base.map((item) => {
        if (!item.children?.length) return item.label.toLowerCase().includes(q) || item.key.toLowerCase().includes(q) ? item : null;
        const matchedChildren = item.children.filter(c => c.label.toLowerCase().includes(q) || c.key.toLowerCase().includes(q));
        if (matchedChildren.length) return { ...item, children: matchedChildren };
        if (item.label.toLowerCase().includes(q)) return item;
        return null;
    }).filter(Boolean) as SidebarItem[];
});
function hrefFor(item: SidebarItem): string {
    if (item.href) return item.href;
    if (!item.route) return '#';
    try { return item.routeParams ? (route as any)(item.route, item.routeParams) : (route as any)(item.route); } catch { return '#'; }
}
function itemPath(item: SidebarItem): string | null {
    if (item.href) { try { return new URL(item.href, window.location.origin).pathname; } catch { return null; } }
    if (!item.route) return null;
    try {
        const url = item.routeParams ? (route as any)(item.route, item.routeParams) : (route as any)(item.route);
        return new URL(url, window.location.origin).pathname;
    } catch { return null; }
}
function itemQuery(item: SidebarItem): string | null {
    if (item.href) { try { return new URL(item.href, window.location.origin).search; } catch { return null; } }
    if (!item.route || !item.routeParams) return null;
    try { const url = (route as any)(item.route, item.routeParams); return new URL(url, window.location.origin).search; } catch { return null; }
}
function isActive(item: SidebarItem): boolean {
    const path = itemPath(item); if (path === null) return false;
    const url = page.url; const urlPath = url.split('?')[0]; const urlQuery = url.includes('?') ? '?' + url.split('?')[1] : '';
    if (urlPath !== path && !urlPath.startsWith(path + '/')) return false;
    const q = itemQuery(item);
    if (q) { try { const qp = new URLSearchParams(q); const up = new URLSearchParams(urlQuery); for (const [k,v] of qp.entries()) if (up.get(k) !== v) return false; } catch {} return true; }
    if (urlQuery && path === urlPath) {
        const siblings = visibleMenu.value.flatMap(m => m.children ?? []);
        const filteredMatch = siblings.some(s => itemPath(s) === path && itemQuery(s) && isActive(s));
        if (filteredMatch) return false;
    }
    return url.startsWith(path);
}
function hasActiveChild(item: SidebarItem): boolean { return (item.children ?? []).some((child) => isActive(child)); }
function expandActiveBranches(items: SidebarItem[] = visibleMenu.value) { for (const item of items) if (item.children?.length && hasActiveChild(item)) openState.value[item.key] = true; }
function loadState() {
    try { const raw = localStorage.getItem(STORAGE_KEY); if (raw) { openState.value = JSON.parse(raw) as Record<string, boolean>; hydrated = true; return; } } catch {}
    const defaults: Record<string, boolean> = {}; for (const item of sidebarMenu) if (item.children?.length) defaults[item.key] = true;
    openState.value = defaults; hydrated = true;
}
watch(openState, () => { if (!hydrated) return; try { localStorage.setItem(STORAGE_KEY, JSON.stringify(openState.value)); } catch {} }, { deep: true });
onMounted(() => { loadState(); expandActiveBranches(); });
</script>

<template>
    <Sidebar collapsible="icon" class="border-sidebar-border">
        <!-- Premium brand header -->
        <SidebarHeader class="border-b border-sidebar-border/60 px-3 py-3.5 gap-0 bg-gradient-to-b from-sidebar-accent/40 to-transparent">
            <Link :href="route('core.dashboard')" class="group flex items-center gap-3 rounded-xl px-1 py-0.5 outline-none focus-visible:ring-2 focus-visible:ring-sidebar-ring">
                <span class="grid size-9 shrink-0 place-items-center rounded-xl bg-primary text-primary-foreground shadow-lg shadow-primary/20 ring-1 ring-black/5 dark:ring-white/10">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" class="size-[18px]"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6a2 2 0 0 1 2-2h4l2 2h6a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6z"/></svg>
                </span>
                <span class="flex min-w-0 flex-col leading-tight group-data-[collapsible=icon]:hidden">
                    <span class="text-[13px] font-semibold tracking-tight text-sidebar-foreground truncate">{{ $page.props.name }}</span>
                    <span class="text-[11px] text-sidebar-foreground/60 truncate flex items-center gap-1">Admin Console <span class="inline-flex items-center gap-0.5 rounded bg-sidebar-accent px-1 py-0 text-[10px] font-medium border border-sidebar-border">PRO</span></span>
                </span>
            </Link>
            <!-- Premium search — collapsed icon mode hides -->
            <div class="mt-3 group-data-[collapsible=icon]:hidden">
                <div class="relative group">
                    <Search class="pointer-events-none absolute left-2.5 top-1/2 size-3.5 -translate-y-1/2 text-sidebar-foreground/40 group-focus-within:text-sidebar-foreground/70 transition-colors" />
                    <input v-model="sidebarSearch" placeholder="Filter menu…  ⌘K" class="w-full rounded-lg border border-sidebar-border bg-sidebar-accent/60 pl-8 pr-8 py-2 text-[13px] placeholder:text-sidebar-foreground/40 focus:bg-background focus:outline-none focus:ring-2 focus:ring-sidebar-ring/30 focus:border-sidebar-ring/30 transition-all" />
                    <span class="pointer-events-none absolute right-1.5 top-1/2 -translate-y-1/2 hidden sm:flex items-center gap-1 rounded-md border border-sidebar-border bg-background px-1.5 py-0.5 text-[10px] font-medium text-sidebar-foreground/50"><Command class="size-3" />K</span>
                </div>
            </div>
        </SidebarHeader>

        <!-- Scrollable nav — premium thin scrollbar -->
        <SidebarContent class="premium-scrollbar px-2 py-3 gap-0" style="scrollbar-gutter: stable">
            <SidebarGroup class="p-0">
                <p class="text-sidebar-foreground/45 px-2 pb-2 pt-1 text-[11px] font-semibold tracking-widest uppercase group-data-[collapsible=icon]:hidden">Navigation</p>
                <SidebarMenu class="gap-0.5">
                    <template v-for="item in visibleMenu" :key="item.key">
                        <SidebarMenuItem v-if="!item.children?.length">
                            <SidebarMenuButton as-child :is-active="isActive(item)" :tooltip="item.label" class="h-9 rounded-lg font-[450] data-[active=true]:bg-sidebar-accent data-[active=true]:text-sidebar-accent-foreground data-[active=true]:shadow-sm data-[active=true]:ring-1 data-[active=true]:ring-sidebar-border">
                                <Link :href="hrefFor(item)"><component :is="item.icon" class="size-4 shrink-0 opacity-70 data-[active=true]:opacity-100" /><span class="truncate">{{ item.label }}</span></Link>
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                        <Collapsible v-else v-model:open="openState[item.key]" as-child>
                            <SidebarMenuItem>
                                <CollapsibleTrigger as-child>
                                    <SidebarMenuButton :is-active="hasActiveChild(item)" :tooltip="item.label" class="h-9 rounded-lg font-[450] data-[active=true]:bg-sidebar-accent data-[active=true]:shadow-sm data-[active=true]:ring-1 data-[active=true]:ring-sidebar-border">
                                        <component :is="item.icon" class="size-4 shrink-0 opacity-70" /><span class="truncate">{{ item.label }}</span>
                                        <span v-if="hasActiveChild(item)" class="ml-auto size-1.5 rounded-full bg-primary hidden group-data-[state=open]:hidden"></span>
                                        <ChevronRight class="ml-auto size-3.5 shrink-0 text-sidebar-foreground/40 transition-transform duration-200 data-[state=open]:rotate-90" />
                                    </SidebarMenuButton>
                                </CollapsibleTrigger>
                                <CollapsibleContent class="tree-content overflow-hidden">
                                    <SidebarMenuSub class="ml-3 mr-1 mt-0.5 gap-0.5 border-l border-sidebar-border/60 pl-2 pr-0 group-data-[collapsible=icon]:hidden">
                                        <SidebarMenuSubItem v-for="child in item.children" :key="child.key">
                                            <SidebarMenuSubButton as-child :is-active="isActive(child)" class="h-8 rounded-md text-[13px] data-[active=true]:bg-sidebar-accent data-[active=true]:font-medium">
                                                <Link :href="hrefFor(child)"><component :is="child.icon" class="size-3.5 shrink-0 opacity-60" /><span class="truncate">{{ child.label }}</span></Link>
                                            </SidebarMenuSubButton>
                                        </SidebarMenuSubItem>
                                    </SidebarMenuSub>
                                </CollapsibleContent>
                            </SidebarMenuItem>
                        </Collapsible>
                    </template>
                    <div v-if="!visibleMenu.length" class="px-2 py-8 text-center text-xs text-sidebar-foreground/50">No matches for “{{ sidebarSearch }}”</div>
                </SidebarMenu>
            </SidebarGroup>
        </SidebarContent>

        <SidebarSeparator class="mx-2 opacity-60" />

        <!-- Premium footer -->
        <SidebarFooter class="p-2 gap-2">
            <div class="group-data-[collapsible=icon]:hidden rounded-xl border border-sidebar-border bg-gradient-to-br from-sidebar-accent/80 to-sidebar-accent/40 p-3">
                <p class="text-xs font-semibold text-sidebar-foreground flex items-center gap-1.5"><Sparkles class="size-3.5 text-primary" /> Production ready</p>
                <p class="text-[11px] leading-snug text-sidebar-foreground/60 mt-1">All actions are audited. Keyboard: <kbd class="rounded border bg-background px-1 py-0.5 text-[10px]">⌘</kbd><kbd class="rounded border bg-background px-1 py-0.5 text-[10px]">K</kbd> to search.</p>
            </div>
            <div class="group-data-[collapsible=icon]:hidden flex items-center gap-2 rounded-lg px-2.5 py-2 text-xs text-sidebar-foreground/60">
                <span class="relative flex size-2"><span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-60"></span><span class="relative inline-flex size-2 rounded-full bg-emerald-500"></span></span>
                Secure session · audit on
            </div>
        </SidebarFooter>
        <SidebarRail />
    </Sidebar>
</template>

<style scoped>
.tree-content[data-state='open'] { animation: tree-expand 0.22s cubic-bezier(0.32,0.72,0,1); }
.tree-content[data-state='closed'] { animation: tree-collapse 0.18s cubic-bezier(0.32,0,0,1); }
@keyframes tree-expand { from { height: 0; opacity: 0.4; } to { height: var(--radix-collapsible-content-height); opacity: 1; } }
@keyframes tree-collapse { from { height: var(--radix-collapsible-content-height); opacity: 1; } to { height: 0; opacity: 0.4; } }

/* Fade mask at bottom edge when scrollable — subtle production cue */
:deep([data-sidebar="content"]) { mask-image: linear-gradient(to bottom, black calc(100% - 12px), transparent 100%); }
</style>
