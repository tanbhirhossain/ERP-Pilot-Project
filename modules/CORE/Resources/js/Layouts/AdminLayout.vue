<script setup lang="ts">
import UserMenuContent from '@/components/UserMenuContent.vue';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Separator } from '@/components/ui/separator';
import { SidebarInset, SidebarProvider, SidebarTrigger } from '@/components/ui/sidebar';
import { useAppearance } from '@/composables/useAppearance';
import { usePrintMeta } from '../Composables/usePrint';
import { useUiTheme } from '../Composables/useUiTheme';
import { Link, router, usePage } from '@inertiajs/vue3';
import { Check, ChevronRight, Moon, Palette, Search, Sun } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import CommandPalette from '../Components/CommandPalette.vue';
import LanguageSwitcher from '../Components/LanguageSwitcher.vue';
// F22: the offline till's queue signal, read from storage so CORE stays ignorant of SALES.
import OfflineBadge from '../Components/OfflineBadge.vue';
import NotificationBell from '../Components/NotificationBell.vue';
import ConfirmDialogHost from '../Components/ConfirmDialogHost.vue';
import ToastStack from '../Components/ToastStack.vue';
import type { AuthUser, Breadcrumb } from '../types';
import TreeSidebar from './TreeSidebar.vue';

/**
 * Premium admin shell for every CORE page: collapsible tree sidebar,
 * floating glass header with breadcrumbs, theme toggle, account menu and
 * the global toast stack — all owned by the module.
 */
interface Props {
    title?: string;
    breadcrumbs?: Breadcrumb[];
}

const props = withDefaults(defineProps<Props>(), {
    title: undefined,
    breadcrumbs: () => [],
});

/** localStorage key for the collapsed/expanded sidebar preference. */
const SIDEBAR_STORAGE_KEY = 'core.sidebar.collapsed.v1';

function readSidebarPreference(): boolean {
    if (typeof window === 'undefined') return true;

    try {
        return window.localStorage.getItem(SIDEBAR_STORAGE_KEY) !== '1';
    } catch {
        return true;
    }
}

const page = usePage<{ auth: { user: AuthUser | null }; name: string }>();
const { updateAppearance } = useAppearance();

/**
 * The signed-in person, for the account pill and the printed colophon. This binding was dropped when
 * the theme engine went in and the template kept referring to it: `v-if="user"` then read as undefined,
 * so the account menu and the "Printed by" line rendered nothing while every gate except the type
 * checker stayed green. It is pinned by `ShellBindingTest`.
 */
const user = computed(() => page.props.auth?.user ?? null);

/**
 * Palette and mode, owned by one engine (item 42). The toggle writes through to
 * the account rather than to localStorage alone, so the choice follows the
 * person to another machine; the switcher lists the schemes the server shared.
 */
const { mode, scheme, schemes, setScheme, setMode } = useUiTheme();

/**
 * Sheet furniture. `printMeta.at` is stamped by the print hook at print time
 * rather than at mount, so a tab left open overnight prints today's date; the
 * title falls back down the breadcrumb trail because most pages set one and
 * not all of them pass `title`.
 */
const printMeta = usePrintMeta();
const appName = computed(() => page.props.name || 'ERP');
const sheetTitle = computed(
    () => props.title ?? props.breadcrumbs[props.breadcrumbs.length - 1]?.title ?? printMeta.title ?? 'Report',
);

const isDark = ref(false);
const navigating = ref(false);

/**
 * The sidebar is driven from here rather than from the kit's internal
 * uncontrolled state: the toggle is fully deterministic *and* the choice
 * survives reloads and Inertia navigations.
 */
const sidebarOpen = ref(readSidebarPreference());

function onSidebarOpenChange(value: boolean): void {
    sidebarOpen.value = value;

    try {
        window.localStorage.setItem(SIDEBAR_STORAGE_KEY, value ? '0' : '1');
    } catch {
        /* storage unavailable (private mode) — simply not remembered */
    }
}

let offStart: (() => void) | undefined;
let offFinish: (() => void) | undefined;

onMounted(() => {
    isDark.value = document.documentElement.classList.contains('dark');

    // Global navigation progress (premium feedback for every Inertia visit —
    // sidebar links, forms, pagination alike).
    offStart = router.on('start', () => {
        navigating.value = true;
    });
    offFinish = router.on('finish', () => {
        navigating.value = false;
    });
});

onBeforeUnmount(() => {
    offStart?.();
    offFinish?.();
});

function toggleTheme() {
    const next = !isDark.value;

    setMode(next ? 'dark' : 'light');
    isDark.value = next;

    // The shim stays in the loop for the starter kit's own tabs, which read the
    // legacy key back on mount.
    updateAppearance(next ? 'dark' : 'light');
}

