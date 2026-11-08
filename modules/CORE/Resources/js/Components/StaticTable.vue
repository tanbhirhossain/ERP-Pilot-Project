<script setup lang="ts">
import { cn } from '@/lib/utils';
import { Inbox } from 'lucide-vue-next';
import { computed, onMounted, onUpdated, ref } from 'vue';

/**
 * The client-side sibling of `DataTable`.
 *
 * `DataTable` is server-driven: it needs `meta: PaginatorMeta` and a `routeName`, because search,
 * sort, filters and page all live in the query string. That is right for an index list of any size,
 * and useless for the other tables this application has — an invoice's lines, a journal's postings, a
 * report grid, a reference list of holidays. Measured across the module pages: 62 pages already use
 * `DataTable`, and all 70 hand-rolled `<table>` elements are client-side, so none of them could.
 *
 * Those 70 were written by hand, and it showed: 6 distinct `<table>` class strings, 65 distinct `<th>`
 * class strings and 139 distinct `<td>` class strings, so the same kind of grid was padded, aligned and
 * bordered a different way on nearly every page. This component owns that rhythm once. Pages keep
 * writing their own `<tr>`/`<td>` — the cells hold arbitrary markup, badges, links and inputs, which no
 * column config can express — and inherit the padding, the header treatment, the row hover and the
 * tabular digits from here.
 *
 * The header paint, cell metrics and hover are DataTable's own values, so a page that mixes the two
 * (a paginated list above, a totals grid below) reads as one table family rather than two.
 */
export interface StaticTableColumn {
    label: string;
    align?: 'left' | 'center' | 'right';
    /** Extra classes on the `<th>`, for a width or a print-only column. */
    headerClass?: string;
}

interface Props {
    /**
     * Headings to render for you. Omit it and put your own `<thead>` in the `head` slot instead —
     * a grid whose headers carry sort hints, colspan groups or units needs the markup.
     */
    columns?: StaticTableColumn[];
    /**
     * Hold the header row in place while the page scrolls. Leave it off when the table sits in its own
     * vertically scrolling box: sticky would then resolve against that box and add nothing.
     */
    sticky?: boolean;
    /**
     * Tighter cells for the genuinely cramped grids — an Excel-style week, a text-xs reconciliation
     * split. The default is already this application's rhythm for a client-side grid: measured across
     * the 70 tables this component replaced, 27 padded their cells `px-3 py-2` and 15 `p-3`, so the
     * default reproduces what those pages had rather than imposing DataTable's roomier `px-4 py-3`.
     * DataTable stays roomier on purpose: a paginated index is the surface somebody browses, while
     * these are detail lines and report grids read in passing.
     */
    dense?: boolean;
    /** Show the empty state instead of an empty body. */
    empty?: boolean;
    emptyTitle?: string;
    emptyDescription?: string;
    /**
     * Column count for the empty state's cell. Rarely needed: it comes from `columns` when given, and
     * otherwise is measured off the rendered rows, so a page's own header — colspan groups, a
     * `v-for` of dates — sets it without the page counting anything.
     */
    colspan?: number;
    /**
     * Drop this component's own card — border, radius, shadow, surface — for a table that already sits
     * flush inside a bordered panel (an `AcctSection`, a `bg-card border rounded-xl overflow-hidden`
     * box). Measured after the conversion: 27 of the 70 tables sat in such a panel with no padding
     * between, so each drew a second border against its parent's edge, where the bare `<table>` it
     * replaced had drawn none. The rhythm, header and empty state are unchanged.
     */
    flush?: boolean;
    /** Accessible name when the table has no visible caption. */
    label?: string;
}

const props = withDefaults(defineProps<Props>(), {
    columns: undefined,
    sticky: false,
    dense: false,
    flush: false,
    empty: false,
    emptyTitle: 'Nothing to show yet',
    emptyDescription: 'Rows will appear here as soon as there is something to report.',
    colspan: undefined,
    label: undefined,
});

