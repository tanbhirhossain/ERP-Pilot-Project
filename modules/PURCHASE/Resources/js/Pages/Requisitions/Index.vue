<script setup lang="ts">
/**
 * The requisition register (F18).
 *
 * The chips above the grid are not decoration: "submitted" is the queue someone with the approve grant
 * has to clear, so it is one click away rather than a search for `status:submitted` in a box.
 */
import { Head, Link, router } from '@inertiajs/vue3';
import { ClipboardList, Plus, Eye, PencilLine } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import DataTable, { type DataTableColumn } from '@core/Components/DataTable.vue';
import StatusBadge from '@core/Components/StatusBadge.vue';
import { formatCurrency } from '@core/Utils/format';

const props = defineProps<{
  requisitions: any;
  statusCounts?: Record<string, number>;
  canManage?: boolean;
  canApprove?: boolean;
}>();

const columns: DataTableColumn[] = [
  { key: 'req_no', label: 'Req No', sortable: true },
  { key: 'title', label: 'Wanted' },
  { key: 'requester', label: 'Requested by' },
  { key: 'need_by', label: 'Needed by', sortable: true },
  { key: 'estimated_amount', label: 'Estimate', align: 'right' },
  { key: 'status', label: 'Status', align: 'center', sortable: true },
  { key: 'actions', label: '', align: 'right', exportable: false, width: '6rem' },
];

const STATUS = ['draft', 'submitted', 'approved', 'rejected', 'converted', 'cancelled'] as const;
const active = ref<string>('');

const counts = computed<Record<string, number>>(() => props.statusCounts ?? {});
const total = computed(() => Object.values(counts.value).reduce((a, b) => a + Number(b), 0));

function pick(status: string) {
  active.value = active.value === status ? '' : status;
  router.get(route('purchase.requisitions.index'), active.value ? { status: active.value } : {}, {
    preserveState: true,
    replace: true,
  });
}

function tone(s: string) {
  if (['approved', 'converted'].includes(s)) return 'success';
  if (s === 'submitted') return 'info';
  if (['rejected', 'cancelled'].includes(s)) return 'danger';
  return 'warning';
}

function overdue(row: any) {
  return row.status !== 'converted' && row.status !== 'cancelled' && row.need_by
    && new Date(row.need_by).getTime() < new Date(new Date().toDateString()).getTime();
}
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'Purchase'},{title:'Requisitions'}]">
    <Head title="Purchase Requisitions" />
    <PageHeader
      title="Purchase Requisitions"
      description="Internal demand — approve it, then buy it. Nothing here moves stock or money."
      :icon="ClipboardList"
      :actions="[{ label:'New Requisition', route:'purchase.requisitions.create', icon: Plus }]"
    >
      <div class="flex flex-wrap gap-1.5 pb-3">
        <button
          class="px-2.5 py-1 rounded-full border text-xs transition-colors"
          :class="active === '' ? 'bg-primary text-primary-foreground border-primary' : 'hover:bg-accent'"
          @click="pick('')"
        >All <span class="opacity-70">{{ total }}</span></button>
        <button
          v-for="s in STATUS.filter((x) => (counts[x] ?? 0) > 0)"
          :key="s"
          class="px-2.5 py-1 rounded-full border text-xs capitalize transition-colors"
          :class="active === s ? 'bg-primary text-primary-foreground border-primary' : 'hover:bg-accent'"
          @click="pick(s)"
        >{{ s }} <span class="opacity-70">{{ counts[s] }}</span></button>
      </div>
      <DataTable route-name="purchase.requisitions.index" title="Requisitions" :columns="columns" :rows="requisitions.data" :meta="requisitions.meta" search-placeholder="Search REQ- or title...">
        <template #cell-req_no="{row}">
          <span class="font-mono text-xs font-bold px-1.5 py-0.5 rounded bg-amber-500/10 text-amber-700">{{ row.req_no }}</span>
        </template>
        <template #cell-title="{row}">
          <div class="text-sm leading-tight">{{ row.title }}</div>
          <div class="text-xs text-muted-foreground">{{ row.requester || '—' }}{{ row.warehouse ? ' → ' + row.warehouse : '' }}</div>
        </template>
        <template #cell-need_by="{row}">
          <span class="text-xs" :class="overdue(row) ? 'text-red-600 font-semibold' : 'text-muted-foreground'">
            {{ row.need_by || '—' }}
          </span>
        </template>
        <template #cell-estimated_amount="{row}">
          <span class="font-mono font-bold">{{ formatCurrency(Number(row.estimated_amount)) }}</span>
        </template>
        <template #cell-status="{row}"><StatusBadge :label="row.status" :tone="tone(row.status)" /></template>
        <template #cell-actions="{row}">
          <div class="flex justify-end gap-1">
            <Link :href="route('purchase.requisitions.show', row.id)" class="size-8 grid place-items-center rounded-md hover:bg-accent" title="Open">
              <Eye class="size-4" />
            </Link>
            <Link v-if="canManage && row.status === 'draft'" :href="route('purchase.requisitions.edit', row.id)" class="size-8 grid place-items-center rounded-md hover:bg-accent" title="Edit draft">
              <PencilLine class="size-4" />
            </Link>
          </div>
        </template>
      </DataTable>
    </PageHeader>
    <p v-if="canApprove" class="text-xs text-muted-foreground pt-2">
      Approving your own request is refused by the server — the register can be filtered, but the split stays.
    </p>
  </AdminLayout>
</template>
