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
import { ChevronRight, Search, Command, X } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useAcl } from '../Composables/useAcl';
import { sidebarMenu, type SidebarItem } from '../Config/menu';

/**
 * The collapsible tree sidebar.
 *
 * Polished after the observation that it was "very disturbing" to use (report item 39). Concretely,
 * four things made it hard to read, and each had a cause worth naming rather than a colour worth
 * changing:
 *
 *  - **Every section opened on first load.** Twelve sections and a hundred items meant the menu was a
 *    wall of text with no shape, and the item you wanted was usually off-screen. Sections now start
 *    closed and only the branch containing the current page opens, so the sidebar reads as twelve
 *    headings until you ask for more. What you open is remembered.
 *  - **The sections were in build order, not work order.** Payroll sat between HRM and Roster, Access
 *    Control between Attendance and Vendors, Accounts between Inventory and Purchase. They are now
 *    banded — People, Supply & Sales, Finance, Administration — with a header drawn per band, so the
 *    tree follows the business rather than the order the modules were written in.
 *  - **The footer was advertising.** A "PRO" badge, a "Production ready" panel and a pinging green
 *    "Secure session · audit on" dot occupied permanent space, animated at the edge of your vision,
 *    and told an accountant nothing. It now says who is signed in, which is the one thing worth
 *    knowing when several people share a terminal.
 *  - **It advertised a shortcut it did not have.** Both the filter's placeholder and the footer
 *    promised ⌘K, and nothing listened for it. Ctrl/⌘K now focuses the filter and Escape clears it,
 *    so the hint is true.
 *
 * Also removed: the fade mask across the bottom of the scroll area, which made the last item in the
 * list look disabled, and the emoji five labels carried (📁 🎒 📊 🌊 🧩), which no other label did.
 *
 * And one outright defect, found by reading the menu against the renderer: **the tree only drew two
 * levels, but the config has three.** `Accounts › Financial Reports` and `Inventory › Inventory
 * Reports` are groups, and each was rendered as a single leaf linking to `#` — so ten items, the trial
 * balance, profit & loss, balance sheet, general ledger, both agings, bank reconciliation, FIFO
 * layers, reorder/ABC and lots/expiry, were unreachable from the shell for every role including the
 * Super Admin. Filtering could not find them either, for the same reason. Both are fixed here, and
 * `treeSidebarPolish.test.ts` pins the reachability rather than the markup.
 */
const STORAGE_KEY = 'core.sidebar.tree.v2';
const { can } = useAcl();
// usePage's generic is the *props* type, not the page: wrapping it in `{ props: … }` types every
// shared prop away, which is how `page.props.auth.user` ends up reading from `{}`.
const page = usePage<{ auth?: { user?: { name?: string; email?: string } | null }; name?: string }>();
const openState = ref<Record<string, boolean>>({});
let hydrated = false;

const currentUser = computed(() => page.props.auth?.user ?? null);
const appName = computed(() => page.props.name ?? 'ERP');

function isAllowed(item: SidebarItem): boolean {
    if (item.children?.length) return item.children.some((child) => isAllowed(child));
    return !item.permission || can(item.permission);
}

const sidebarSearch = ref('');
const filterRef = ref<HTMLInputElement | null>(null);

function labelMatches(item: SidebarItem, q: string): boolean {
    return item.label.toLowerCase().includes(q) || item.key.toLowerCase().includes(q);
}

/**
 * Filter to any depth, keeping the branches that lead to a match.
 *
 * This used to look at two levels only, which is why searching for "Trial" found nothing: the trial
 * balance sits inside `Financial Reports`, inside `Accounts`. A filter that cannot see an item is
 * indistinguishable from an item that does not exist.
 */
function filterTree(items: SidebarItem[], q: string): SidebarItem[] {
    const out: SidebarItem[] = [];

    for (const item of items) {
        if (!item.children?.length) {
            if (labelMatches(item, q)) out.push(item);
            continue;
        }

        // Naming the branch keeps the whole branch: searching "Reports" should show every report.
        if (labelMatches(item, q)) {
            out.push(item);
            continue;
        }

        const kept = filterTree(item.children, q);
        if (kept.length) out.push({ ...item, children: kept });
    }

    return out;
}

