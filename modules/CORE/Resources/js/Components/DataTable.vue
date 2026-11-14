<script setup lang="ts" generic="T extends { id: string | number }">
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { router, usePage } from '@inertiajs/vue3';
import {
    ArrowDown,
    ArrowUp,
    ArrowUpDown,
    Bookmark,
    ChevronLeft,
    ChevronRight,
    ChevronsLeft,
    ChevronsRight,
    Download,
    FileSpreadsheet,
    FileText,
    Inbox,
    Loader2,
    Plus,
    Printer,
    Rows3,
    Search,
    Settings2,
    SlidersHorizontal,
    X,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref, watch, type Component } from 'vue';
import { useConfirm } from '../Composables/useConfirm';
import { printDocument } from '../Composables/usePrint';
import { useToast } from '../Composables/useToast';
import type { FilterDef, PaginatorMeta } from '../types';
import { formatDateTime, formatNumber } from '../Utils/format';
import SearchableSelect from './SearchableSelect.vue';

/**
 * Server-side DataTable built for tables of any size (100 → billions of
 * rows): the browser only ever holds the current page. URL is the single
 * source of truth — search, sort, filters, page and per-page all live in the
 * query string, so views are shareable and the back button behaves.
 */
export interface DataTableColumn {
    key: string;
    label: string;
    sortable?: boolean;
    align?: 'left' | 'center' | 'right';
    width?: string;
    /** Hidden from both print and exports when false. */
    exportable?: boolean;
    /** Render-only visibility toggle starts hidden. */
    defaultHidden?: boolean;
    headerClass?: string;
    cellClass?: string;
}

/** A bulk action offered when rows are selected (Phase 2 — U7). The parent
 *  handles the `bulk` event with the chosen key + the selected row ids. */
export interface BulkAction {
    key: string;
    label: string;
    icon?: Component;
    danger?: boolean;
}

/** A persisted table view (Phase 2 — U8). */
interface SavedView {
    id: number;
    name: string;
    state: { query?: Record<string, string>; hidden?: string[] };
}

interface Props {
    columns: DataTableColumn[];
    /** Row type is inferred from the caller, so cell slots receive a typed `row` (U18). */
    rows: T[];
    meta: PaginatorMeta;
    /** Ziggy name of the index route, e.g. 'core.users.index'. */
    routeName: string;
    routeParams?: Record<string, unknown>;
    /** Enables exports when set, e.g. 'users'. */
    exportResource?: string;
    /** Human title used by PDF export + print header. */
    title?: string;
    filterDefs?: FilterDef[];
    searchPlaceholder?: string;
    defaultSort?: { key: string; direction: 'asc' | 'desc' };
    emptyTitle?: string;
    emptyDescription?: string;
    /** Show a selection checkbox column + bulk-action bar (Phase 2 — U7). */
    selectable?: boolean;
    /** Bulk actions offered when rows are selected; parent handles `bulk`. */
    bulkActions?: BulkAction[];
}

const props = withDefaults(defineProps<Props>(), {
    exportResource: undefined,
    routeParams: undefined,
    title: 'Records',
    filterDefs: () => [],
    searchPlaceholder: 'Search…',
    defaultSort: () => ({ key: 'created_at', direction: 'desc' }),
    emptyTitle: 'No records found',
    emptyDescription: 'Try adjusting your search or filters to widen the results.',
    selectable: false,
    bulkActions: () => [],
});

const emit = defineEmits<{
    (e: 'bulk', action: string, ids: Array<string | number>): void;
}>();

const page = usePage();
const toast = useToast();
const { prompt } = useConfirm();
const appName = computed(() => (page.props.name as string | undefined) ?? 'Admin Console');
const loading = ref(false);
const exporting = ref(false);
const searchInput = ref('');
let searchTimer: number | undefined;

/* --------------------------------- URL ---------------------------------- */

const urlParams = computed(() => new URL(page.url, 'http://core.local').searchParams);

const filterValue = (key: string): string => urlParams.value.get(`filters[${key}]`) ?? '';

const activeFilters = computed<Record<string, string>>(() => {
    const result: Record<string, string> = {};

    for (const def of props.filterDefs) {
        const value = filterValue(def.key);
        if (value !== '') result[def.key] = value;
    }

    return result;
});

const hasActiveFilters = computed(() => Object.keys(activeFilters.value).length > 0 || (searchInput.value ?? '') !== '');

const hasSearch = computed(() => (urlParams.value.get('search') ?? '') !== '');

/**
 * Navigate while merging a flat patch of query params into the current URL.
 * Keys not mentioned are preserved; `page` resets unless explicitly patched.
 */
function navigate(patch: Record<string, string | number | null | undefined>, push = false) {
    const params = new URLSearchParams(urlParams.value);

    for (const [key, value] of Object.entries(patch)) {
        if (value === null || value === undefined || value === '') {
            params.delete(key);
        } else {
            params.set(key, String(value));
        }
    }

    if (!('page' in patch)) {
        params.delete('page');
    }

    loading.value = true;

    const query = Object.fromEntries(params.entries());

    router.get((route as any)(props.routeName, props.routeParams ?? {}), query, {
        preserveState: true,
        preserveScroll: true,
        replace: !push,
        onFinish: () => {
            loading.value = false;
        },
    });
}

