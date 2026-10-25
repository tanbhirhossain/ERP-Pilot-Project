<script setup lang="ts">
import UserMenuContent from '@/components/UserMenuContent.vue';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Separator } from '@/components/ui/separator';
import { SidebarInset, SidebarProvider, SidebarTrigger } from '@/components/ui/sidebar';
import { useAppearance } from '@/composables/useAppearance';
import { Link, router, usePage } from '@inertiajs/vue3';
import { ChevronRight, Moon, Sun } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
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

withDefaults(defineProps<Props>(), {
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

const page = usePage<{ props: { auth: { user: AuthUser | null }; name: string } }>();
const { updateAppearance } = useAppearance();

const user = computed(() => page.props.auth.user);

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
    updateAppearance(next ? 'dark' : 'light');
    isDark.value = next;
}
</script>

<template>
    <SidebarProvider class="core-shell" :open="sidebarOpen" @update:open="onSidebarOpenChange">
        <TreeSidebar />

        <SidebarInset class="relative min-w-0">
            <header
                class="no-print bg-background/80 sticky top-0 z-30 flex h-14 shrink-0 items-center gap-3 border-b px-4 backdrop-blur-md transition-[width,height] print:hidden"
            >
                <SidebarTrigger class="-ml-1 shrink-0" />
                <Separator orientation="vertical" class="h-5 shrink-0" />

                <!-- Breadcrumbs -->
                <nav class="flex min-w-0 items-center gap-1.5 overflow-hidden" aria-label="Breadcrumb">
                    <template v-for="(crumb, index) in breadcrumbs" :key="`${crumb.title}-${index}`">
                        <ChevronRight v-if="index > 0" class="text-muted-foreground/60 size-3.5 shrink-0" aria-hidden="true" />
                        <Link
                            v-if="crumb.href && index < breadcrumbs.length - 1"
                            :href="crumb.href"
                            class="text-muted-foreground hover:text-foreground max-w-[10rem] truncate text-sm transition"
                        >
                            {{ crumb.title }}
                        </Link>
                        <span v-else class="text-foreground max-w-[14rem] truncate text-sm font-semibold" aria-current="page">
                            {{ crumb.title }}
                        </span>
                    </template>
                </nav>

                <div class="ml-auto flex items-center gap-1.5">
                    <!-- Theme toggle -->
                    <Button
                        variant="ghost"
                        size="icon"
                        class="text-muted-foreground size-9 rounded-lg"
                        :aria-label="isDark ? 'Switch to light mode' : 'Switch to dark mode'"
                        @click="toggleTheme"
                    >
                        <Sun v-if="isDark" class="size-4.5" />
                        <Moon v-else class="size-4.5" />
                    </Button>

                    <!-- Account -->
                    <DropdownMenu v-if="user">
                        <DropdownMenuTrigger as-child>
                            <button
                                type="button"
                                class="hover:border-border hover:bg-accent focus-visible:ring-ring flex items-center gap-2 rounded-lg border border-transparent px-2 py-1.5 text-sm transition focus-visible:ring-2 focus-visible:outline-none"
                            >
                                <span
                                    class="grid size-7 shrink-0 place-items-center rounded-md bg-gradient-to-br from-indigo-500 to-violet-600 text-[11px] font-bold text-white"
                                >
                                    {{
                                        user.name
                                            .split(/\s+/)
                                            .map((part) => part[0])
                                            .slice(0, 2)
                                            .join('')
                                            .toUpperCase()
                                    }}
                                </span>
                                <span class="hidden max-w-36 truncate font-medium md:inline">{{ user.name }}</span>
                                <ChevronRight class="text-muted-foreground size-3.5 rotate-90" />
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-64">
                            <UserMenuContent :user="user" />
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </header>

            <!-- Global navigation progress -->
            <div v-if="navigating" class="global-progress fixed inset-x-0 top-0 z-50 h-0.5 overflow-hidden" aria-hidden="true">
                <div class="global-progress-bar bg-primary h-full w-1/3 rounded-full" />
            </div>

            <div class="flex-1 p-4 md:p-6">
                <div class="page-enter mx-auto w-full max-w-[1500px] space-y-6">
                    <slot />
                </div>
            </div>

            <ToastStack />
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

<style>
/*
|--------------------------------------------------------------------------
| Print isolation (global on purpose)
|--------------------------------------------------------------------------
| Printing an admin screen must produce the data — not the application
| chrome. Everything outside the element marked `data-core-print` is hidden,
| and that element is lifted to the top-left of the sheet so it always
| starts on page one.
*/
@media print {
    @page {
        margin: 12mm;
        size: A4 landscape;
    }

    html,
    body {
        height: auto !important;
        overflow: visible !important;
        background: #ffffff !important;
    }

    /* Drop the sidebar subtree; keep the content column (`SidebarInset`). */
    .core-shell > *:not(main) {
        display: none !important;
    }

    /* Drop header, toasts, progress bar and anything else marked no-print. */
    .no-print,
    [data-core-toasts],
    .global-progress,
    [data-sidebar='sidebar'] {
        display: none !important;
    }

    /* Reveal only the printable region. */
    body * {
        visibility: hidden !important;
    }

    [data-core-print],
    [data-core-print] * {
        visibility: visible !important;
    }

    [data-core-print] {
        position: absolute !important;
        inset-inline-start: 0 !important;
        inset-block-start: 0 !important;
        width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
        box-shadow: none !important;
        border: 0 !important;
        background: #ffffff !important;
    }

    /* Repeat the column headers on every printed page. */
    [data-core-print] thead {
        display: table-header-group;
    }

    [data-core-print] tr {
        break-inside: avoid;
    }

    a[href]::after {
        content: '' !important;
    }
}
</style>