const visibleMenu = computed<SidebarItem[]>(() => {
    const allowed = (items: SidebarItem[]): SidebarItem[] =>
        items.filter(isAllowed).map((item) => (item.children?.length ? { ...item, children: allowed(item.children) } : item));

    const base = allowed(sidebarMenu);
    const q = sidebarSearch.value.trim().toLowerCase();

    return q ? filterTree(base, q) : base;
});

/** Draw a band header whenever the group changes, so grouped sections read as one block. */
function groupHeaderAt(index: number): string | null {
    const items = visibleMenu.value;
    const group = items[index]?.group;
    if (!group) return null;
    return index === 0 || items[index - 1]?.group !== group ? group : null;
}

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
function hasActiveChild(item: SidebarItem): boolean {
    return (item.children ?? []).some((child) => isActive(child) || hasActiveChild(child));
}
/** Opens every branch above the current page, at any depth, so you can see where you are. */
function expandActiveBranches(items: SidebarItem[] = visibleMenu.value) {
    for (const item of items) {
        if (!item.children?.length) continue;
        if (hasActiveChild(item)) openState.value[item.key] = true;
        expandActiveBranches(item.children);
    }
}

function loadState() {
    try { const raw = localStorage.getItem(STORAGE_KEY); if (raw) { openState.value = JSON.parse(raw) as Record<string, boolean>; hydrated = true; return; } } catch {}
    // Closed until asked. The old default opened all twelve sections, which is what turned the first
    // screen into a hundred rows of small grey text.
    openState.value = {};
    hydrated = true;
}

watch(openState, () => { if (!hydrated) return; try { localStorage.setItem(STORAGE_KEY, JSON.stringify(openState.value)); } catch {} }, { deep: true });

/** While filtering, every section that still has a match is open, or the results are hidden inside it. */
watch(sidebarSearch, (q) => {
    if (!q.trim()) return;
    const openAll = (items: SidebarItem[]) => {
        for (const item of items) if (item.children?.length) { openState.value[item.key] = true; openAll(item.children); }
    };
    openAll(visibleMenu.value);
});

function onKeydown(event: KeyboardEvent) {
    // The shortcut the placeholder always promised. Guarded on the tag so typing "k" in a form with
    // Ctrl held (or a ⌘K from a browser extension) does not steal focus mid-entry.
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        filterRef.value?.focus();
        filterRef.value?.select();
        return;
    }
    if (event.key === 'Escape' && document.activeElement === filterRef.value) {
        sidebarSearch.value = '';
        filterRef.value?.blur();
    }
}

onMounted(() => {
    loadState();
    expandActiveBranches();
    window.addEventListener('keydown', onKeydown);
});
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));
</script>