/* ------------------------------- live search ----------------------------- */

function onSearchInputEvent(event: Event) {
    onSearchInput((event.target as HTMLInputElement).value);
}

function onSearchInput(value: string) {
    window.clearTimeout(searchTimer);

    searchTimer = window.setTimeout(() => navigate({ search: value.trim() || null }), 400);
}

watch(
    () => page.url,
    () => {
        const incoming = urlParams.value.get('search') ?? '';

        if (incoming !== searchInput.value.trim()) {
            searchInput.value = incoming;
        }
    },
    { immediate: true },
);

/* -------------------------------- sorting -------------------------------- */

function sortState(key: string): 'asc' | 'desc' | null {
    const urlSort = urlParams.value.get('sort');

    if (urlSort) {
        return urlSort === key ? (urlParams.value.get('dir') === 'asc' ? 'asc' : 'desc') : null;
    }

    // No explicit sort in the URL — surface the default order on its column.
    return props.defaultSort.key === key ? props.defaultSort.direction : null;
}

const activeSortKey = computed(() => urlParams.value.get('sort') ?? props.defaultSort.key);

function toggleSort(column: DataTableColumn) {
    if (!column.sortable) return;

    const urlSort = urlParams.value.get('sort');

    if (!urlSort || urlSort !== column.key) {
        navigate({ sort: column.key, dir: 'asc' });
        return;
    }

    const direction = urlParams.value.get('dir') ?? 'desc';

    if (direction === 'asc') {
        navigate({ sort: column.key, dir: 'desc' });
    } else {
        // back to default order
        navigate({ sort: null, dir: null });
    }
}

/* -------------------------------- filters -------------------------------- */

function onFilterChange(def: FilterDef, value: unknown) {
    const normalized = value === null || value === undefined || value === '' ? null : String(value);
    applyFilter(def, normalized);
}

function applyFilter(def: FilterDef, value: string | null) {
    navigate({ [`filters[${def.key}]`]: value === null || value === '' ? null : String(value) });
}

function clearAll() {
    const params = new URLSearchParams(urlParams.value);
    const patch: Record<string, string | null> = { search: null };

    for (const key of [...params.keys()]) {
        if (key.startsWith('filters[') || key === 'sort' || key === 'dir') {
            patch[key] = null;
        }
    }

    searchInput.value = '';
    navigate(patch);
}

/* ------------------------------ column visibility ------------------------ */

const hiddenColumns = ref<Set<string>>(new Set(props.columns.filter((column) => column.defaultHidden).map((c) => c.key)));

function toggleColumn(key: string) {
    const next = new Set(hiddenColumns.value);

    if (next.has(key)) {
        next.delete(key);
    } else {
        // never hide the last visible column
        if (next.size >= props.columns.length - 1) return;
        next.add(key);
    }

    hiddenColumns.value = next;
}

const visibleColumns = computed(() => props.columns.filter((column) => !hiddenColumns.value.has(column.key)));

/* -------------------------------- exports -------------------------------- */

const exportHint = 'Respects the current search, filters and sort';

/**
 * Start a file download for the current view.
 *
 * Hardened for the real world: the query string is built natively so
 * bracketed filter keys can never break route generation, a literal URL
 * covers a missing ziggy route, and `finally` guarantees the button always
 * re-arms — a failed attempt can never leave the export stuck/disabled.
 */
/** Turns a failed export response into a message a human can act on. */
async function describeFailure(response: Response): Promise<string> {
    try {
        const text = await response.text();
        const titled = text.match(/<title>([^<]*)<\/title>/i);

        if (titled?.[1]) return titled[1].trim();
        if (text.trim())
            return text
                .replace(/<[^>]+>/g, ' ')
                .replace(/\s+/g, ' ')
                .trim()
                .slice(0, 200);
    } catch {
        /* body unreadable — fall through to the status code */
    }

    return `The server responded with status ${response.status}.`;
}

/** Prefers the server filename, falls back to a sensible one. */
function filenameFrom(disposition: string | null, format: 'excel' | 'pdf'): string {
    if (disposition) {
        const utf8 = disposition.match(/filename\*=UTF-8''([^;]+)/i);
        if (utf8?.[1]) return decodeURIComponent(utf8[1].trim());

        const plain = disposition.match(/filename="?([^";]+)"?/i);
        if (plain?.[1]) return plain[1].trim();
    }

    const ext = format === 'excel' ? 'xlsx' : 'pdf';

    return `${props.exportResource ?? 'export'}-${new Date().toISOString().slice(0, 10)}.${ext}`;
}

