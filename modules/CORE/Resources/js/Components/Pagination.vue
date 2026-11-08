<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * One link from Laravel's `paginate()->toArray()['links']`.
 */
interface PageLink {
    url: string | null;
    label: string;
    active: boolean;
}

const props = withDefaults(
    defineProps<{
        links?: PageLink[];
        /** Total record count, shown as "x of y" when provided. */
        total?: number;
        /** Records on the current page, for the same caption. */
        current?: number;
        /** Hide single-page paginators instead of rendering one disabled button. */
        hideWhenSinglePage?: boolean;
    }>(),
    { links: () => [], total: undefined, current: undefined, hideWhenSinglePage: true },
);

/**
 * Laravel HTML-escapes the « and » glyphs in link labels (`&laquo; Previous`).
 * The pages this replaces used `v-html` to undo that, which Vue rejects on a
 * component root and which would inject markup if a label ever carried user
 * data. Decoding the entities to plain text is both safe and renders correctly.
 */
const DECODE_HOST = typeof document !== 'undefined' ? document.createElement('textarea') : null;

function decodeLabel(label: string): string {
    if (!label) return '';
    if (!DECODE_HOST) return label;
    DECODE_HOST.innerHTML = label;
    return DECODE_HOST.value;
}

const items = computed(() => (props.links ?? []).map((l) => ({ ...l, text: decodeLabel(l.label) })));

/** A paginator with only a single page conveys nothing — drop it by default. */
const visible = computed(() => !props.hideWhenSinglePage || items.value.length > 3);

const caption = computed(() => {
    if (props.total === undefined) return null;
    if (props.current !== undefined) {
        return `Showing ${props.current} of ${props.total.toLocaleString()} records`;
    }
    return `${props.total.toLocaleString()} records`;
});
</script>

<template>
    <nav v-if="visible && items.length" class="flex flex-wrap items-center justify-between gap-3 border-t p-3" aria-label="Pagination">
        <p v-if="caption" class="text-xs text-muted-foreground">{{ caption }}</p>
        <span v-else />

        <ul class="flex flex-wrap gap-1">
            <li v-for="(l, i) in items" :key="`${l.text}-${i}`">
                <Link
                    v-if="l.url && !l.active"
                    :href="l.url"
                    class="inline-flex min-w-8 items-center justify-center rounded-md border bg-card px-3 py-1 text-xs font-medium transition-colors hover:bg-accent hover:text-accent-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                >
                    {{ l.text }}
                </Link>
                <span
                    v-else-if="l.active"
                    aria-current="page"
                    class="inline-flex min-w-8 items-center justify-center rounded-md bg-primary px-3 py-1 text-xs font-semibold text-primary-foreground"
                >
                    {{ l.text }}
                </span>
                <span
                    v-else
                    aria-disabled="true"
                    class="inline-flex min-w-8 cursor-not-allowed items-center justify-center rounded-md border bg-muted/40 px-3 py-1 text-xs text-muted-foreground opacity-60"
                >
                    {{ l.text }}
                </span>
            </li>
        </ul>
    </nav>
</template>