<template>
    <Sidebar collapsible="icon" class="border-sidebar-border">
        <SidebarHeader class="border-b border-sidebar-border/60 px-3 py-3.5 gap-0">
            <Link :href="route('core.dashboard')" class="group flex items-center gap-3 rounded-xl px-1 py-0.5 outline-none focus-visible:ring-2 focus-visible:ring-sidebar-ring">
                <span class="grid size-9 shrink-0 place-items-center rounded-xl bg-gradient-to-br from-hero-from via-hero-via to-hero-to text-hero-ink shadow-sm ring-1 ring-hero-ink/25">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" class="size-[18px]" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6a2 2 0 0 1 2-2h4l2 2h6a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6z"/></svg>
                </span>
                <span class="flex min-w-0 flex-col leading-tight group-data-[collapsible=icon]:hidden">
                    <span class="text-[13px] font-semibold tracking-tight text-sidebar-foreground truncate">{{ appName }}</span>
                    <span class="text-[11px] text-sidebar-foreground/60 truncate">Admin console</span>
                </span>
            </Link>

            <div class="mt-3 group-data-[collapsible=icon]:hidden">
                <div class="relative group">
                    <Search class="pointer-events-none absolute left-2.5 top-1/2 size-3.5 -translate-y-1/2 text-sidebar-foreground/40 group-focus-within:text-sidebar-foreground/70 transition-colors" />
                    <input
                        ref="filterRef"
                        v-model="sidebarSearch"
                        type="search"
                        aria-label="Filter the menu"
                        placeholder="Filter menu…"
                        class="w-full rounded-lg border border-sidebar-border bg-sidebar-accent/60 pl-8 pr-14 py-2 text-[13px] placeholder:text-sidebar-foreground/40 focus:bg-background focus:outline-none focus:ring-2 focus:ring-sidebar-ring/30 focus:border-sidebar-ring/30 transition-all"
                    />
                    <button
                        v-if="sidebarSearch"
                        type="button"
                        aria-label="Clear the menu filter"
                        class="absolute right-1.5 top-1/2 -translate-y-1/2 rounded-md p-1 text-sidebar-foreground/50 hover:bg-sidebar-accent hover:text-sidebar-foreground"
                        @click="sidebarSearch = ''"
                    ><X class="size-3" /></button>
                    <!-- The shortcut is real: onKeydown listens for Ctrl/⌘K and focuses this field. -->
                    <span v-else class="pointer-events-none absolute right-1.5 top-1/2 -translate-y-1/2 hidden sm:flex items-center gap-0.5 rounded-md border border-sidebar-border bg-background px-1.5 py-0.5 text-[10px] font-medium text-sidebar-foreground/50"><Command class="size-3" />K</span>
                </div>
            </div>
        </SidebarHeader>

        <SidebarContent class="premium-scrollbar px-2 py-3 gap-0" style="scrollbar-gutter: stable">
            <SidebarGroup class="p-0">
                <SidebarMenu class="gap-0.5">
                    <template v-for="(item, index) in visibleMenu" :key="item.key">
                        <li v-if="groupHeaderAt(index)" class="px-2 pb-1 pt-3 text-[11px] font-semibold uppercase tracking-widest text-sidebar-foreground/45 group-data-[collapsible=icon]:hidden first:pt-1">
                            {{ groupHeaderAt(index) }}
                        </li>

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
                                        <span v-if="item.children.length" class="ml-auto rounded-full bg-sidebar-accent px-1.5 text-[10px] font-medium tabular-nums text-sidebar-foreground/50 group-data-[state=open]:hidden group-data-[collapsible=icon]:hidden">{{ item.children.length }}</span>
                                        <ChevronRight class="ml-auto size-3.5 shrink-0 text-sidebar-foreground/40 transition-transform duration-200 data-[state=open]:rotate-90" />
                                    </SidebarMenuButton>
                                </CollapsibleTrigger>
                                <CollapsibleContent class="tree-content overflow-hidden">
                                    <SidebarMenuSub class="ml-3 mr-1 mt-0.5 gap-0.5 border-l border-sidebar-border/60 pl-2 pr-0 group-data-[collapsible=icon]:hidden">
                                        <SidebarMenuSubItem v-for="child in item.children" :key="child.key">
                                            <!-- A leaf: the ordinary case. -->
                                            <SidebarMenuSubButton v-if="!child.children?.length" as-child :is-active="isActive(child)" class="h-8 rounded-md text-[13px] data-[active=true]:bg-sidebar-accent data-[active=true]:font-medium">
                                                <Link :href="hrefFor(child)"><component :is="child.icon" class="size-3.5 shrink-0 opacity-60" /><span class="truncate">{{ child.label }}</span></Link>
                                            </SidebarMenuSubButton>
                                            <!-- A sub-group, e.g. Accounts › Financial Reports › Trial Balance.
                                                 Rendered as a nested collapsible, not as a link: it has no route of
                                                 its own, and linking it to '#' is what made its seven children —
                                                 including the trial balance — unreachable from the shell. -->
                                            <Collapsible v-else v-model:open="openState[child.key]" as-child>
                                                <div>
                                                    <CollapsibleTrigger as-child>
                                                        <SidebarMenuSubButton :is-active="hasActiveChild(child)" class="h-8 w-full rounded-md text-[13px] font-medium data-[active=true]:text-sidebar-accent-foreground">
                                                            <component :is="child.icon" class="size-3.5 shrink-0 opacity-60" /><span class="truncate">{{ child.label }}</span>
                                                            <ChevronRight class="ml-auto size-3.5 shrink-0 text-sidebar-foreground/40 transition-transform duration-200 data-[state=open]:rotate-90" />
                                                        </SidebarMenuSubButton>
                                                    </CollapsibleTrigger>
                                                    <CollapsibleContent class="tree-content overflow-hidden">
                                                        <SidebarMenuSub class="ml-3 mt-0.5 gap-0.5 border-l border-sidebar-border/60 pl-2 group-data-[collapsible=icon]:hidden">
                                                            <SidebarMenuSubItem v-for="leaf in child.children" :key="leaf.key">
                                                                <SidebarMenuSubButton as-child :is-active="isActive(leaf)" class="h-8 rounded-md text-[13px] data-[active=true]:bg-sidebar-accent data-[active=true]:font-medium">
                                                                    <Link :href="hrefFor(leaf)"><component :is="leaf.icon" class="size-3.5 shrink-0 opacity-60" /><span class="truncate">{{ leaf.label }}</span></Link>
                                                                </SidebarMenuSubButton>
                                                            </SidebarMenuSubItem>
                                                        </SidebarMenuSub>
                                                    </CollapsibleContent>
                                                </div>
                                            </Collapsible>
                                        </SidebarMenuSubItem>
                                    </SidebarMenuSub>
                                </CollapsibleContent>
                            </SidebarMenuItem>
                        </Collapsible>
                    </template>
                    <div v-if="!visibleMenu.length" class="px-2 py-8 text-center text-xs text-sidebar-foreground/50">
                        Nothing in the menu matches “{{ sidebarSearch }}”.
                    </div>
                </SidebarMenu>
            </SidebarGroup>
        </SidebarContent>

        <SidebarSeparator class="mx-2 opacity-60" />

        <!-- Who is signed in. On a shared terminal that is the useful thing to know; a badge saying
             the product is production-ready is not. -->
        <SidebarFooter class="p-2 gap-1">
            <div v-if="currentUser" class="group-data-[collapsible=icon]:hidden flex min-w-0 items-center gap-2 rounded-lg px-2 py-2">
                <span class="grid size-7 shrink-0 place-items-center rounded-full bg-sidebar-accent text-[11px] font-semibold text-sidebar-foreground ring-1 ring-sidebar-border">
                    {{ (currentUser.name ?? '?').slice(0, 1).toUpperCase() }}
                </span>
                <span class="flex min-w-0 flex-col leading-tight">
                    <span class="truncate text-xs font-medium text-sidebar-foreground">{{ currentUser.name }}</span>
                    <span class="truncate text-[11px] text-sidebar-foreground/55">{{ currentUser.email }}</span>
                </span>
            </div>
            <p class="group-data-[collapsible=icon]:hidden px-2 pb-1 text-[11px] text-sidebar-foreground/45">
                Only the items your role is allowed to see are listed.
            </p>
        </SidebarFooter>
        <SidebarRail />
    </Sidebar>
</template>

<style scoped>
.tree-content[data-state='open'] { animation: tree-expand 0.22s cubic-bezier(0.32,0.72,0,1); }
.tree-content[data-state='closed'] { animation: tree-collapse 0.18s cubic-bezier(0.32,0,0,1); }
@keyframes tree-expand { from { height: 0; opacity: 0.4; } to { height: var(--radix-collapsible-content-height); opacity: 1; } }
@keyframes tree-collapse { from { height: var(--radix-collapsible-content-height); opacity: 1; } to { height: 0; opacity: 0.4; } }
</style>