/** Anchors a blob to a temporary link — the page itself never navigates. */
function downloadBlob(blob: Blob, filename: string): void {
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');

    link.href = url;
    link.download = filename;
    link.rel = 'noopener';

    document.body.appendChild(link);
    link.click();
    link.remove();

    window.setTimeout(() => URL.revokeObjectURL(url), 5000);
}

async function runExport(format: 'excel' | 'pdf') {
    if (!props.exportResource || exporting.value) return;

    exporting.value = true;

    try {
        let base = `/core/exports/${props.exportResource}/${format}`;

        try {
            base = route('core.exports', { resource: props.exportResource, format });
        } catch {
            /* route table unavailable — the literal path above is identical */
        }

        const query = new URLSearchParams();
        urlParams.value.forEach((value, key) => {
            if (value !== '') query.set(key, value);
        });

        const target = query.toString() ? `${base}?${query.toString()}` : base;

        const response = await fetch(target, {
            credentials: 'same-origin',
            headers: { Accept: 'application/octet-stream, application/pdf, */*' },
        });

        if (!response.ok) throw new Error(await describeFailure(response));

        const blob = await response.blob();

        if (!blob.size) throw new Error('The server returned an empty file.');

        downloadBlob(blob, filenameFrom(response.headers.get('Content-Disposition'), format));

        toast.success(`Your ${format === 'excel' ? 'Excel workbook' : 'PDF report'} is ready — check your downloads folder.`, 'Export complete');
    } catch (error) {
        const detail = error instanceof Error ? error.message : 'Unexpected error.';
        console.error('[CORE] export failed', error);
        toast.error(detail, 'Export failed');
    } finally {
        // Re-armed on every path: the button can never stay disabled.
        exporting.value = false;
    }
}

onBeforeUnmount(() => {
    window.clearTimeout(searchTimer);
});

function printView() {
    // Item 43: through the engine rather than a bare `window.print()`. The global hook installed at
    // boot would have made the bare call *work* — that is the point of the hook, and it is why this
    // was not caught earlier — but it would have printed before the web fonts settled and without
    // stamping the sheet furniture. This is also the component that marks its own print region.
    printDocument();
}

/* ------------------------------- pagination ------------------------------ */

const perPageOptions = [10, 25, 50, 100];

const pageWindow = computed<(number | 'gap-start' | 'gap-end')[]>(() => {
    const { current_page: current, last_page: last } = props.meta;

    if (last <= 7) return Array.from({ length: last }, (_, index) => index + 1);

    if (current <= 4) return [1, 2, 3, 4, 5, 6, 'gap-end', last];

    if (current >= last - 4) return [1, 'gap-start', last - 5, last - 4, last - 3, last - 2, last - 1, last];

    return [1, 'gap-start', current - 1, current, current + 1, 'gap-end', last];
});

/* -------------------------------- helpers -------------------------------- */

const showFilters = computed(() => props.filterDefs.length > 0);

/** Staggered entrance for rows — re-triggered per page via the row key. */
function rowStyle(index: number): Record<string, string> {
    return { '--dt-row-delay': `${Math.min(index * 16, 240)}ms` };
}

/** Raw field access for the cell slot's `value` binding (rows are generic). */
function rawValue(row: T, key: string): unknown {
    return (row as Record<string, unknown>)[key];
}

function cellValue(row: T, key: string): unknown {
    const value = rawValue(row, key);

    if (value === null || value === undefined || value === '') return '—';
    if (typeof value === 'boolean') return value ? 'Yes' : 'No';
    if (typeof value === 'string' && /^\d{4}-\d{2}-\d{2}T/.test(value)) return formatDateTime(value);
    if (Array.isArray(value)) return value.join(', ');
    if (typeof value === 'object') return JSON.stringify(value);

    return value;
}

/* --------------------- selection & bulk actions (U7) --------------------- */

const selected = ref<Set<string | number>>(new Set());

const rowId = (row: T): string | number => row.id;

const allSelected = computed(() => props.rows.length > 0 && props.rows.every((r) => selected.value.has(rowId(r))));
const someSelected = computed(() => props.rows.some((r) => selected.value.has(rowId(r))) && !allSelected.value);

function toggleRow(id: string | number): void {
    const next = new Set(selected.value);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    selected.value = next;
}

function toggleAll(): void {
    selected.value = allSelected.value ? new Set() : new Set(props.rows.map(rowId));
}

function clearSelection(): void {
    selected.value = new Set();
}

function runBulk(action: BulkAction): void {
    if (selected.value.size === 0) return;
    emit('bulk', action.key, [...selected.value]);
}

// Selection is per-page: navigating or filtering clears it so a bulk action can
// never silently include rows the operator can no longer see.
watch(
    () => props.rows,
    () => clearSelection(),
);

/* --------------------------- density (U8) -------------------------------- */

const densityKey = `dt-density-${props.routeName}`;
const density = ref<'comfortable' | 'compact'>('comfortable');

function setDensity(value: 'comfortable' | 'compact'): void {
    density.value = value;
    try {
        localStorage.setItem(densityKey, value);
    } catch {
        /* private mode — density just won't persist */
    }
}

