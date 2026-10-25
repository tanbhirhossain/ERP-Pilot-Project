<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Eye, ScrollText } from 'lucide-vue-next';
import DataTable, { type DataTableColumn } from '../../Components/DataTable.vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import type { ActivityRow, FilterDef, Paginator } from '../../types';
import { eventTone, formatDateTime } from '../../Utils/format';

interface Props {
    activities: Paginator<ActivityRow>;
    filterDefs?: FilterDef[];
}

const props = withDefaults(defineProps<Props>(), { filterDefs: () => [] });

const columns: DataTableColumn[] = [
    { key: 'created_at', label: 'When', sortable: true, width: '13rem' },
    { key: 'event', label: 'Action', sortable: true, width: '9rem' },
    { key: 'description', label: 'Description' },
    { key: 'causer', label: 'Performed by', width: '14rem' },
    { key: 'subject', label: 'Subject', width: '12rem' },
    { key: 'log_name', label: 'Log', sortable: true, width: '7rem' },
    { key: 'actions', label: '', align: 'right', exportable: false, width: '1rem' },
];
</script>

<template>
    <AdminLayout :breadcrumbs="[{ title: 'Audit & Compliance' }, { title: 'Audit Trail' }]">
        <Head title="Audit Trail" />

        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between print:hidden">
            <div class="flex items-start gap-3">
                <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-gradient-to-br from-emerald-500 to-teal-600 text-white shadow-md shadow-emerald-500/25">
                    <ScrollText class="size-5" />
                </span>
                <div>
                    <h1 class="text-xl font-bold tracking-tight">Audit trail</h1>
                    <p class="mt-0.5 text-sm text-muted-foreground">
                        JCI-grade record of who did what, when and from where — immutable and never editable.
                    </p>
                </div>
            </div>
        </div>

        <DataTable
            route-name="core.activities.index"
            export-resource="activities"
            title="Audit trail"
            search-placeholder="Search descriptions…"
            :columns="columns"
            :rows="props.activities.data"
            :meta="props.activities.meta"
            :filter-defs="filterDefs"
            empty-title="No audit entries match this view"
            empty-description="Widen the date range or clear filters to see more of the trail."
        >
            <template #cell-created_at="{ row }">
                <span class="whitespace-nowrap text-sm font-medium tabular-nums">{{ formatDateTime(row.created_at) }}</span>
            </template>

            <template #cell-event="{ row }">
                <span
                    class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium capitalize"
                    :class="eventTone(row.event).chip"
                >
                    <span class="size-1.5 rounded-full" :class="eventTone(row.event).dot" />
                    {{ (row.event ?? 'unknown').replace('_', ' ') }}
                </span>
            </template>

            <template #cell-description="{ row }">
                <span class="line-clamp-2">{{ row.description }}</span>
            </template>

            <template #cell-causer="{ row }">
                <div v-if="row.causer" class="flex min-w-0 items-center gap-2">
                    <span class="grid size-7 shrink-0 place-items-center rounded-md bg-gradient-to-br from-indigo-500 to-violet-600 text-[10px] font-bold text-white">
                        {{ row.causer.name.split(/\s+/).map((part: string) => part[0]).slice(0, 2).join('').toUpperCase() }}
                    </span>
                    <span class="truncate text-sm font-medium">{{ row.causer.name }}</span>
                </div>
                <span v-else class="text-sm italic text-muted-foreground">System / anonymous</span>
            </template>

            <template #cell-subject="{ row }">
                <span v-if="row.subject" class="text-sm">
                    <span class="text-muted-foreground">{{ row.subject.type }}</span>
                    <span class="font-medium"> #{{ row.subject.id }}</span>
                    <span class="block truncate text-xs text-muted-foreground">{{ row.subject.label }}</span>
                </span>
                <span v-else class="text-sm text-muted-foreground">—</span>
            </template>

            <template #cell-log_name="{ row }">
                <span class="rounded-md border bg-muted/50 px-2 py-0.5 text-xs font-medium">{{ row.log_name ?? '—' }}</span>
            </template>

            <template #cell-actions="{ row }">
                <div class="flex items-center justify-end">
                    <Link
                        :href="route('core.activities.show', row.id)"
                        class="inline-flex size-8 items-center justify-center rounded-md text-muted-foreground transition hover:bg-accent hover:text-accent-foreground"
                        :aria-label="`View audit entry ${row.id}`"
                        title="Inspect entry"
                    >
                        <Eye class="size-4" />
                    </Link>
                </div>
            </template>
        </DataTable>
    </AdminLayout>
</template>
