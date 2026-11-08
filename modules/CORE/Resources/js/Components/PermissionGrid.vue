<script setup lang="ts">
import { Checkbox } from '@/components/ui/checkbox';
import { cn } from '@/lib/utils';
import type { SelectOption } from '../types';
import { computed, ref } from 'vue';

/**
 * Permission matrix grouped by "resource" with per-group select-all —
 * the fastest way to assign a capability set to a role.
 */
interface Props {
    options: SelectOption[];
    modelValue: number[];
    disabled?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    disabled: false,
});

const emit = defineEmits<{
    'update:modelValue': [value: number[]];
}>();

const query = ref('');

const groups = computed(() => {
    const needle = query.value.trim().toLowerCase();

    const filtered = needle
        ? props.options.filter((option) => option.label.toLowerCase().includes(needle))
        : props.options;

    const map = new Map<string, SelectOption[]>();

    for (const option of filtered) {
        const segments = String(option.label).split('.');
        const group = segments.length > 1 ? segments[0] : 'general';

        if (!map.has(group)) map.set(group, []);
        map.get(group)!.push(option);
    }

    return [...map.entries()].sort(([a], [b]) => a.localeCompare(b));
});

const selected = computed({
    get: () => Array.isArray(props.modelValue) ? props.modelValue : [],
    set: (value: number[]) => emit('update:modelValue', value),
});

function has(value: number): boolean {
    return selected.value.some((id) => id === value);
}

function toggle(value: number) {
    if (props.disabled) return;

    selected.value = has(value) ? selected.value.filter((id) => id !== value) : [...selected.value, value];
}

function isGroupChecked(options: SelectOption[]): boolean {
    return options.every((option) => has(Number(option.value)));
}

function toggleGroup(options: SelectOption[]) {
    if (props.disabled) return;

    const ids = options.map((option) => Number(option.value));
    const allChecked = ids.every((id) => has(id));

    if (allChecked) {
        selected.value = selected.value.filter((id) => !ids.includes(id));
    } else {
        selected.value = [...new Set([...selected.value, ...ids])];
    }
}
</script>

<template>
    <div class="space-y-4">
        <div class="relative max-w-sm">
            <input
                v-model="query"
                type="search"
                placeholder="Filter permissions…"
                class="h-9 w-full rounded-md border border-input bg-background px-3 pr-8 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                :disabled="disabled"
            />
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            <section
                v-for="[group, groupOptions] in groups"
                :key="group"
                class="overflow-hidden rounded-xl border bg-card"
            >
                <header class="flex items-center justify-between border-b bg-muted/40 px-4 py-2.5">
                    <div class="flex items-center gap-2.5">
                        <label
                            class="flex cursor-pointer items-center"
                            :class="disabled && 'cursor-not-allowed opacity-60'"
                        >
                            <Checkbox
                                :model-value="isGroupChecked(groupOptions)"
                                :disabled="disabled"
                                @update:model-value="toggleGroup(groupOptions)"
                            />
                        </label>
                        <h3 class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                            {{ group }}
                        </h3>
                    </div>
                    <span class="rounded-full bg-background px-2 py-0.5 text-[11px] font-medium text-muted-foreground tabular-nums">
                        {{ groupOptions.filter((option) => has(Number(option.value))).length }}/{{ groupOptions.length }}
                    </span>
                </header>

                <ul class="max-h-64 divide-y divide-border/60 overflow-y-auto">
                    <li v-for="option in groupOptions" :key="option.value">
                        <label
                            :class="
                                cn(
                                    'flex cursor-pointer items-center gap-3 px-4 py-2.5 text-sm transition hover:bg-muted/50',
                                    has(Number(option.value)) && 'bg-primary/[0.04]',
                                    disabled && 'cursor-not-allowed opacity-60',
                                )
                            "
                        >
                            <Checkbox
                                :model-value="has(Number(option.value))"
                                :disabled="disabled"
                                @update:model-value="toggle(Number(option.value))"
                            />
                            <span
                                class="font-mono text-[13px]"
                                :class="has(Number(option.value)) ? 'text-foreground' : 'text-muted-foreground'"
                            >
                                {{ option.label }}
                            </span>
                        </label>
                    </li>
                </ul>
            </section>
        </div>

        <p class="text-xs text-muted-foreground">
            <strong class="font-semibold text-foreground">{{ selected.length }}</strong>
            permission{{ selected.length === 1 ? '' : 's' }} selected across
            {{ groups.length }} group{{ groups.length === 1 ? '' : 's' }}.
        </p>
    </div>
</template>