const tableEl = ref<HTMLTableElement | null>(null);
const measuredSpan = ref(1);

/** The widest row's column count, counting each cell's colspan, ignoring the empty row itself. */
function measureSpan(): void {
    const table = tableEl.value;
    if (!table) return;
    let widest = 0;
    for (const row of Array.from(table.rows)) {
        if (row.hasAttribute('data-st-empty')) continue;
        let width = 0;
        for (const cell of Array.from(row.cells)) width += cell.colSpan || 1;
        widest = Math.max(widest, width);
    }
    // Only write on a change, or onUpdated would re-render forever.
    if (widest > 0 && widest !== measuredSpan.value) measuredSpan.value = widest;
}

onMounted(measureSpan);
onUpdated(measureSpan);

const span = computed(() => props.colspan ?? (props.columns?.length || measuredSpan.value));
</script>

<template>
    <div :class="flush ? 'relative' : 'bg-card relative overflow-hidden rounded-xl border shadow-sm'">
        <!--
            DataTable scrolls its body in a `max-h-[72vh] overflow-auto` box, and that box is the only
            reason its sticky header works at all: `position: sticky` resolves against the nearest
            scrolling ancestor, so a plain `overflow-x-auto` wrapper would make it inert. Same box,
            same 72vh, same print escape hatch — a page that mixes the two tables sees one behaviour.
        -->
        <div :class="sticky ? 'max-h-[72vh] overflow-auto print:max-h-none print:overflow-visible' : 'overflow-x-auto'">
            <table
                ref="tableEl"
                class="w-full caption-bottom"
                :class="[dense ? 'st-dense text-xs' : 'text-sm', { 'st-sticky': sticky }]"
                :aria-label="label"
            >
                <thead v-if="columns?.length">
                    <tr class="border-border/70 border-b">
                        <th
                            v-for="(column, index) in columns"
                            :key="`${column.label}-${index}`"
                            scope="col"
                            :class="
                                cn(
                                    'st-th text-muted-foreground px-3 py-2 text-left text-xs font-semibold tracking-wider uppercase',
                                    sticky && 'sticky top-0 z-[1]',
                                    dense && 'px-2 py-1.5',
                                    column.align === 'right' && 'text-right',
                                    column.align === 'center' && 'text-center',
                                    column.headerClass,
                                )
                            "
                        >
                            {{ column.label }}
                        </th>
                    </tr>
                </thead>
                <slot name="head" />
                <!--
                    The row group belongs to whoever wrote the rows. Every page this component
                    replaced passes its own <tbody> (and often a <tfoot> of totals), so wrapping the
                    slot in a second one nested a tbody inside a tbody and put the page's <thead>
                    inside a tbody — which loses the header's table-header-group semantics, breaks the
                    header/row association for a screen reader, and makes `sticky` resolve against the
                    wrong box. Only the `columns` shorthand, where the caller passes bare rows, gets a
                    row group from here.
                -->
                <tbody v-if="columns?.length" class="divide-border/70 divide-y">
                    <slot />
                </tbody>
                <slot v-else />
                <tbody v-if="empty">
                    <tr data-st-empty>
                        <td :colspan="span" :class="dense ? 'px-3 py-6' : 'px-4 py-10'">
                            <div class="mx-auto flex max-w-sm flex-col items-center text-center">
                                <div
                                    class="from-muted/80 grid size-12 place-items-center rounded-2xl border border-dashed bg-gradient-to-b to-transparent"
                                >
                                    <Inbox class="text-muted-foreground/70 size-6" />
                                </div>
                                <h3 class="mt-3 text-sm font-semibold">{{ emptyTitle }}</h3>
                                <p v-if="emptyDescription" class="text-muted-foreground mt-1 text-sm leading-relaxed">{{ emptyDescription }}</p>
                                <slot name="empty-action" />
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>

<style scoped>
/* Tailwind's own layer order, in case this chunk is ever parsed before app.css; otherwise a no-op. */
@layer properties, theme, base, components, utilities;