const cellPad = computed(() => (density.value === 'compact' ? 'px-3 py-1.5' : 'px-4 py-3'));

/* ------------------------- saved views (U8) ------------------------------ */

const views = ref<SavedView[]>([]);
const savingView = ref(false);

async function loadViews(): Promise<void> {
    try {
        const res = await fetch(route('core.saved-views.index', { tableKey: props.routeName }), { headers: { Accept: 'application/json' } });
        if (res.ok) views.value = (await res.json()).views ?? [];
    } catch {
        /* views are a convenience — never block the table */
    }
}

async function saveCurrentView(): Promise<void> {
    const name = await prompt({ title: 'Save this view', inputLabel: 'View name', inputPlaceholder: 'e.g. Overdue, compact', required: true });
    if (!name) return;

    const query: Record<string, string> = {};
    urlParams.value.forEach((value, key) => {
        if (value !== '' && key !== 'page') query[key] = value;
    });

    savingView.value = true;
    try {
        const res = await fetch(route('core.saved-views.store', { tableKey: props.routeName }), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken() },
            body: JSON.stringify({ name, state: { query, hidden: [...hiddenColumns.value] } }),
        });
        if (res.ok) {
            await loadViews();
            toast.success(`View "${name}" saved.`);
        } else {
            toast.error('Could not save the view.');
        }
    } catch {
        toast.error('Could not save the view.');
    } finally {
        savingView.value = false;
    }
}

function applyView(view: SavedView): void {
    hiddenColumns.value = new Set(view.state.hidden ?? []);
    loading.value = true;
    router.get((route as any)(props.routeName, props.routeParams ?? {}), view.state.query ?? {}, {
        preserveState: true,
        preserveScroll: true,
        onFinish: () => {
            loading.value = false;
        },
    });
}

async function deleteView(view: SavedView): Promise<void> {
    try {
        await fetch(route('core.saved-views.destroy', { tableKey: props.routeName, id: view.id }), {
            method: 'DELETE',
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken() },
        });
        await loadViews();
    } catch {
        /* ignore */
    }
}

function csrfToken(): string {
    return (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null)?.content ?? '';
}

/* --------------------------- skeletons (U13) ----------------------------- */

const skeletonCount = computed(() => Math.min(props.meta.per_page || 10, 10));
const showSkeleton = computed(() => loading.value && props.rows.length === 0);

onMounted(() => {
    try {
        const stored = localStorage.getItem(densityKey);
        if (stored === 'compact' || stored === 'comfortable') density.value = stored;
    } catch {
        /* ignore */
    }
    void loadViews();
});
</script>

