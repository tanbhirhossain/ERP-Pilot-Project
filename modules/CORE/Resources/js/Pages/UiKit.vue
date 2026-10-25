<script setup lang="ts">
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Breadcrumb, BreadcrumbItem, BreadcrumbLink, BreadcrumbList, BreadcrumbPage, BreadcrumbSeparator } from '@/components/ui/breadcrumb';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle, SheetTrigger } from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { Head } from '@inertiajs/vue3';
import { Boxes, Check, ChevronDown, Copy, Layers, Palette, Search, Sparkles, Type } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import ConfirmDialog from '../Components/ConfirmDialog.vue';
import DataTable, { type DataTableColumn } from '../Components/DataTable.vue';
import SearchableSelect from '../Components/SearchableSelect.vue';
import { useToast } from '../Composables/useToast';
import { iconCategories, type IconEntry } from '../Config/iconLibrary';
import AdminLayout from '../Layouts/AdminLayout.vue';
import type { FilterDef, Paginator, SelectOption, UserRow } from '../types';
import { formatDate } from '../Utils/format';

interface Props {
    demoUsers: Paginator<UserRow>;
    roleOptions?: SelectOption[];
    filterDefs?: FilterDef[];
    environment?: { php: string; laravel: string; modules: string[] };
}

withDefaults(defineProps<Props>(), {
    roleOptions: () => [],
    filterDefs: () => [],
    environment: undefined,
});

const toast = useToast();

/* ------------------------------- Icon library ---------------------------- */

const iconQuery = ref('');
const activeCategory = ref<string | null>(null);
const copied = ref<string | null>(null);

const visibleCategories = computed(() => {
    const query = iconQuery.value.trim().toLowerCase();

    return iconCategories
        .filter((category) => !activeCategory.value || category.label === activeCategory.value)
        .map((category) => ({
            ...category,
            icons: query ? category.icons.filter((icon) => icon.name.toLowerCase().includes(query)) : category.icons,
        }))
        .filter((category) => category.icons.length > 0);
});

const totalIcons = computed(() => iconCategories.reduce((sum, category) => sum + category.icons.length, 0));
const visibleIcons = computed(() => visibleCategories.value.reduce((sum, category) => sum + category.icons.length, 0));

async function copyIcon(entry: IconEntry): Promise<void> {
    const snippet = `import { ${entry.name} } from 'lucide-vue-next';`;

    try {
        await navigator.clipboard.writeText(snippet);
    } catch {
        // Clipboard API unavailable (insecure context) — fall back to a hidden field.
        const field = document.createElement('textarea');
        field.value = snippet;
        field.style.position = 'fixed';
        field.style.opacity = '0';
        document.body.appendChild(field);
        field.select();
        document.execCommand('copy');
        field.remove();
    }

    copied.value = entry.name;
    window.setTimeout(() => {
        if (copied.value === entry.name) copied.value = null;
    }, 1600);

    toast.success(`${entry.name} copied to your clipboard.`, 'Icon copied');
}

/* ------------------------------ Component demos -------------------------- */

const checkboxOn = ref(true);
const selectValue = ref<string | number | null>(null);
const multiValue = ref<(string | number)[]>([]);
const collapsed = ref(false);
const confirmOpen = ref(false);
const skeleton = ref(true);

const demoColumns: DataTableColumn[] = [
    { key: 'name', label: 'Name', sortable: true },
    { key: 'email', label: 'Email', sortable: true },
    { key: 'roles', label: 'Roles' },
    { key: 'verified', label: 'Status', sortable: true },
    { key: 'created_at', label: 'Joined', sortable: true, width: '11rem' },
];

function fireToast(type: 'success' | 'error' | 'warning' | 'info'): void {
    const copy = {
        success: ['Your changes have been saved.', 'Saved successfully'],
        error: ['The server rejected the request (status 422).', 'Export failed'],
        warning: ['Two users could not be imported.', 'Partially completed'],
        info: ['Your export is being prepared.', 'Export started'],
    }[type];

    toast[type](copy[0], copy[1]);
}

