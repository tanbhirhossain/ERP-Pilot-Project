<script setup lang="ts">
/**
 * The quote-round register (F18).
 *
 * `quotes / lines` is the column that matters here: a sent round with no answers is a question nobody
 * has answered, and the buyer needs to see that before the due date, not after it.
 */
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Handshake, Plus, Eye } from 'lucide-vue-next';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import DataTable, { type DataTableColumn } from '@core/Components/DataTable.vue';
import StatusBadge from '@core/Components/StatusBadge.vue';

defineProps<{ rfqs: any }>();

const status = ref(new URLSearchParams(window.location.search).get('status') ?? '');

const columns: DataTableColumn[] = [
    { key: 'rfq_no', label: 'Round', sortable: true },
    { key: 'subject', label: 'Subject' },
    { key: 'due_on', label: 'Due', sortable: true },
    { key: 'answers', label: 'Answers', align: 'center' },
    { key: 'status', label: 'Status', align: 'center', sortable: true },
    { key: 'actions', label: '', align: 'right', exportable: false, width: '6rem' },
];

function apply(value: string) {
    status.value = value;
    router.get(route('purchase.rfqs.index'), value ? { status: value } : {}, { preserveState: true, replace: true });
}

function tone(s: string) {
    if (s === 'closed') return 'success';
    if (s === 'sent') return 'info';
    if (s === 'cancelled') return 'danger';

    return 'warning';
}

/** A round that is due and still unanswered deserves to look like it. */
function late(row: any) {
    return row.status === 'sent' && ! row.awarded && row.due_on
        && new Date(row.due_on).getTime() < new Date(new Date().toDateString()).getTime();
}
</script>
<template>
    <AdminLayout :breadcrumbs="[{ title: 'Purchase' }, { title: 'Quote Rounds' }]">
        <Head title="Quote Rounds (RFQ)" />
        <PageHeader
            title="Quote Rounds"
            description="Ask several vendors, compare on one screen, award and the purchase order is written for you."
            :icon="Handshake"
            :actions="[{ label: 'New Round', route: 'purchase.rfqs.create', icon: Plus }]"
        >
            <div class="flex flex-wrap items-center gap-2 pb-3">
                <select
                    :value="status"
                    class="h-8 rounded-md border bg-background px-2 text-sm"
                    aria-label="Filter rounds by status"
                    @change="apply(($event.target as HTMLSelectElement).value)"
                >
                    <option value="">Any status</option>
                    <option value="draft">Draft</option>
                    <option value="sent">Sent</option>
                    <option value="closed">Closed</option>
                    <option value="cancelled">Cancelled</option>
                </select>
            </div>
            <DataTable route-name="purchase.rfqs.index" title="Quote rounds" :columns="columns" :rows="rfqs.data" :meta="rfqs.meta" search-placeholder="Search RFQ- or subject...">
                <template #cell-rfq_no="{row}">
                    <span class="font-mono text-xs font-bold px-1.5 py-0.5 rounded bg-amber-500/10 text-amber-700">{{ row.rfq_no }}</span>
                </template>
                <template #cell-subject="{row}">
                    <div class="text-sm leading-tight">{{ row.subject }}</div>
                    <div v-if="row.requisition" class="text-xs text-muted-foreground">for {{ row.requisition }}</div>
                </template>
                <template #cell-due_on="{row}">
                    <span class="text-xs" :class="late(row) ? 'font-semibold text-destructive' : 'text-muted-foreground'">
                        {{ row.due_on || '—' }}
                        <span v-if="late(row)">· overdue</span>
                    </span>
                </template>
                <template #cell-answers="{row}">
                    <span class="font-mono text-sm" :class="row.quotes === 0 && row.status === 'sent' ? 'text-destructive' : ''">
                        {{ row.quotes }}/{{ row.lines }}
                    </span>
                </template>
                <template #cell-status="{row}">
                    <div class="flex justify-center gap-1">
                        <StatusBadge :label="row.status" :tone="tone(row.status)" />
                        <StatusBadge v-if="row.awarded" label="awarded" tone="success" />
                    </div>
                </template>
                <template #cell-actions="{row}">
                    <div class="flex justify-end">
                        <Link :href="route('purchase.rfqs.show', row.id)" class="size-8 grid place-items-center rounded-md hover:bg-accent" title="Open round">
                            <Eye class="size-4" />
                        </Link>
                    </div>
                </template>
            </DataTable>
        </PageHeader>
    </AdminLayout>
</template>
