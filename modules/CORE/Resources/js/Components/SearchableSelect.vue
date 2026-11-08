<script setup lang="ts">
import { cn } from '@/lib/utils';
import type { SelectOption } from '../types';
import { unwrapOptions } from '../Utils/optionsPayload';
import { AlertCircle, Check, ChevronDown, LoaderCircle, Search, X } from 'lucide-vue-next';
import { computed, nextTick, onBeforeUnmount, ref, useId, watch } from 'vue';

/**
 * Premium searchable dropdown.
 *
 * Two modes:
 *  - static `options` — filtered instantly in the browser (roles, groups…)
 *  - `endpoint`       — server-searched with debounced fetches + cancellation
 *                       (vendors, products, warehouses, accounts, employees…)
 *
 * Supports single & multiple selection, keyboard navigation, clearing and
 * full keyboard accessibility.
 */
interface Props {
    modelValue?: string | number | (string | number)[] | null;
    class?: string;
    options?: SelectOption[];
    endpoint?: string;
    multiple?: boolean;
    placeholder?: string;
    searchPlaceholder?: string;
    emptyText?: string;
    clearable?: boolean;
    disabled?: boolean;
    invalid?: boolean;
    id?: string;
    badge?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    modelValue: null,
    options: () => [],
    endpoint: undefined,
    multiple: false,
    placeholder: 'Select an option…',
    searchPlaceholder: 'Type to search…',
    emptyText: 'No matching results',
    clearable: true,
    disabled: false,
    invalid: false,
    id: undefined,
    badge: false,
});

const emit = defineEmits<{
    'update:modelValue': [value: string | number | (string | number)[] | null];
}>();

const root = ref<HTMLElement | null>(null);
const searchInput = ref<HTMLInputElement | null>(null);
const listEl = ref<HTMLUListElement | null>(null);
const open = ref(false);
const query = ref('');
const loading = ref(false);
const failed = ref(false);
const activeIndex = ref(-1);
const remoteItems = ref<SelectOption[]>([]);

/**
 * Every option this component has ever seen, keyed by value.
 *
 * In endpoint mode the visible list is replaced on each keystroke, so without
 * this a chosen item would stop being present and its label would degrade to
 * the raw value — a vendor picked as "Amparo Lang Enterprise (VEN-9595)" would
 * render as "11" in the closed control.
 */
const seen = new Map<string, SelectOption>();

let debounceTimer: number | undefined;
let controller: AbortController | null = null;

// useId() rather than a counter: stable across SSR hydration and unique per
// instance, so aria-controls/aria-activedescendant always point at this list.
const instanceId = useId();
const listId = computed(() => `${props.id ?? instanceId}-listbox`);

/* ------------------------------- selection ------------------------------ */

const values = computed<(string | number)[]>(() => {
    if (props.multiple) {
        return Array.isArray(props.modelValue) ? [...props.modelValue] : [];
    }

    if (props.modelValue === null || props.modelValue === undefined || props.modelValue === '') {
        return [];
    }

    return Array.isArray(props.modelValue) ? props.modelValue.slice(0, 1) : [props.modelValue];
});

const sourceOptions = computed<SelectOption[]>(() => (props.endpoint ? remoteItems.value : props.options));

function remember(options: SelectOption[]): void {
    for (const option of options) {
        seen.set(String(option.value), option);
    }
}

watch(sourceOptions, (options) => remember(options), { immediate: true });
watch(
    () => props.options,
    (options) => remember(options ?? []),
    { immediate: true },
);

const selectedOptions = computed<SelectOption[]>(() =>
    values.value.map((value) => {
        const key = String(value);

        return (
            sourceOptions.value.find((option) => String(option.value) === key) ??
            seen.get(key) ??
            props.options.find((option) => String(option.value) === key) ?? { value, label: key }
        );
    }),
);

const visibleOptions = computed<SelectOption[]>(() => {
    const needle = query.value.trim().toLowerCase();

    if (props.endpoint) return remoteItems.value;

    if (!needle) return props.options;

    return props.options.filter(
        (option) =>
            option.label.toLowerCase().includes(needle) ||
            (option.description ?? '').toLowerCase().includes(needle) ||
            (option.meta ?? '').toLowerCase().includes(needle) ||
            String(option.value).toLowerCase().includes(needle),
    );
});

const isSelected = (option: SelectOption): boolean =>
    values.value.some((value) => String(value) === String(option.value));

/* --------------------------------- state -------------------------------- */