function initials(name: unknown): string {
    return String(name ?? '')
        .split(/\s+/)
        .map((part) => part[0] ?? '')
        .slice(0, 2)
        .join('')
        .toUpperCase();
}

const statusPills = [
    { label: 'Active', class: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' },
    { label: 'Pending', class: 'bg-amber-500/10 text-amber-600 dark:text-amber-400' },
    { label: 'Blocked', class: 'bg-rose-500/10 text-rose-600 dark:text-rose-400' },
    { label: 'Draft', class: 'bg-slate-500/10 text-slate-600 dark:text-slate-400' },
    { label: 'Verified', class: 'bg-sky-500/10 text-sky-600 dark:text-sky-400' },
    { label: 'Premium', class: 'bg-violet-500/10 text-violet-600 dark:text-violet-400' },
];
</script>

<template>
    <Head title="Design System" />

    <AdminLayout title="Design System" :breadcrumbs="[{ title: 'Dashboard', href: route('core.dashboard') }, { title: 'Design System' }]">
        <!-- Hero -->
        <section class="ds-hero bg-card relative overflow-hidden rounded-2xl border p-6 shadow-sm sm:p-8">
            <div class="ds-hero-grid" aria-hidden="true" />
            <div class="relative flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
                <div class="max-w-2xl">
                    <span
                        class="border-primary/20 bg-primary/10 text-primary inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-semibold"
                    >
                        <Sparkles class="size-3.5" />
                        Living catalogue
                    </span>
                    <h1 class="mt-3 text-2xl font-bold tracking-tight sm:text-3xl">Design System</h1>
                    <p class="text-muted-foreground mt-2 text-sm leading-relaxed">
                        Every icon and component available to the CORE module, rendered live. Click any icon to copy its import statement; interact
                        with every component to see exactly how it behaves.
                    </p>
                </div>

                <dl class="grid shrink-0 grid-cols-3 gap-3 text-center">
                    <div class="bg-background/70 rounded-xl border px-4 py-3 backdrop-blur">
                        <dt class="text-muted-foreground text-[10px] font-semibold tracking-wider uppercase">Icons</dt>
                        <dd class="mt-1 text-xl font-bold tabular-nums">{{ totalIcons }}</dd>
                    </div>
                    <div class="bg-background/70 rounded-xl border px-4 py-3 backdrop-blur">
                        <dt class="text-muted-foreground text-[10px] font-semibold tracking-wider uppercase">Components</dt>
                        <dd class="mt-1 text-xl font-bold tabular-nums">24</dd>
                    </div>
                    <div class="bg-background/70 rounded-xl border px-4 py-3 backdrop-blur">
                        <dt class="text-muted-foreground text-[10px] font-semibold tracking-wider uppercase">Modules</dt>
                        <dd class="mt-1 text-xl font-bold tabular-nums">{{ environment?.modules.length ?? 1 }}</dd>
                    </div>
                </dl>
            </div>

            <Separator class="my-6" />

            <div class="text-muted-foreground relative flex flex-wrap items-center gap-x-6 gap-y-2 text-xs">
                <span class="inline-flex items-center gap-1.5">
                    <Layers class="size-3.5" />
                    Laravel {{ environment?.laravel ?? '—' }}
                </span>
                <span class="inline-flex items-center gap-1.5">
                    <Boxes class="size-3.5" />
                    PHP {{ environment?.php ?? '—' }}
                </span>
                <span class="inline-flex items-center gap-1.5">
                    <Type class="size-3.5" />
                    Tailwind CSS 4 · Vue 3 · Inertia
                </span>
            </div>
        </section>

        <!-- ============================ ICONS ============================ -->
        <Card class="scroll-mt-24" id="icons">
            <CardHeader class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <CardTitle class="flex items-center gap-2">
                        <Palette class="text-primary size-4.5" />
                        Icon library
                    </CardTitle>
                    <CardDescription> {{ visibleIcons }} of {{ totalIcons }} icons shown · click a tile to copy its import </CardDescription>
                </div>

                <div class="relative w-full sm:w-72">
                    <Search class="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                    <Input v-model="iconQuery" placeholder="Search icons…" class="h-9 rounded-lg pl-9" />
                </div>
            </CardHeader>

            <CardContent class="space-y-6">
                <!-- Category chips -->
                <div class="flex flex-wrap items-center gap-1.5">
                    <button
                        type="button"
                        class="rounded-full border px-3 py-1.5 text-xs font-medium transition"
                        :class="activeCategory === null ? 'border-primary bg-primary text-primary-foreground shadow-sm' : 'hover:bg-accent'"
                        @click="activeCategory = null"
                    >
                        All
                    </button>
                    <button
                        v-for="category in iconCategories"
                        :key="category.label"
                        type="button"
                        class="rounded-full border px-3 py-1.5 text-xs font-medium transition"
                        :class="activeCategory === category.label ? 'border-primary bg-primary text-primary-foreground shadow-sm' : 'hover:bg-accent'"
                        @click="activeCategory = category.label"
                    >
                        {{ category.label }}
                    </button>
                </div>

                <!-- Grid -->
                <div v-if="visibleCategories.length" class="space-y-6">
                    <section v-for="category in visibleCategories" :key="category.label">
                        <h3 class="text-muted-foreground mb-2.5 text-xs font-semibold tracking-[0.14em] uppercase">
                            {{ category.label }}
                        </h3>
                        <div class="grid grid-cols-3 gap-2 sm:grid-cols-5 md:grid-cols-7 lg:grid-cols-9 xl:grid-cols-12">
                            <TooltipProvider v-for="entry in category.icons" :key="entry.name" :delay-duration="200">
                                <Tooltip>
                                    <TooltipTrigger as-child>
                                        <button
                                            type="button"
                                            class="icon-tile group bg-background hover:border-primary/40 focus-visible:ring-ring relative grid aspect-square place-items-center rounded-xl border transition hover:-translate-y-0.5 hover:shadow-md focus-visible:ring-2 focus-visible:outline-none"
                                            :aria-label="`Copy ${entry.name}`"
                                            @click="copyIcon(entry)"
                                        >
                                            <Check v-if="copied === entry.name" class="size-5 text-emerald-500" />
                                            <component
                                                :is="entry.icon"
                                                v-else
                                                class="text-muted-foreground group-hover:text-foreground size-5 transition"
                                            />
                                            <Copy class="absolute top-1.5 right-1.5 size-3 opacity-0 transition group-hover:opacity-60" />
                                        </button>
                                    </TooltipTrigger>
                                    <TooltipContent side="bottom" class="font-mono text-[11px]">
                                        {{ entry.name }}
                                    </TooltipContent>
                                </Tooltip>
                            </TooltipProvider>
                        </div>
                    </section>
                </div>

                <div v-else class="grid place-items-center gap-2 rounded-xl border border-dashed py-14 text-center">
                    <Search class="text-muted-foreground/50 size-6" />
                    <p class="text-sm font-medium">No icons match “{{ iconQuery }}”</p>
                    <Button variant="ghost" size="sm" @click="iconQuery = ''">Clear search</Button>
                </div>
            </CardContent>
        </Card>

        <!-- ========================== COMPONENTS ========================= -->
        <div class="grid gap-6 xl:grid-cols-2">
            <!-- Buttons -->
            <Card>
                <CardHeader>
                    <CardTitle>Buttons</CardTitle>
                    <CardDescription>Six variants, four sizes, loading and disabled states.</CardDescription>
                </CardHeader>
                <CardContent class="ds-demo space-y-5">
                    <div class="flex flex-wrap gap-2">
                        <Button>Primary</Button>
                        <Button variant="secondary">Secondary</Button>
                        <Button variant="destructive">Destructive</Button>
                        <Button variant="outline">Outline</Button>
                        <Button variant="ghost">Ghost</Button>
                        <Button variant="link">Link</Button>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <Button size="sm">Small</Button>
                        <Button>Default</Button>
                        <Button size="lg">Large</Button>
                        <Button size="icon" aria-label="Settings">
                            <Palette class="size-4" />
                        </Button>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Button disabled>
                            <svg class="size-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="0.25" />
                                <path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
                            </svg>
                            Saving…
                        </Button>
                        <Button variant="outline" disabled>Disabled</Button>
                    </div>
                </CardContent>
            </Card>

            <!-- Status pills -->
            <Card>
                <CardHeader>
                    <CardTitle>Status pills</CardTitle>
                    <CardDescription>Tailwind tone pattern reused across every table in the kit.</CardDescription>
                </CardHeader>
                <CardContent class="ds-demo">
                    <div class="flex flex-wrap gap-2">
                        <span
                            v-for="pill in statusPills"
                            :key="pill.label"
                            class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium"
                            :class="pill.class"
                        >
                            <span class="size-1.5 rounded-full bg-current" />
                            {{ pill.label }}
                        </span>
                    </div>
                </CardContent>
            </Card>

            <!-- Inputs -->
            <Card>
                <CardHeader>
                    <CardTitle>Inputs &amp; labels</CardTitle>
                    <CardDescription>Text fields, validation tone and helper text.</CardDescription>
                </CardHeader>
                <CardContent class="ds-demo space-y-4">
                    <div class="space-y-1.5">
                        <Label for="ds-name">Full name</Label>
                        <Input id="ds-name" placeholder="Ada Lovelace" class="h-9 rounded-lg" />
                        <p class="text-muted-foreground text-xs">Appears on every audit record.</p>
                    </div>
                    <div class="space-y-1.5">
                        <Label for="ds-email">Email address</Label>
                        <Input
                            id="ds-email"
                            value="not-an-email"
                            aria-invalid="true"
                            class="h-9 rounded-lg border-rose-500 focus-visible:ring-rose-500"
                        />
                        <p class="text-xs font-medium text-rose-600 dark:text-rose-400">The email address must be a valid address.</p>
                    </div>
                    <div class="space-y-1.5">
                        <Label for="ds-disabled">Disabled</Label>
                        <Input id="ds-disabled" value="Read only" disabled class="h-9 rounded-lg" />
                    </div>
                </CardContent>
            </Card>

            <!-- Selection -->
            <Card>
                <CardHeader>
                    <CardTitle>Selection controls</CardTitle>
                    <CardDescription>Checkbox plus the module's searchable single and multi select.</CardDescription>
                </CardHeader>
                <CardContent class="ds-demo space-y-4">
                    <label class="flex cursor-pointer items-center gap-2.5 text-sm">
                        <Checkbox v-model="checkboxOn" />
                        Send me a summary every Monday
                    </label>

                    <div class="space-y-1.5">
                        <Label>Searchable select (single)</Label>
                        <SearchableSelect v-model="selectValue" :options="roleOptions" placeholder="Choose a role…" />
                    </div>

                    <div class="space-y-1.5">
                        <Label>Searchable select (multiple)</Label>
                        <SearchableSelect v-model="multiValue" :options="roleOptions" multiple badge placeholder="Choose roles…" />
                    </div>
                </CardContent>
            </Card>

            <!-- Overlays -->
            <Card>
                <CardHeader>
                    <CardTitle>Dialog, sheet &amp; menu</CardTitle>
                    <CardDescription>Modal, side panel and dropdown — all keyboard accessible.</CardDescription>
                </CardHeader>
                <CardContent class="ds-demo">
                    <div class="flex flex-wrap gap-2">
                        <Dialog>
                            <DialogTrigger as-child>
                                <Button variant="outline">Open dialog</Button>
                            </DialogTrigger>
                            <DialogContent>
                                <DialogHeader>
                                    <DialogTitle>Create a new role</DialogTitle>
                                    <DialogDescription> Roles group permissions together and can be assigned to any user. </DialogDescription>
                                </DialogHeader>
                                <div class="space-y-1.5 py-1">
                                    <Label for="ds-role">Role name</Label>
                                    <Input id="ds-role" placeholder="Finance reviewer" class="h-9 rounded-lg" />
                                </div>
                                <DialogFooter>
                                    <Button variant="outline">Cancel</Button>
                                    <Button>Create role</Button>
                                </DialogFooter>
                            </DialogContent>
                        </Dialog>

                        <Sheet>
                            <SheetTrigger as-child>
                                <Button variant="outline">Open sheet</Button>
                            </SheetTrigger>
                            <SheetContent class="w-full sm:max-w-md">
                                <SheetHeader>
                                    <SheetTitle>Filters</SheetTitle>
                                    <SheetDescription> Sheets slide in from the edge — ideal for dense filter panels. </SheetDescription>
                                </SheetHeader>
                                <div class="space-y-4 py-4">
                                    <div class="space-y-1.5">
                                        <Label for="ds-sheet">Keyword</Label>
                                        <Input id="ds-sheet" class="h-9 rounded-lg" placeholder="Search…" />
                                    </div>
                                    <div class="space-y-1.5">
                                        <Label>Role</Label>
                                        <SearchableSelect :options="roleOptions" placeholder="Any role" />
                                    </div>
                                </div>
                            </SheetContent>
                        </Sheet>

                        <DropdownMenu>
                            <DropdownMenuTrigger as-child>
                                <Button variant="outline">
                                    Options
                                    <ChevronDown class="size-4" />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent class="w-56">
                                <DropdownMenuLabel>View options</DropdownMenuLabel>
                                <DropdownMenuSeparator />
                                <DropdownMenuCheckboxItem :model-value="true">Show archived</DropdownMenuCheckboxItem>
                                <DropdownMenuCheckboxItem :model-value="false">Show system roles</DropdownMenuCheckboxItem>
                            </DropdownMenuContent>
                        </DropdownMenu>

                        <Button variant="outline" @click="confirmOpen = true">Confirm dialog</Button>
                    </div>
                </CardContent>
            </Card>

            <!-- Feedback -->
            <Card>
                <CardHeader>
                    <CardTitle>Notifications &amp; feedback</CardTitle>
                    <CardDescription>Toasts, tooltips, skeletons and collapsibles.</CardDescription>
                </CardHeader>
                <CardContent class="ds-demo space-y-5">
                    <div class="flex flex-wrap gap-2">
                        <Button size="sm" @click="fireToast('success')">Success</Button>
                        <Button size="sm" variant="destructive" @click="fireToast('error')">Error</Button>
                        <Button size="sm" variant="outline" @click="fireToast('warning')">Warning</Button>
                        <Button size="sm" variant="outline" @click="fireToast('info')">Info</Button>
                        <Button size="sm" variant="ghost" @click="skeleton = !skeleton"> {{ skeleton ? 'Hide' : 'Show' }} skeletons </Button>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <TooltipProvider :delay-duration="150">
                            <Tooltip>
                                <TooltipTrigger as-child>
                                    <Button variant="outline" size="sm">Hover me</Button>
                                </TooltipTrigger>
                                <TooltipContent>Tooltips explain without cluttering.</TooltipContent>
                            </Tooltip>
                        </TooltipProvider>
                    </div>

                    <div v-if="skeleton" class="space-y-2.5">
                        <div class="flex items-center gap-3">
                            <Skeleton class="size-9 rounded-lg" />
                            <div class="flex-1 space-y-2">
                                <Skeleton class="h-3 w-2/5" />
                                <Skeleton class="h-3 w-1/4" />
                            </div>
                        </div>
                        <Skeleton class="h-3 w-full" />
                        <Skeleton class="h-3 w-4/5" />
                    </div>

                    <Collapsible v-model:open="collapsed" class="bg-background rounded-lg border">
                        <CollapsibleTrigger class="flex w-full items-center justify-between px-3 py-2.5 text-sm font-medium">
                            Advanced settings
                            <ChevronDown class="size-4 transition-transform" :class="collapsed && 'rotate-180'" />
                        </CollapsibleTrigger>
                        <CollapsibleContent class="text-muted-foreground border-t px-3 py-3 text-sm">
                            Collapsible content animates to its measured height — used for the sidebar tree.
                        </CollapsibleContent>
                    </Collapsible>
                </CardContent>
            </Card>

            <!-- Navigation & identity -->
            <Card>
                <CardHeader>
                    <CardTitle>Navigation &amp; identity</CardTitle>
                    <CardDescription>Breadcrumbs, avatars and card anatomy.</CardDescription>
                </CardHeader>
                <CardContent class="ds-demo space-y-5">
                    <Breadcrumb>
                        <BreadcrumbList>
                            <BreadcrumbItem>
                                <BreadcrumbLink :href="route('core.dashboard')">Dashboard</BreadcrumbLink>
                            </BreadcrumbItem>
                            <BreadcrumbSeparator />
                            <BreadcrumbItem>
                                <BreadcrumbLink :href="route('core.users.index')">Users</BreadcrumbLink>
                            </BreadcrumbItem>
                            <BreadcrumbSeparator />
                            <BreadcrumbItem>
                                <BreadcrumbPage>Create</BreadcrumbPage>
                            </BreadcrumbItem>
                        </BreadcrumbList>
                    </Breadcrumb>

                    <div class="flex flex-wrap items-center gap-3">
                        <Avatar>
                            <AvatarImage src="" alt="Ada" />
                            <AvatarFallback class="bg-gradient-to-br from-indigo-500 to-violet-600 text-white"> AL </AvatarFallback>
                        </Avatar>
                        <Avatar>
                            <AvatarFallback>GB</AvatarFallback>
                        </Avatar>
                        <div class="text-sm">
                            <p class="font-medium">Grace Brewster</p>
                            <p class="text-muted-foreground text-xs">Super Admin · admin@example.com</p>
                        </div>
                    </div>

                    <Card class="bg-muted/40">
                        <CardHeader class="pb-3">
                            <CardTitle class="text-sm">Nested card</CardTitle>
                            <CardDescription class="text-xs">Cards compose — header, content, footer.</CardDescription>
                        </CardHeader>
                        <CardContent class="text-muted-foreground text-xs"> Use cards to group related controls inside a page. </CardContent>
                    </Card>
                </CardContent>
            </Card>
        </div>

        <!-- ========================= LIVE DATA TABLE ====================== -->
        <Card>
            <CardHeader>
                <CardTitle class="flex items-center gap-2">
                    <Boxes class="text-primary size-4.5" />
                    DataTable — live instance
                </CardTitle>
                <CardDescription>
                    Real paginated data through the same service/repository stack as every CRUD screen. Search, sort, filter, print and both exports
                    are all live here.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <DataTable
                    route-name="core.ui-kit"
                    export-resource="users"
                    title="Design System — sample dataset"
                    :columns="demoColumns"
                    :rows="demoUsers.data"
                    :meta="demoUsers.meta"
                    :filter-defs="filterDefs"
                    search-placeholder="Search the sample dataset…"
                    empty-title="No matching rows"
                    empty-description="Try clearing the search or filters."
                >
                    <template #cell-name="{ row }">
                        <div class="flex items-center gap-3">
                            <span
                                class="grid size-8 shrink-0 place-items-center rounded-lg bg-gradient-to-br from-indigo-500 to-violet-600 text-[11px] font-bold text-white"
                            >
                                {{ initials(row.name) }}
                            </span>
                            <span class="font-medium">{{ row.name }}</span>
                        </div>
                    </template>

                    <template #cell-email="{ row }">
                        <span class="text-muted-foreground">{{ row.email }}</span>
                    </template>

                    <template #cell-roles="{ row }">
                        <div class="flex flex-wrap gap-1.5">
                            <span
                                v-for="role in row.roles"
                                :key="role.id"
                                class="bg-primary/10 text-primary rounded-full px-2 py-0.5 text-[11px] font-medium"
                            >
                                {{ role.name }}
                            </span>
                            <span v-if="!row.roles.length" class="text-muted-foreground text-xs">—</span>
                        </div>
                    </template>

                    <template #cell-verified="{ row }">
                        <span
                            class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium"
                            :class="
                                row.verified
                                    ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'
                                    : 'bg-amber-500/10 text-amber-600 dark:text-amber-400'
                            "
                        >
                            <span class="size-1.5 rounded-full bg-current" />
                            {{ row.verified ? 'Verified' : 'Pending' }}
                        </span>
                    </template>

                    <template #cell-created_at="{ row }">
                        <span class="text-muted-foreground text-sm whitespace-nowrap">{{ formatDate(row.created_at) }}</span>
                    </template>
                </DataTable>
            </CardContent>
        </Card>

        <!-- Usage snippet -->
        <Card>
            <CardHeader>
                <CardTitle>Using these in a module page</CardTitle>
                <CardDescription>Everything is imported by name so the bundle stays tree-shaken.</CardDescription>
            </CardHeader>
            <CardContent>
                <pre class="ds-code bg-muted/50 overflow-x-auto rounded-xl border p-4 text-xs leading-relaxed"><code>&lt;script setup lang="ts"&gt;
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Users } from 'lucide-vue-next';              // icons: always by name
import DataTable from '../Components/DataTable.vue';
import AdminLayout from '../Layouts/AdminLayout.vue';
&lt;/script&gt;