<template>
    <div class="relative space-y-3" data-core-print>
        <!-- Print-only sheet header (hidden on screen) -->
        <div class="mb-5 hidden print:block">
            <div class="flex items-end justify-between gap-6 border-b-2 border-gray-900 pb-3">
                <div class="min-w-0">
                    <p class="text-[10px] font-semibold tracking-[0.22em] text-gray-500 uppercase">
                        {{ appName }}
                    </p>
                    <h1 class="mt-1 truncate text-2xl leading-tight font-bold text-gray-900">{{ title }}</h1>
                </div>
                <div class="shrink-0 text-right text-[10px] leading-relaxed text-gray-600">
                    <p>Generated {{ formatDateTime(new Date().toISOString()) }}</p>
                    <p>{{ formatNumber(meta.total) }} records · page {{ meta.current_page }} of {{ meta.last_page }}</p>
                </div>
            </div>
        </div>

        <!-- Toolbar -->
        <div class="bg-card flex flex-col gap-3 rounded-xl border p-3 shadow-sm lg:flex-row lg:items-center lg:justify-between print:hidden">
            <div class="flex flex-1 flex-wrap items-center gap-2">
                <div class="relative w-full max-w-sm">
                    <Search class="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                    <Input
                        v-model="searchInput"
                        type="search"
                        :placeholder="searchPlaceholder"
                        class="h-9 w-full rounded-lg pr-9 pl-9 shadow-none"
                        aria-label="Live search"
                        @input="onSearchInputEvent($event)"
                    />
                    <button
                        v-if="searchInput"
                        type="button"
                        class="text-muted-foreground hover:text-foreground focus-visible:ring-ring absolute top-1/2 right-2.5 -translate-y-1/2 rounded-md p-0.5 transition focus-visible:ring-2 focus-visible:outline-none"
                        aria-label="Clear search"
                        @click="
                            searchInput = '';
                            onSearchInput('');
                        "
                    >
                        <X class="size-3.5" />
                    </button>
                </div>

                <!-- Filters -->
                <template v-if="showFilters">
                    <div v-for="def in filterDefs" :key="def.key" class="w-full sm:w-48">
                        <Input
                            v-if="def.kind === 'date'"
                            type="date"
                            :value="filterValue(def.key)"
                            class="h-9 w-full rounded-lg"
                            :aria-label="def.label"
                            @update:model-value="onFilterChange(def, $event)"
                        />
                        <SearchableSelect
                            v-else
                            :model-value="filterValue(def.key) || null"
                            :options="def.options ?? []"
                            :endpoint="def.kind === 'endpoint' ? def.endpoint : undefined"
                            :placeholder="def.label + (def.kind === 'select' ? ': any' : '')"
                            :search-placeholder="def.placeholder ?? `Search ${def.label.toLowerCase()}…`"
                            clearable
                            badge
                            class="h-9"
                            @update:model-value="onFilterChange(def, $event)"
                        />
                    </div>
                </template>

                <Button
                    v-if="hasActiveFilters || urlParams.get('sort')"
                    variant="ghost"
                    size="sm"
                    class="text-muted-foreground hover:text-foreground h-9 rounded-lg"
                    @click="clearAll"
                >
                    <X class="size-4" />
                    Clear
                </Button>
            </div>

            <div class="flex items-center gap-2">
                <!-- Saved views (U8) -->
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <Button variant="outline" size="sm" class="h-9 rounded-lg" title="Saved views">
                            <Bookmark class="size-4" />
                            <span class="hidden sm:inline">Views</span>
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-60 p-1.5">
                        <DropdownMenuLabel class="text-muted-foreground text-xs">Saved views</DropdownMenuLabel>
                        <DropdownMenuSeparator />
                        <div v-if="views.length === 0" class="text-muted-foreground px-2.5 py-2 text-xs">No saved views yet.</div>
                        <div v-for="view in views" :key="view.id" class="group flex items-center gap-1">
                            <DropdownMenuItem class="dt-menu-item min-w-0 flex-1 cursor-pointer rounded-lg px-2.5 py-2" @select="applyView(view)">
                                <span class="truncate">{{ view.name }}</span>
                            </DropdownMenuItem>
                            <button
                                type="button"
                                class="text-muted-foreground hover:text-destructive shrink-0 rounded p-1 opacity-0 transition group-hover:opacity-100"
                                :aria-label="`Delete view ${view.name}`"
                                @click.stop="deleteView(view)"
                            >
                                <X class="size-3.5" />
                            </button>
                        </div>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem class="dt-menu-item cursor-pointer gap-2 rounded-lg px-2.5 py-2" :disabled="savingView" @select="saveCurrentView">
                            <Plus class="size-4" /> Save current view…
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>

                <!-- Density (U8) -->
                <Button
                    variant="outline"
                    size="sm"
                    class="h-9 rounded-lg"
                    :title="density === 'compact' ? 'Switch to comfortable rows' : 'Switch to compact rows'"
                    @click="setDensity(density === 'compact' ? 'comfortable' : 'compact')"
                >
                    <Rows3 class="size-4" />
                    <span class="hidden sm:inline">{{ density === 'compact' ? 'Compact' : 'Comfortable' }}</span>
                </Button>

                <!-- Column visibility -->
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <Button variant="outline" size="sm" class="h-9 rounded-lg" title="Toggle columns">
                            <Settings2 class="size-4" />
                            <span class="hidden sm:inline">Columns</span>
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-52">
                        <DropdownMenuLabel class="text-muted-foreground text-xs">Visible columns</DropdownMenuLabel>
                        <DropdownMenuSeparator />
                        <DropdownMenuCheckboxItem
                            v-for="column in columns"
                            :key="column.key"
                            :checked="!hiddenColumns.has(column.key)"
                            @select.prevent="toggleColumn(column.key)"
                        >
                            {{ column.label }}
                        </DropdownMenuCheckboxItem>
                    </DropdownMenuContent>
                </DropdownMenu>

                <!-- Exports -->
                <DropdownMenu v-if="exportResource">
                    <DropdownMenuTrigger as-child>
                        <Button variant="outline" size="sm" class="h-9 rounded-lg" :disabled="exporting">
                            <Loader2 v-if="exporting" class="size-4 animate-spin" />
                            <Download v-else class="size-4" />
                            <span class="hidden sm:inline">{{ exporting ? 'Exporting…' : 'Export' }}</span>
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-68 p-1.5">
                        <div class="px-2.5 py-2">
                            <p class="text-muted-foreground text-[11px] font-semibold tracking-wider uppercase">Export current view</p>
                            <p class="text-muted-foreground/80 mt-0.5 text-xs leading-snug">{{ exportHint }}</p>
                        </div>
                        <DropdownMenuSeparator class="my-1.5" />
                        <DropdownMenuItem class="dt-menu-item cursor-pointer gap-3 rounded-lg px-2.5 py-2.5" @select="runExport('excel')">
                            <span class="grid size-8 shrink-0 place-items-center rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">
                                <FileSpreadsheet class="size-4" />
                            </span>
                            <span class="flex min-w-0 flex-col leading-tight">
                                <span class="font-medium">Export to Excel</span>
                                <span class="text-muted-foreground text-xs">.xlsx · full fidelity</span>
                            </span>
                        </DropdownMenuItem>
                        <DropdownMenuItem class="dt-menu-item cursor-pointer gap-3 rounded-lg px-2.5 py-2.5" @select="runExport('pdf')">
                            <span class="grid size-8 shrink-0 place-items-center rounded-lg bg-rose-500/10 text-rose-600 dark:text-rose-400">
                                <FileText class="size-4" />
                            </span>
                            <span class="flex min-w-0 flex-col leading-tight">
                                <span class="font-medium">Export to PDF</span>
                                <span class="text-muted-foreground text-xs">.pdf · print ready</span>
                            </span>
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>

                <Button variant="outline" size="sm" class="h-9 rounded-lg" @click="printView">
                    <Printer class="size-4" />
                    <span class="hidden sm:inline">Print</span>
                </Button>
            </div>
        </div>

        <!-- Active filter chips -->
        <div v-if="Object.keys(activeFilters).length > 0 || hasSearch" class="flex flex-wrap items-center gap-2 print:hidden">
            <span
                class="border-primary/25 bg-primary/5 text-primary inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[11px] font-semibold tracking-wider uppercase"
            >
                <SlidersHorizontal class="size-3" />
                Filtered
            </span>
            <span v-if="hasSearch" class="bg-card inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-medium shadow-sm">
                <span class="text-muted-foreground">Search:</span>
                “{{ urlParams.get('search') }}”
                <button
                    type="button"
                    class="text-muted-foreground hover:text-foreground focus-visible:ring-ring rounded-full p-0.5 transition focus-visible:ring-2 focus-visible:outline-none"
                    aria-label="Clear search filter"
                    @click="
                        searchInput = '';
                        onSearchInput('');
                    "
                >
                    <X class="size-3" />
                </button>
            </span>
            <span
                v-for="(value, key) in activeFilters"
                :key="key"
                class="bg-card inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-medium shadow-sm"
            >
                <span class="text-muted-foreground">{{ filterDefs.find((def) => def.key === key)?.label ?? key }}:</span>
                {{ value }}
                <button
                    type="button"
                    class="text-muted-foreground hover:text-foreground focus-visible:ring-ring rounded-full p-0.5 transition focus-visible:ring-2 focus-visible:outline-none"
                    :aria-label="`Remove ${key} filter`"
                    @click="applyFilter({ key, label: key, kind: 'select' }, null)"
                >
                    <X class="size-3" />
                </button>
            </span>
            <button
                type="button"
                class="text-muted-foreground hover:text-foreground focus-visible:ring-ring rounded-full px-2 py-1 text-xs font-medium transition focus-visible:ring-2 focus-visible:outline-none"
                @click="clearAll"
            >
                Clear all
            </button>
        </div>

        <!-- Bulk-action bar (U7) -->
        <div
            v-if="selectable && selected.size > 0"
            class="bg-primary/5 border-primary/30 flex flex-wrap items-center gap-2 rounded-xl border px-3 py-2 shadow-sm print:hidden"
        >
            <span class="text-sm font-semibold">{{ selected.size }} selected</span>
            <span class="bg-border mx-1 hidden h-5 w-px sm:block" />
            <Button
                v-for="action in bulkActions"
                :key="action.key"
                size="sm"
                :variant="action.danger ? 'outline' : 'default'"
                class="h-8 rounded-lg"
                :class="action.danger && 'text-destructive hover:bg-destructive/10'"
                @click="runBulk(action)"
            >
                <component :is="action.icon" v-if="action.icon" class="size-4" />
                {{ action.label }}
            </Button>
            <Button variant="ghost" size="sm" class="text-muted-foreground ml-auto h-8 rounded-lg" @click="clearSelection">
                <X class="size-4" /> Clear
            </Button>
        </div>

        <!-- Table card -->
        <div class="bg-card relative overflow-hidden rounded-xl border shadow-sm">
            <!-- indeterminate progress -->
            <div v-if="loading" class="bg-muted absolute inset-x-0 top-0 z-20 h-0.5 overflow-hidden">
                <div class="dt-progress bg-primary h-full w-1/3 rounded-full" />
            </div>

            <div
                class="dt-scroll max-h-[72vh] overflow-auto print:max-h-none print:overflow-visible"
                :class="loading && 'pointer-events-none opacity-70 transition-opacity'"
            >
                <table class="w-full caption-bottom text-sm">
                    <thead>
                        <tr class="border-b">
                            <th v-if="selectable" scope="col" class="dt-th bg-card sticky top-0 z-[1] w-10 px-3 py-3 print:hidden">
                                <input
                                    type="checkbox"
                                    class="size-4 cursor-pointer rounded border accent-[hsl(var(--primary))]"
                                    :checked="allSelected"
                                    :indeterminate="someSelected"
                                    aria-label="Select all rows on this page"
                                    @change="toggleAll"
                                />
                            </th>
                            <th
                                v-for="column in visibleColumns"
                                :key="column.key"
                                scope="col"
                                :style="column.width ? { width: column.width } : undefined"
                                :class="
                                    cn(
                                        'dt-th text-muted-foreground sticky top-0 z-[1] px-4 py-3 text-xs font-semibold tracking-wider uppercase',
                                        column.align === 'right' && 'text-right',
                                        column.align === 'center' && 'text-center',
                                        column.key === 'actions' && 'w-1 print:hidden',
                                        column.sortable && 'select-none',
                                        column.headerClass,
                                    )
                                "
                            >
                                <button
                                    v-if="column.sortable"
                                    type="button"
                                    class="group hover:text-foreground focus-visible:ring-ring inline-flex items-center gap-1.5 rounded transition focus-visible:ring-2 focus-visible:outline-none"
                                    :class="sortState(column.key) && 'text-foreground'"
                                    :aria-label="`Sort by ${column.label}`"
                                    @click="toggleSort(column)"
                                >
                                    {{ column.label }}
                                    <ArrowUp v-if="sortState(column.key) === 'asc'" class="text-primary size-3.5" />
                                    <ArrowDown v-else-if="sortState(column.key) === 'desc'" class="text-primary size-3.5" />
                                    <ArrowUpDown v-else class="text-muted-foreground/40 group-hover:text-muted-foreground size-3 transition" />
                                </button>
                                <template v-else>{{ column.label }}</template>
                            </th>
                        </tr>
                    </thead>

                    <tbody class="divide-border/70 divide-y">
                        <tr
                            v-for="(row, rowIndex) in rows"
                            :key="`${meta.current_page}-${row.id}`"
                            class="dt-row group hover:bg-accent/50 transition-colors"
                            :class="selectable && selected.has(rowId(row)) && 'bg-primary/[0.06]'"
                            :style="rowStyle(rowIndex)"
                        >
                            <td v-if="selectable" class="px-3 align-middle print:hidden" :class="cellPad">
                                <input
                                    type="checkbox"
                                    class="size-4 cursor-pointer rounded border accent-[hsl(var(--primary))]"
                                    :checked="selected.has(rowId(row))"
                                    :aria-label="`Select row ${row.id}`"
                                    @change="toggleRow(rowId(row))"
                                />
                            </td>
                            <td
                                v-for="column in visibleColumns"
                                :key="column.key"
                                :class="
                                    cn(
                                        'text-foreground/90 align-middle text-sm',
                                        cellPad,
                                        column.align === 'right' && 'text-right tabular-nums',
                                        column.align === 'center' && 'text-center',
                                        column.key === 'actions' && 'w-1 print:hidden',
                                        activeSortKey && sortState(column.key) && 'bg-primary/[0.035]',
                                        column.cellClass,
                                    )
                                "
                            >
                                <slot :name="`cell-${column.key}`" :row="row" :value="rawValue(row, column.key)" :index="rowIndex">
                                    <span :class="column.key === 'created_at' ? 'text-muted-foreground whitespace-nowrap' : ''">
                                        {{ cellValue(row, column.key) }}
                                    </span>
                                </slot>
                            </td>
                        </tr>

                        <!-- Skeleton rows on first load (U13) -->
                        <tr v-for="n in showSkeleton ? skeletonCount : 0" :key="`skel-${n}`" class="dt-row">
                            <td v-if="selectable" class="px-3" :class="cellPad">
                                <div class="bg-muted size-4 animate-pulse rounded" />
                            </td>
                            <td v-for="column in visibleColumns" :key="column.key" :class="cellPad">
                                <div class="bg-muted h-4 animate-pulse rounded" :style="{ width: `${55 + ((n * 13 + column.key.length * 7) % 35)}%` }" />
                            </td>
                        </tr>

                        <!-- Empty state -->
                        <tr v-if="rows.length === 0 && !showSkeleton">
                            <td :colspan="visibleColumns.length + (selectable ? 1 : 0)" class="px-4 py-16">
                                <div class="mx-auto flex max-w-sm flex-col items-center text-center">
                                    <div
                                        class="from-muted/80 grid size-16 place-items-center rounded-2xl border border-dashed bg-gradient-to-b to-transparent"
                                    >
                                        <Inbox class="text-muted-foreground/70 size-7" />
                                    </div>
                                    <h3 class="mt-4 text-sm font-semibold">{{ emptyTitle }}</h3>
                                    <p class="text-muted-foreground mt-1 text-sm leading-relaxed">{{ emptyDescription }}</p>
                                    <Button v-if="hasActiveFilters" variant="outline" size="sm" class="mt-4 rounded-lg" @click="clearAll">
                                        Clear search & filters
                                    </Button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Footer -->
            <div v-if="meta.total > 0" class="flex flex-col gap-3 border-t px-4 py-3 sm:flex-row sm:items-center sm:justify-between print:hidden">
                <div class="text-muted-foreground flex items-center gap-4 text-sm">
                    <span>
                        Showing <strong class="text-foreground font-medium">{{ formatNumber(meta.from) }}</strong>
                        –
                        <strong class="text-foreground font-medium">{{ formatNumber(meta.to) }}</strong>
                        of
                        <strong class="text-foreground font-medium">{{ formatNumber(meta.total) }}</strong>
                        <span class="text-muted-foreground/70 ml-1.5">
                            · Page {{ formatNumber(meta.current_page) }} / {{ formatNumber(meta.last_page) }}
                        </span>
                    </span>

                    <label class="flex items-center gap-2 text-sm">
                        <span class="whitespace-nowrap">Rows</span>
                        <select
                            :value="meta.per_page"
                            class="bg-muted/50 focus-visible:ring-ring h-8 rounded-lg border px-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                            @change="navigate({ per_page: Number(($event.target as HTMLSelectElement).value) })"
                        >
                            <option v-for="size in perPageOptions" :key="size" :value="size">{{ size }}</option>
                        </select>
                    </label>
                </div>

                <nav class="flex items-center gap-1" aria-label="Pagination">
                    <button
                        type="button"
                        class="text-muted-foreground hover:bg-accent hover:text-accent-foreground inline-flex h-8 w-8 items-center justify-center rounded-lg border transition disabled:pointer-events-none disabled:opacity-40"
                        :disabled="meta.current_page <= 1 || loading"
                        aria-label="First page"
                        @click="navigate({ page: 1 }, true)"
                    >
                        <ChevronsLeft class="size-4" />
                    </button>
                    <button
                        type="button"
                        class="text-muted-foreground hover:bg-accent hover:text-accent-foreground inline-flex h-8 w-8 items-center justify-center rounded-lg border transition disabled:pointer-events-none disabled:opacity-40"
                        :disabled="meta.current_page <= 1 || loading"
                        aria-label="Previous page"
                        @click="navigate({ page: meta.current_page - 1 }, true)"
                    >
                        <ChevronLeft class="size-4" />
                    </button>

                    <template v-for="entry in pageWindow" :key="entry">
                        <span v-if="entry === 'gap-start' || entry === 'gap-end'" class="text-muted-foreground px-1.5 text-sm" aria-hidden="true">
                            …
                        </span>
                        <button
                            v-else
                            type="button"
                            :class="
                                cn(
                                    'inline-flex h-8 min-w-8 items-center justify-center rounded-lg px-2 text-sm font-medium transition',
                                    entry === meta.current_page
                                        ? 'bg-primary text-primary-foreground shadow-primary/30 shadow-sm'
                                        : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground',
                                )
                            "
                            :aria-current="entry === meta.current_page ? 'page' : undefined"
                            :aria-label="`Page ${entry}`"
                            :disabled="loading"
                            @click="navigate({ page: entry }, true)"
                        >
                            {{ entry }}
                        </button>
                    </template>

                    <button
                        type="button"
                        class="text-muted-foreground hover:bg-accent hover:text-accent-foreground inline-flex h-8 w-8 items-center justify-center rounded-lg border transition disabled:pointer-events-none disabled:opacity-40"
                        :disabled="meta.current_page >= meta.last_page || loading"
                        aria-label="Next page"
                        @click="navigate({ page: meta.current_page + 1 }, true)"
                    >
                        <ChevronRight class="size-4" />
                    </button>
                    <button
                        type="button"
                        class="text-muted-foreground hover:bg-accent hover:text-accent-foreground inline-flex h-8 w-8 items-center justify-center rounded-lg border transition disabled:pointer-events-none disabled:opacity-40"
                        :disabled="meta.current_page >= meta.last_page || loading"
                        aria-label="Last page"
                        @click="navigate({ page: meta.last_page }, true)"
                    >
                        <ChevronsRight class="size-4" />
                    </button>
                </nav>
            </div>
        </div>
    </div>
