<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { FileText, Plus, Eye } from 'lucide-vue-next';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import DataTable, { type DataTableColumn } from '@core/Components/DataTable.vue';
import StatusBadge from '@core/Components/StatusBadge.vue';

import { formatCurrency, formatDate } from '@core/Utils/format';

const props = defineProps<{ orders?: any, receipts?: any, bills?: any }>();
const data = props.orders ?? props.receipts ?? props.bills;

/*
 * A payable list has to answer three questions on one line:
 * what is this bill, who do we owe, how much is still owed, and when was it due.
 *
 * The screen used to carry four facts — number, vendor, total, status — and then spend the rest of
 * the row on empty space. Every one of the missing facts was already in the payload
 * (`bill_date`, `due_date`, `paid_amount`, `payment_status`): the list was not short of data, it was
 * short of columns.
 */
const columns: DataTableColumn[] = [
  { key: 'bill_no', label: 'Bill', sortable: true, width: '9rem' },
  { key: 'vendor', label: 'Vendor' },
  { key: 'bill_date', label: 'Date', width: '8.5rem' },
  { key: 'total_amount', label: 'Total', align: 'right' },
  { key: 'due_amount', label: 'Outstanding', align: 'right' },
  { key: 'status', label: 'Status', align: 'center', sortable: true },
  { key: 'actions', label: '', align: 'right', exportable: false, width: '4.5rem' },
];

const rows = computed(() => (data?.data ?? []) as any[]);

const totals = computed(() => {
  const list = rows.value;
  const sum = (key: string) => list.reduce((carry, row) => carry + Number(row[key] ?? 0), 0);

  return {
    total: sum('total_amount'),
    outstanding: list.reduce(
      (carry, row) => carry + Math.max(0, Number(row.total_amount ?? 0) - Number(row.paid_amount ?? 0)),
      0,
    ),
  };
});

function tone(status: string) {
  if (['posted', 'paid', 'received', 'confirmed'].includes(status)) return 'success';
  if (['draft', 'partially_paid', 'partial'].includes(status)) return 'warning';
  if (['cancelled', 'void'].includes(status)) return 'danger';

  return 'neutral';
}

function outstanding(row: any) {
  return Math.max(0, Number(row.total_amount ?? 0) - Number(row.paid_amount ?? 0));
}

function daysOverdue(due: string | null | undefined): number | null {
  if (!due) return null;
  const dueDate = new Date(due);
  if (Number.isNaN(dueDate.getTime())) return null;
  const days = Math.floor((Date.now() - dueDate.getTime()) / 86_400_000);

  return days > 0 ? days : null;
}
</script>

<template>
  <AdminLayout :breadcrumbs="[{ title: 'Purchase' }, { title: 'Vendor Bills' }]">
    <Head title="Vendor Bills" />
    <PageHeader
      title="Vendor Bills"
      description="AP • GRN → Bill posted = AP 2100 Cr • Payment clears AP"
      :icon="FileText"
      :actions="[{ label: 'New Vendor Bill', route: 'purchase.bills.create', icon: Plus }]"
    >
      <DataTable
        route-name="purchase.bills.index"
        title="Vendor Bills"
        :columns="columns"
        :rows="rows"
        :meta="data?.meta"
        search-placeholder="Search bill no or vendor…"
      >
        <template #cell-bill_no="{ row }">
          <Link
            :href="route('purchase.bills.show', row.id)"
            class="text-foreground font-mono text-xs font-semibold hover:underline"
          >
            {{ row.bill_no }}
          </Link>
        </template>

        <template #cell-vendor="{ row }">
          <div class="flex flex-col leading-tight">
            <span class="truncate text-sm font-medium">{{ row.vendor?.name || '—' }}</span>
            <span v-if="row.vendor_reference" class="text-muted-foreground mt-0.5 truncate text-xs">
              ref {{ row.vendor_reference }}
            </span>
          </div>
        </template>

        <template #cell-bill_date="{ row }">
          <div class="flex flex-col leading-tight">
            <span class="text-sm">{{ formatDate(row.bill_date) }}</span>
            <span v-if="row.due_date" class="text-muted-foreground mt-0.5 text-xs">
              due {{ formatDate(row.due_date) }}
            </span>
          </div>
        </template>

        <template #cell-total_amount="{ row }">
          <div class="flex flex-col items-end leading-tight">
            <span class="font-mono text-sm font-semibold">{{ formatCurrency(Number(row.total_amount)) }}</span>
            <span
              v-if="Number(row.paid_amount) > 0"
              class="text-muted-foreground mt-0.5 inline-flex items-center gap-1 text-xs"
            >
              paid <span class="tabular-nums">{{ formatCurrency(Number(row.paid_amount)) }}</span>
            </span>
          </div>
        </template>

        <template #cell-due_amount="{ row }">
          <span
            class="font-mono text-sm font-semibold"
            :class="outstanding(row) > 0 ? 'text-foreground' : 'text-muted-foreground'"
          >
            {{ formatCurrency(outstanding(row)) }}
          </span>
        </template>

        <template #cell-status="{ row }">
          <StatusBadge :label="row.status" :tone="tone(row.status)" />
          <span v-if="daysOverdue(row.due_date)" class="text-danger mt-1 block text-[11px] font-medium">
            {{ daysOverdue(row.due_date) }}d overdue
          </span>
        </template>

        <template #cell-actions="{ row }">
          <Link
            :href="route('purchase.bills.show', row.id)"
            class="hover:bg-accent grid size-8 place-items-center rounded-md"
            :aria-label="`Open bill ${row.bill_no}`"
          >
            <Eye class="size-4" />
          </Link>
        </template>
      </DataTable>

      <!--
        Built from elements, not loose text: Vue's default whitespace handling ("condense") deletes a
        whitespace-only text node that contains a newline, so `{{ amount }} still owed` written across
        two template lines renders as "৳76,470.96still owed".
      -->
      <p v-if="rows.length" class="text-muted-foreground mt-3 flex flex-wrap items-center gap-x-1.5 text-xs">
        <span>On this page</span>
        <span class="text-foreground font-medium tabular-nums">{{ formatCurrency(totals.total) }}</span>
        <span>billed ·</span>
        <span class="text-foreground font-semibold tabular-nums">{{ formatCurrency(totals.outstanding) }}</span>
        <span>still owed</span>
      </p>
    </PageHeader>
  </AdminLayout>
</template>
