<script setup lang="ts">
import { cn } from '@/lib/utils';
import type { SelectOption } from '../types';
import { Check, ChevronDown, LoaderCircle, Search, X } from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

/**
 * Premium searchable dropdown.
 *
 * Two modes:
 *  - static `options` — filtered instantly in the browser (roles, groups…)
 *  - `endpoint`       — server-searched via /core/api/options/… with debounced
 *                       fetches + request cancellation (users, causers…)
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
const open = ref(false);
const query = ref('');
const loading = ref(false);
const activeIndex = ref(-1);
const remoteItems = ref<SelectOption[]>([]);

let debounceTimer: number | undefined;
let controller: AbortController | null = null;

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

const selectedOptions = computed<SelectOption[]>(() =>
    values.value.map((value) => {
        const known =
            sourceOptions.value.find((option) => String(option.value) === String(value)) ??
            props.options.find((option) => String(option.value) === String(value));

        return known ?? { value, label: String(value) };
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

    try {
        const separator = props.endpoint.includes('?') ? '&' : '?';
        const url = `${props.endpoint}${separator}search=${encodeURIComponent(query.value.trim())}`;
        const response = await fetch(url, {
            signal: controller.signal,
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });

        if (!response.ok) throw new Error(`Request failed: ${response.status}`);

        const payload = (await response.json()) as { items?: SelectOption[] };
        remoteItems.value = payload.items ?? [];
        activeIndex.value = -1;
    } catch (error) {
        if ((error as Error)?.name !== 'AbortError') {
            remoteItems.value = [];
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

    if (event.key === 'ArrowDown') {
        event.preventDefault();
        activeIndex.value = Math.min(activeIndex.value + 1, visibleOptions.value.length - 1);
        return;
    }

    if (event.key === 'ArrowUp') {
        event.preventDefault();
        activeIndex.value = Math.max(activeIndex.value - 1, 0);
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
</script>

<template>
    <div ref="root" class="relative">
        <button
            :id="id"
            type="button"
            role="combobox"
            :aria-expanded="open"
            :disabled="disabled"
            :class="
                cn(
                    'flex min-h-10 w-full items-center justify-between gap-2 rounded-md border border-input bg-background px-3 py-1.5 text-left text-sm ring-offset-background transition',
                    'placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2',
                    'disabled:cursor-not-allowed disabled:opacity-50',
                    invalid && 'border-destructive focus-visible:ring-destructive',
                    open && 'ring-2 ring-ring ring-offset-2',
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
                    <span v-if="selectedOptions[0].description" class="truncate text-xs text-muted-foreground">
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
                        class="w-full bg-transparent text-sm outline-none placeholder:text-muted-foreground"
                        @keydown="onKeydown"
                    />
                </div>

                <ul class="max-h-60 overflow-y-auto p-1" role="listbox">
                    <li
                        v-for="(option, index) in visibleOptions"
                        :key="option.value"
                        role="option"
                        :aria-selected="isSelected(option)"
                        :class="
                            cn(
                                'relative flex cursor-pointer select-none items-start gap-2 rounded-md px-2.5 py-2 text-sm transition-colors',
                                index === activeIndex && 'bg-accent',
                                isSelected(option) && 'font-medium',
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
                            <span class="block truncate">{{ option.label }}</span>
                            <span v-if="option.description" class="mt-0.5 block truncate text-xs text-muted-foreground">
                                {{ option.description }}
                            </span>
                        </span>
                    </li>

                    <li
                        v-if="visibleOptions.length === 0 && !loading"
                        class="px-3 py-6 text-center text-sm text-muted-foreground"
                    >
                        {{ emptyText }}
                    </li>
                </ul>

                <p v-if="endpoint" class="border-t px-3 py-1.5 text-[11px] text-muted-foreground">
                    {{ remoteItems.length }} option{{ remoteItems.length === 1 ? '' : 's' }} — keep typing to refine
                    the search
                </p>
            </div>
        </Transition>
    </div>
</template>
