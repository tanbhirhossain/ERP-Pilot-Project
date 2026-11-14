<script setup lang="ts">
import { computed } from 'vue';
import { History, ArrowRight, User2 } from 'lucide-vue-next';
import { formatDateTime } from '@core/Utils/format';

interface Change {
    field: string;
    old: unknown;
    new: unknown;
}

interface TimelineEntry {
    id: number;
    description: string;
    event: string | null;
    causer: string;
    created_at: string | null;
    changes: Change[];
}

const props = withDefaults(defineProps<{ activities?: TimelineEntry[]; title?: string }>(), {
    activities: () => [],
    title: 'Activity',
});

const EVENT_TONE: Record<string, string> = {
    created: 'bg-emerald-500',
    updated: 'bg-sky-500',
    deleted: 'bg-rose-500',
};

function dotTone(event: string | null): string {
    return EVENT_TONE[event ?? ''] ?? 'bg-muted-foreground/50';
}

function humanField(field: string): string {
    return field.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

function display(value: unknown): string {
    if (value === null || value === undefined || value === '') return '—';
    if (typeof value === 'boolean') return value ? 'Yes' : 'No';
    if (typeof value === 'object') return JSON.stringify(value);
    return String(value);
}

const entries = computed(() => props.activities);
</script>

<template>
    <section class="bg-card rounded-xl border p-4 shadow-sm">
        <header class="mb-3 flex items-center gap-2">
            <History class="text-muted-foreground size-4" />
            <h3 class="text-sm font-semibold">{{ title }}</h3>
            <span class="text-muted-foreground ml-auto text-xs">{{ entries.length }} {{ entries.length === 1 ? 'event' : 'events' }}</span>
        </header>

        <ol v-if="entries.length" class="relative space-y-4 border-l pl-5">
            <li v-for="entry in entries" :key="entry.id" class="relative">
                <span class="absolute top-1 -left-[26px] size-2.5 rounded-full ring-4 ring-card" :class="dotTone(entry.event)" />
                <div class="flex flex-wrap items-baseline gap-x-2">
                    <p class="text-sm font-medium capitalize">{{ entry.description || entry.event || 'Changed' }}</p>
                    <time v-if="entry.created_at" class="text-muted-foreground text-xs">{{ formatDateTime(entry.created_at) }}</time>
                </div>
                <p class="text-muted-foreground mt-0.5 flex items-center gap-1 text-xs">
                    <User2 class="size-3" /> {{ entry.causer }}
                </p>
                <ul v-if="entry.changes.length" class="mt-1.5 space-y-1">
                    <li v-for="(change, i) in entry.changes" :key="i" class="flex flex-wrap items-center gap-1.5 text-xs">
                        <span class="text-muted-foreground font-medium">{{ humanField(change.field) }}:</span>
                        <span class="text-muted-foreground/70 line-through">{{ display(change.old) }}</span>
                        <ArrowRight class="text-muted-foreground/50 size-3" />
                        <span class="font-medium">{{ display(change.new) }}</span>
                    </li>
                </ul>
            </li>
        </ol>

        <p v-else class="text-muted-foreground py-4 text-center text-sm">No activity recorded yet.</p>
    </section>
</template>