function toggleOpen(force?: boolean) {
    if (props.disabled) return;

    const next = force ?? !open.value;

    if (next && !open.value) {
        query.value = '';
        activeIndex.value = -1;
        failed.value = false;

        if (props.endpoint && remoteItems.value.length === 0) {
            void fetchRemote();
        }
    }

    if (!next) {
        controller?.abort();
        loading.value = false;
    }

    open.value = next;

    if (next) {
        requestAnimationFrame(() => searchInput.value?.focus());
    }
}

function select(option: SelectOption) {
    if (option.disabled) return;

    remember([option]);

    if (props.multiple) {
        const current = values.value;
        const index = current.findIndex((value) => String(value) === String(option.value));

        if (index === -1) {
            current.push(option.value);
        } else {
            current.splice(index, 1);
        }

        emit('update:modelValue', [...current]);

        if (!props.endpoint) {
            query.value = '';
        }
    } else {
        emit('update:modelValue', option.value);
        open.value = false;
    }
}

function clear(event?: Event) {
    event?.stopPropagation();
    emit('update:modelValue', props.multiple ? [] : null);
}

function removeChip(value: string | number, event?: Event) {
    event?.stopPropagation();

    if (!props.multiple) {
        clear(event);
        return;
    }

    emit(
        'update:modelValue',
        values.value.filter((current) => String(current) !== String(value)),
    );
}

/* --------------------------- remote search mode -------------------------- */

async function fetchRemote() {
    if (!props.endpoint) return;

    controller?.abort();
    controller = new AbortController();
    loading.value = true;
    failed.value = false;

    try {
        const separator = props.endpoint.includes('?') ? '&' : '?';
        const url = `${props.endpoint}${separator}search=${encodeURIComponent(query.value.trim())}`;
        const response = await fetch(url, {
            signal: controller.signal,
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });

        if (!response.ok) throw new Error(`Request failed: ${response.status}`);

        remoteItems.value = unwrapOptions(await response.json());
        remember(remoteItems.value);
        activeIndex.value = -1;
    } catch (error) {
        if ((error as Error)?.name !== 'AbortError') {
            remoteItems.value = [];
            // Surfaced separately from "no matches": a 403/500 looks identical
            // to an empty result set otherwise, which is how this component
            // shipped broken with no way to tell from the UI.
            failed.value = true;
        }
    } finally {
        loading.value = false;
    }
}

watch(query, () => {
    window.clearTimeout(debounceTimer);

    if (props.endpoint) {
        debounceTimer = window.setTimeout(() => void fetchRemote(), 280);
    }
});

onBeforeUnmount(() => {
    window.clearTimeout(debounceTimer);
    controller?.abort();
});

/* ------------------------------ interactions ----------------------------- */

/** Keep the highlighted row in view — the list scrolls, the marker must follow. */
function scrollActiveIntoView() {
    void nextTick(() => {
        const node = listEl.value?.querySelector<HTMLElement>('[data-active="true"]');
        // Optional call: scrollIntoView is absent in jsdom and in some embedded
        // webviews, and keyboard navigation must not depend on it.
        node?.scrollIntoView?.({ block: 'nearest' });
    });
}

function moveTo(index: number) {
    const max = visibleOptions.value.length - 1;
    activeIndex.value = Math.max(0, Math.min(index, max));
    scrollActiveIntoView();
}

function onKeydown(event: KeyboardEvent) {
    if (!open.value && ['ArrowDown', 'Enter'].includes(event.key)) {
        event.preventDefault();
        toggleOpen(true);
        return;
    }

    if (!open.value) return;

    if (event.key === 'Escape') {
        event.preventDefault();
        open.value = false;
        return;
    }

    if (event.key === 'Tab') {
        open.value = false;
        return;
    }

    if (event.key === 'ArrowDown') {
        event.preventDefault();
        moveTo(activeIndex.value + 1);
        return;
    }

    if (event.key === 'ArrowUp') {
        event.preventDefault();
        moveTo(activeIndex.value - 1);
        return;
    }

    if (event.key === 'Home') {
        event.preventDefault();
        moveTo(0);
        return;
    }

    if (event.key === 'End') {
        event.preventDefault();
        moveTo(visibleOptions.value.length - 1);
        return;
    }

    if (event.key === 'Enter') {
        const option = visibleOptions.value[activeIndex.value];
        if (option) {
            event.preventDefault();
            select(option);
        }
        return;
    }

    if (event.key === 'Backspace' && query.value === '' && props.multiple && values.value.length > 0) {
        removeChip(values.value[values.value.length - 1]);
    }
}

function onDocumentClick(event: MouseEvent) {
    if (open.value && root.value && !root.value.contains(event.target as Node)) {
        open.value = false;
    }
}

if (typeof document !== 'undefined') {
    document.addEventListener('click', onDocumentClick, true);
    onBeforeUnmount(() => document.removeEventListener('click', onDocumentClick, true));
}

/* ------------------------------- rendering ------------------------------- */