/*
 * Two strengths of rule, on purpose.
 *
 * Tailwind v4 emits every utility inside `@layer utilities`, and an unlayered rule beats any layered
 * rule whatever its specificity. The first version of this file was entirely unlayered, so its
 * `td { color; font-size }` overrode the classes the pages wrote on their own cells: across the 59
 * pages, 65 cells carrying a colour utility (a red shortfall, a green balance, a muted secondary
 * column), 11 more binding one through `:class`, and 70 carrying a size utility, all flattened to one
 * colour and one size. A cell's colour means something, and it is the page's to decide.
 *
 * So what this component exists to unify — data-cell rhythm, the header, row separators — stays
 * unlayered and wins. What only fills a gap — colour, alignment, and the spacing of a lone
 * full-width message cell — lives in `@layer components`, beneath utilities, where any class the page
 * put on that cell takes precedence. Text size is not set on cells at all: they inherit it from the
 * table, which is `text-sm`, or `text-xs` when dense.
 */
@layer components {
    :deep(td) {
        padding: 0.5rem 0.75rem;
        color: hsl(var(--foreground) / 0.9);
        vertical-align: middle;
        font-variant-numeric: tabular-nums;
    }

    .st-dense :deep(td) {
        padding: 0.25rem 0.5rem;
    }
}

/*
 * The rhythm, imposed on every data cell. The one exception is a row whose single cell spans the
 * table — the "No holidays entered yet." notice 22 pages already write, a section heading — because
 * that spacing is its own design: it keeps the layered default above unless the page padded it.
 */
:deep(td:not([colspan]:only-child)) {
    padding: 0.5rem 0.75rem;
}

.st-dense :deep(td:not([colspan]:only-child)) {
    padding: 0.25rem 0.5rem;
}

/*
 * Every header gets DataTable's treatment, including a `<thead>` the page wrote itself — which is most
 * of them, since a grid with sort hints, colspan groups or units in the header needs real markup.
 * Unlayered: a uniform header is the point.
 */
:deep(thead th) {
    padding: 0.5rem 0.75rem;
    color: hsl(var(--muted-foreground));
    font-size: 0.75rem;
    font-weight: 600;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    vertical-align: middle;
    font-variant-numeric: tabular-nums;
    background-color: hsl(var(--muted) / 0.88);
    backdrop-filter: blur(6px);
    box-shadow: inset 0 -1px 0 0 hsl(var(--border));
}

.st-dense :deep(thead th) {
    padding: 0.375rem 0.5rem;
}

.st-sticky :deep(thead th) {
    position: sticky;
    top: 0;
    z-index: 1;
}

:deep(tbody tr) {
    transition: background-color 120ms ease;
}

/*
 * Row separators, so a page that drew none still reads as a grid. Tables collapse their borders
 * (Tailwind's preflight), so a page's own `divide-y` or `border-b` merges with this into one line
 * rather than doubling it.
 */
:deep(tbody tr + tr) {
    border-top: 1px solid hsl(var(--border) / 0.7);
}

:deep(tbody tr:not([data-st-empty]):hover) {
    background-color: hsl(var(--accent) / 0.5);
}

/* DataTable's header paint, so a mixed page does not show two kinds of table. */
.st-th {
    background-color: hsl(var(--muted) / 0.88);
    backdrop-filter: blur(6px);
    box-shadow: inset 0 -1px 0 0 hsl(var(--border));
}

@media (prefers-reduced-motion: reduce) {
    :deep(tbody tr) {
        transition: none;
    }
}

@media print {
    /* A sticky, blurred, tinted header prints as a grey band over the first row. */
    .st-th,
    :deep(thead th) {
        position: static !important;
        background: transparent !important;
        backdrop-filter: none !important;
        box-shadow: none !important;
    }

    :deep(tbody tr:hover) {
        background-color: transparent;
    }
}
</style>