// A mode change from anywhere else — the settings page, the OS while the mode
// is `system` — has to be reflected in the header icon.
watch(mode, () => {
    isDark.value = document.documentElement.classList.contains('dark');
});
</script>

<template>
    <SidebarProvider class="core-shell" :open="sidebarOpen" @update:open="onSidebarOpenChange">
        <TreeSidebar />

        <SidebarInset class="relative min-w-0">
            <header
                class="no-print glass-header bg-background/70 sticky top-0 z-30 flex h-[3.5rem] shrink-0 items-center gap-3 border-b border-border/60 px-4 shadow-[0_1px_0_0_hsl(var(--border)/0.6),0_8px_24px_hsl(var(--foreground)/0.04)] transition-[width,height] print:hidden supports-[backdrop-filter]:bg-background/50"
            >
                <SidebarTrigger class="-ml-1 shrink-0 rounded-lg hover:bg-accent" />
                <Separator orientation="vertical" class="h-5 shrink-0 opacity-60" />

                <!--
                    Breadcrumb trail. Three visually distinct roles, because they mean three different
                    things: a parent you can click, a parent you cannot, and the page you are on. The
                    `v-else` used to paint *any* unlinked crumb as the current-page pill, so a trail of
                    `HRM / Employees` showed two identical bold chips both claiming aria-current="page".
                -->
                <nav class="flex min-w-0 items-center gap-0.5 overflow-hidden" aria-label="Breadcrumb">
                    <template v-for="(crumb, index) in breadcrumbs" :key="`${crumb.title}-${index}`">
                        <ChevronRight
                            v-if="index > 0"
                            class="size-3.5 shrink-0 text-muted-foreground/40"
                            aria-hidden="true"
                        />
                        <Link
                            v-if="crumb.href && index < breadcrumbs.length - 1"
                            :href="crumb.href"
                            class="max-w-[12rem] truncate rounded-md px-2 py-1 text-[13px] font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                        >
                            {{ crumb.title }}
                        </Link>
                        <span
                            v-else-if="index < breadcrumbs.length - 1"
                            class="max-w-[12rem] truncate px-2 py-1 text-[13px] font-medium text-muted-foreground/80"
                        >
                            {{ crumb.title }}
                        </span>
                        <span
                            v-else
                            class="max-w-[16rem] truncate rounded-md bg-accent px-2.5 py-1 text-[13px] font-semibold text-foreground"
                            aria-current="page"
                        >
                            {{ crumb.title }}
                        </span>
                    </template>
                    <span v-if="!breadcrumbs.length" class="px-2 py-1 text-sm font-semibold text-foreground">{{ title ?? 'Dashboard' }}</span>
                </nav>

                <div class="ml-auto flex items-center gap-1">
                    <!-- Global search — premium inset -->
                    <CommandPalette v-slot="{ open }">
                        <button type="button" class="hidden md:flex items-center gap-2 rounded-full border border-border bg-muted/50 px-3 py-1.5 text-[13px] text-muted-foreground shadow-sm hover:bg-accent hover:text-foreground transition" @click="open()">
                            <Search class="size-3.5" /> Search <span class="ml-1 hidden lg:inline-flex items-center gap-1 rounded bg-background border px-1.5 py-0 text-[11px] font-medium shadow-sm">⌘K</span>
                        </button>
                        <Button variant="ghost" size="icon" class="md:hidden size-9 rounded-full" aria-label="Search" @click="open()"><Search class="size-4" /></Button>
                    </CommandPalette>
                    <!-- Notifications — the bell inbox (Phase 1 F2) -->
                    <NotificationBell />
                    <!-- Colour scheme — the eight palettes, one click away -->
                    <DropdownMenu v-if="schemes.length">
                        <DropdownMenuTrigger as-child>
                            <Button variant="ghost" size="icon" class="size-8 rounded-full text-muted-foreground hover:bg-accent" aria-label="Colour scheme">
                                <Palette class="size-4" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-64 rounded-xl p-1.5 shadow-xl">
                            <p class="px-2 pb-1 pt-1.5 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Colour scheme</p>
                            <button
                                v-for="option in schemes"
                                :key="option.id"
                                type="button"
                                class="flex w-full items-center gap-2.5 rounded-lg px-2 py-1.5 text-left text-[13px] transition hover:bg-accent"
                                :class="option.id === scheme ? 'bg-accent font-semibold text-foreground' : 'text-muted-foreground'"
                                @click="setScheme(option.id)"
                            >
                                <span class="size-4 shrink-0 rounded-full ring-1 ring-border" :style="{ background: option.swatch }" />
                                <span class="truncate">{{ option.label }}</span>
                                <Check v-if="option.id === scheme" class="ml-auto size-3.5 shrink-0 text-primary" />
                            </button>
                            <Separator class="my-1.5" />
                            <Link :href="route('core.settings.appearance')" class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-[13px] font-medium text-muted-foreground transition hover:bg-accent hover:text-foreground">
                                <Palette class="size-3.5" /> UI Settings
                            </Link>
                        </DropdownMenuContent>
                    </DropdownMenu>
                    <!-- Offline till (F22) — what this device is still holding -->
                    <OfflineBadge />
                    <!-- Language (U19) — EN / বাংলা, RTL follows automatically -->
                    <LanguageSwitcher />
                    <!-- Theme toggle -->
                    <Button variant="ghost" size="icon" class="size-8 rounded-full text-muted-foreground hover:bg-accent" :aria-label="isDark ? 'Switch to light mode' : 'Switch to dark mode'" @click="toggleTheme">
                        <Sun v-if="isDark" class="size-4" /><Moon v-else class="size-4" />
                    </Button>
                    <Separator orientation="vertical" class="h-6 mx-1 hidden sm:block opacity-50" />

                    <!-- Account — premium pill -->
                    <DropdownMenu v-if="user">
                        <DropdownMenuTrigger as-child>
                            <button type="button" class="flex items-center gap-2 rounded-full border border-border bg-card pl-1 pr-2 py-1 text-sm shadow-sm hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none transition">
                                <span class="grid size-7 shrink-0 place-items-center rounded-full bg-gradient-to-br from-hero-from to-hero-to text-[11px] font-bold text-hero-ink ring-1 ring-hero-ink/20">{{ user.name.split(/\s+/).map((part) => part[0]).slice(0, 2).join('').toUpperCase() }}</span>
                                <span class="hidden max-w-36 truncate text-[13px] font-medium md:inline">{{ user.name }}</span>
                                <ChevronRight class="size-3.5 text-muted-foreground rotate-90" />
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-64 rounded-xl p-1 shadow-xl"><UserMenuContent :user="user" /></DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </header>

            <!-- Global navigation progress -->
            <div v-if="navigating" class="global-progress fixed inset-x-0 top-0 z-50 h-0.5 overflow-hidden" aria-hidden="true">
                <div class="global-progress-bar bg-primary h-full w-1/3 rounded-full" />
            </div>

            <div class="flex-1 p-4 md:p-6 bg-gradient-to-b from-muted/[0.35] to-transparent">
                <div class="page-enter mx-auto w-full max-w-[1500px] space-y-6">
                    <!--
                        Print furniture. Hidden on screen (`display: none` in
                        print.css until the media matches), and kept visible
                        even under region isolation so a page that prints one
                        marked element still gets a letterhead and a colophon.
                    -->
                    <div class="print-furniture" aria-hidden="true">
                        <div class="print-sheet-header">
                            <div>
                                <p class="print-sheet-org">{{ appName }}</p>
                                <p class="print-sheet-title">{{ sheetTitle }}</p>
                            </div>
                            <p class="print-sheet-meta">
                                {{ printMeta.at }}<br />
                                <template v-if="user">Printed by {{ user.name }}</template>
                            </p>
                        </div>
                    </div>

                    <slot />

                    <div class="print-furniture" aria-hidden="true">
                        <div class="print-sheet-footer">
                            <span>{{ appName }} &mdash; {{ sheetTitle }}</span>
                            <span>Page numbers come from the browser's headers-and-footers print option</span>
                        </div>
                    </div>
                </div>
            </div>

            <ToastStack />
            <ConfirmDialogHost />
        </SidebarInset>
    </SidebarProvider>
</template>

<style scoped>
/* Global navigation bar pinned to the top of the viewport */
.global-progress-bar {
    animation: global-progress 1.1s ease-in-out infinite;
    box-shadow: 0 0 8px hsl(var(--primary) / 0.6);
}

@keyframes global-progress {
    0% {
        transform: translateX(-100%);
    }
    100% {
        transform: translateX(400%);
    }
}

/* Fresh entrance on every Inertia mount */
.page-enter {
    animation: page-in 0.4s cubic-bezier(0.21, 1.02, 0.73, 1) both;
}

@keyframes page-in {
    from {
        opacity: 0;
        transform: translateY(8px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@media (prefers-reduced-motion: reduce) {
    .page-enter,
    .global-progress-bar {
        animation: none !important;
    }
}
</style>