/** Split a label so the matched substring can be highlighted. */
function highlight(label: string): { text: string; hit: boolean }[] {
    const needle = query.value.trim();

    if (!needle || !label) return [{ text: label, hit: false }];

    const parts: { text: string; hit: boolean }[] = [];
    const lowerLabel = label.toLowerCase();
    const lowerNeedle = needle.toLowerCase();
    let cursor = 0;

    while (cursor < label.length) {
        const at = lowerLabel.indexOf(lowerNeedle, cursor);

        if (at === -1) {
            parts.push({ text: label.slice(cursor), hit: false });
            break;
        }

        if (at > cursor) parts.push({ text: label.slice(cursor, at), hit: false });
        parts.push({ text: label.slice(at, at + needle.length), hit: true });
        cursor = at + needle.length;
    }

    return parts;
}

const activeDescendant = computed(() =>
    activeIndex.value >= 0 ? `${listId.value}-option-${activeIndex.value}` : undefined,
);
</script>

<template>
    <div ref="root" class="relative">
        <button
            :id="id"
            type="button"
            role="combobox"
            :aria-expanded="open"
            :aria-controls="listId"
            :aria-haspopup="'listbox'"
            :aria-activedescendant="activeDescendant"
            :aria-busy="loading || undefined"
            :aria-invalid="invalid || undefined"
            :disabled="disabled"
            :class="
                cn(
                    'flex min-h-10 w-full items-center justify-between gap-2 rounded-md border border-input bg-background px-3 py-1.5 text-left text-sm ring-offset-background transition',
                    'hover:border-muted-foreground/40 placeholder:text-muted-foreground',
                    'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2',
                    'disabled:cursor-not-allowed disabled:opacity-50',
                    invalid && 'border-destructive focus-visible:ring-destructive',
                    open && 'border-ring ring-2 ring-ring/40 ring-offset-2',
                    props.class,
                )
            "
            @click="toggleOpen()"
            @keydown="onKeydown"
        >
            <!-- single selection -->
            <span v-if="!multiple && selectedOptions.length" class="flex min-w-0 items-center gap-2">
                <span
                    v-if="badge"
                    class="inline-flex max-w-full items-center rounded-md border bg-muted/60 px-2 py-0.5 text-xs font-medium text-foreground"
                >
                    <span class="truncate">{{ selectedOptions[0].label }}</span>
                </span>
                <template v-else>
                    <span class="truncate font-medium">{{ selectedOptions[0].label }}</span>
                    <span
                        v-if="selectedOptions[0].description"
                        class="hidden truncate text-xs text-muted-foreground sm:inline"
                    >
                        {{ selectedOptions[0].description }}
                    </span>
                </template>
            </span>

            <!-- multiple selection -->
            <span v-else-if="multiple && selectedOptions.length" class="flex min-w-0 flex-wrap items-center gap-1.5">
                <span
                    v-for="option in selectedOptions"
                    :key="option.value"
                    class="inline-flex max-w-[14rem] items-center gap-1 rounded-md border bg-muted/60 px-2 py-0.5 text-xs font-medium"
                >
                    <span class="truncate">{{ option.label }}</span>
                    <button
                        type="button"
                        class="rounded-full text-muted-foreground transition hover:text-foreground"
                        :aria-label="`Remove ${option.label}`"
                        @click="removeChip(option.value, $event)"
                    >
                        <X class="size-3" />
                    </button>
                </span>
            </span>

            <span v-else class="truncate text-muted-foreground">{{ placeholder }}</span>

            <span class="flex shrink-0 items-center gap-1.5 pl-1 text-muted-foreground">
                <LoaderCircle v-if="loading" class="size-4 animate-spin" />
                <button
                    v-else-if="clearable && !disabled && selectedOptions.length > 0"
                    type="button"
                    class="rounded-md p-0.5 transition hover:bg-muted hover:text-foreground"
                    aria-label="Clear selection"
                    @click="clear($event)"
                >
                    <X class="size-3.5" />
                </button>
                <ChevronDown class="size-4 transition-transform duration-200" :class="open && 'rotate-180'" />
            </span>
        </button>

        <Transition
            enter-active-class="transition duration-150 ease-out"
            enter-from-class="opacity-0 -translate-y-1 scale-[0.98]"
            enter-to-class="opacity-100 translate-y-0 scale-100"
            leave-active-class="transition duration-100 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-show="open"
                class="absolute z-50 mt-1.5 w-full overflow-hidden rounded-lg border bg-popover text-popover-foreground shadow-xl shadow-black/10"
            >
                <div
                    v-if="endpoint || multiple || options.length > 8 || query.length > 0"
                    class="flex items-center gap-2 border-b px-3 py-2"
                >
                    <Search class="size-4 shrink-0 text-muted-foreground" />
                    <input
                        ref="searchInput"
                        v-model="query"
                        type="text"
                        :placeholder="searchPlaceholder"
                        :aria-controls="listId"
                        autocomplete="off"
                        spellcheck="false"
                        class="w-full bg-transparent text-sm outline-none placeholder:text-muted-foreground"
                        @keydown="onKeydown"
                    />
                    <LoaderCircle v-if="loading" class="size-3.5 shrink-0 animate-spin text-muted-foreground" />
                </div>

                <!-- loading: skeleton rows, so the panel never looks frozen -->
                <ul v-if="loading && visibleOptions.length === 0" class="space-y-1 p-2" aria-hidden="true">
                    <li v-for="n in 4" :key="n" class="flex items-center gap-2 rounded-md px-2.5 py-2">
                        <span class="size-4 shrink-0 rounded bg-muted" />
                        <span class="h-3.5 flex-1 animate-pulse rounded bg-muted" :style="{ maxWidth: `${95 - n * 9}%` }" />
                    </li>
                </ul>

                <ul v-else ref="listEl" :id="listId" class="max-h-64 overflow-y-auto p-1" role="listbox">
                    <li
                        v-for="(option, index) in visibleOptions"
                        :id="`${listId}-option-${index}`"
                        :key="option.value"
                        role="option"
                        :aria-selected="isSelected(option)"
                        :data-active="index === activeIndex ? 'true' : undefined"
                        :class="
                            cn(
                                'relative flex cursor-pointer select-none items-start gap-2 rounded-md px-2.5 py-2 text-sm transition-colors',
                                index === activeIndex && 'bg-accent text-accent-foreground',
                                isSelected(option) && 'font-medium',
                                option.disabled && 'cursor-not-allowed opacity-50',
                            )
                        "
                        @mouseenter="activeIndex = index"
                        @click="select(option)"
                    >
                        <Check
                            v-if="isSelected(option)"
                            class="mt-0.5 size-4 shrink-0 text-primary"
                            aria-hidden="true"
                        />
                        <span v-else class="mt-0.5 w-4 shrink-0" aria-hidden="true" />

                        <span class="min-w-0 flex-1">
                            <span class="flex items-baseline justify-between gap-2">
                                <span class="truncate">
                                    <template v-for="(part, i) in highlight(option.label)" :key="i">
                                        <mark
                                            v-if="part.hit"
                                            class="rounded-[3px] bg-primary/15 px-0.5 text-inherit"
                                            >{{ part.text }}</mark
                                        >
                                        <template v-else>{{ part.text }}</template>
                                    </template>
                                </span>
                                <span
                                    v-if="option.meta"
                                    class="shrink-0 rounded border bg-muted/50 px-1.5 py-0.5 text-[10px] font-medium tabular-nums text-muted-foreground"
                                >
                                    {{ option.meta }}
                                </span>
                            </span>
                            <span
                                v-if="option.description"
                                class="mt-0.5 block truncate text-xs text-muted-foreground"
                            >
                                {{ option.description }}
                            </span>
                        </span>
                    </li>

                    <li v-if="visibleOptions.length === 0" class="px-3 py-6 text-center">
                        <template v-if="failed">
                            <AlertCircle class="mx-auto size-5 text-destructive" aria-hidden="true" />
                            <p class="mt-2 text-sm font-medium">Couldn’t load options</p>
                            <p class="mt-0.5 text-xs text-muted-foreground">
                                The server rejected the request.
                            </p>
                            <button
                                type="button"
                                class="mt-2 rounded-md border px-2.5 py-1 text-xs font-medium transition hover:bg-accent"
                                @click="fetchRemote()"
                            >
                                Try again
                            </button>
                        </template>
                        <template v-else>
                            <Search class="mx-auto size-5 text-muted-foreground/60" aria-hidden="true" />
                            <p class="mt-2 text-sm text-muted-foreground">{{ emptyText }}</p>
                            <p v-if="endpoint && query.trim()" class="mt-0.5 text-xs text-muted-foreground/80">
                                Try a shorter or different search term
                            </p>
                        </template>
                    </li>
                </ul>

                <p
                    v-if="endpoint"
                    class="flex items-center justify-between gap-2 border-t px-3 py-1.5 text-[11px] text-muted-foreground"
                    aria-live="polite"
                >
                    <span>
                        {{ loading ? 'Searching…' : `${remoteItems.length} option${remoteItems.length === 1 ? '' : 's'}` }}
                    </span>
                    <span v-if="!loading" class="hidden sm:inline">keep typing to refine</span>
                </p>
            </div>
        </Transition>
    </div>
</template>