&lt;template&gt;
    &lt;AdminLayout title="Reports" :breadcrumbs="[{ title: 'Reports' }]"&gt;
        &lt;Card&gt;
            &lt;CardHeader&gt;&lt;CardTitle&gt;Reports&lt;/CardTitle&gt;&lt;/CardHeader&gt;
            &lt;CardContent&gt;…&lt;/CardContent&gt;
        &lt;/Card&gt;
    &lt;/AdminLayout&gt;
&lt;/template&gt;</code></pre>
            </CardContent>
        </Card>

        <ConfirmDialog
            v-model:open="confirmOpen"
            title="Delete this component?"
            description="This is the shared danger dialog used by every destructive action in the CORE module."
            confirm-label="Delete"
            @confirm="confirmOpen = false"
        />
    </AdminLayout>
</template>

<style scoped>
/* Hero backdrop — blueprint grid with a soft corner glow. */
.ds-hero-grid {
    position: absolute;
    inset: 0;
    background-image:
        linear-gradient(to right, hsl(var(--border) / 0.55) 1px, transparent 1px),
        linear-gradient(to bottom, hsl(var(--border) / 0.55) 1px, transparent 1px);
    background-size: 28px 28px;
    mask-image: radial-gradient(ellipse 90% 70% at 15% 0%, #000 20%, transparent 75%);
}

.ds-hero {
    background-image: radial-gradient(ellipse 70% 120% at 100% 0%, hsl(var(--primary) / 0.09), transparent 60%);
}

.ds-code {
    font-family: ui-monospace, SFMono-Regular, 'SF Mono', Menlo, Consolas, monospace;
}

.icon-tile {
    transition:
        transform 0.16s ease,
        border-color 0.16s ease,
        box-shadow 0.16s ease;
}

@media (prefers-reduced-motion: reduce) {
    .icon-tile {
        transition: none;
    }

    .icon-tile:hover {
        transform: none;
    }
}

@media print {
    .ds-hero,
    .icon-tile {
        box-shadow: none !important;
    }
}
</style>