</template>

<style scoped>
.dt-progress {
    animation: dt-indeterminate 1.1s ease-in-out infinite;
}

@keyframes dt-indeterminate {
    0% {
        transform: translateX(-100%);
    }
    100% {
        transform: translateX(400%);
    }
}

/* Sticky header over the scrolled body (theme vars are raw HSL triplets) */
.dt-th {
    background-color: hsl(var(--muted) / 0.88);
    backdrop-filter: blur(6px);
    box-shadow: inset 0 -1px 0 0 hsl(var(--border));
}

/* Staggered row entrance — re-triggered whenever the page key changes */
.dt-row {
    animation: dt-row-in 0.32s cubic-bezier(0.21, 1.02, 0.73, 1) both;
    animation-delay: var(--dt-row-delay, 0ms);
}

@keyframes dt-row-in {
    from {
        opacity: 0;
        transform: translateY(6px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@media (prefers-reduced-motion: reduce) {
    .dt-row,
    .dt-progress {
        animation: none !important;
    }
}

@media print {
    .dt-th {
        position: static !important;
        background: transparent !important;
        backdrop-filter: none !important;
        box-shadow: none !important;
    }

    table {
        font-size: 11px;
    }

    thead th {
        color: #111827 !important;
        border-bottom: 2px solid #111827 !important;
    }

    tbody tr:nth-child(even) {
        background: #f9fafb;
    }
}
</style>
