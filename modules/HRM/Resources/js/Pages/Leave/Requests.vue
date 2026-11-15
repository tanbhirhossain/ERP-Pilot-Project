<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import { CalendarPlus, ClipboardList, Plus } from 'lucide-vue-next';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import DataTable, { type DataTableColumn } from '@core/Components/DataTable.vue';
import ModuleNav from './ModuleNav.vue';
import { Button } from '@/components/ui/button';
import { formatDate, formatNumber } from '@core/Utils/format';
import type { FilterDef, PaginatorMeta } from '@core/types';

/**
 * Leave Requests — the list.
 *
 * One table, one job: find an application and open it. The decision is not made here. The old screen
 * put Approve and Reject buttons in every row, so a rejection was one mis-click away from a list that
 * does not show the employee's balance, their existing leave, or why they applied.
 */

interface LeaveRow {
  id: number;
  employee_id: string;
  employee: string;
  department: string | null;
  leave_type: string | null;
  leave_type_code: string | null;
  leave_type_color: string | null;
  from_date: string | null;
  to_date: string | null;
  days: number;
  is_half_day: boolean;
  reason: string | null;
  status: string;
  created_at: string | null;
  approved_at: string | null;
}

const props = defineProps<{
  leaves: { data: LeaveRow[]; meta: PaginatorMeta };
  canApprove: boolean;
  filterDefs: FilterDef[];
}>();

const columns: DataTableColumn[] = [
  { key: 'employee', label: 'Employee', sortable: true, cellClass: 'whitespace-nowrap' },
  { key: 'leave_type', label: 'Leave type', sortable: false, cellClass: 'whitespace-nowrap' },
  { key: 'period', label: 'Period', sortable: false, cellClass: 'whitespace-nowrap' },
  { key: 'days', label: 'Days', sortable: true, align: 'right', cellClass: 'whitespace-nowrap' },
  { key: 'status', label: 'Status', sortable: true, cellClass: 'whitespace-nowrap' },
  { key: 'created_at', label: 'Applied', sortable: true, cellClass: 'whitespace-nowrap' },
  { key: 'actions', label: '', sortable: false, align: 'right' },
];

const totals = computed<Record<string, string | number | null>>(() => {
  const sum = props.leaves.data.reduce((acc, row) => acc + Number(row.days ?? 0), 0);

  return {
    employee: `${formatNumber(props.leaves.meta.total)} applications`,
    days: `${formatNumber(Math.round(sum * 10) / 10)}d this page`,
    actions: null,
    period: null,
    leave_type: null,
    status: null,
    created_at: null,
  };
});

const statusTone = (status: string): string => ({
  Approved: 'bg-success/10 text-success ring-success/25',
  Pending: 'bg-warning/10 text-warning ring-warning/25',
  Rejected: 'bg-danger/10 text-danger ring-danger/25',
}[status] ?? 'bg-muted text-muted-foreground ring-border');

const open = (row: LeaveRow): void => router.visit(route('hrm.leaves.show', row.id));
</script>

<template>
  <AdminLayout :breadcrumbs="[{ title: 'Leave' }, { title: 'Requests' }]">
    <Head title="Leave requests" />

    <div class="space-y-4">
      <ModuleNav />

      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 class="flex items-center gap-2.5 text-xl font-bold tracking-tight">
            <span class="grid size-9 place-items-center rounded-xl bg-gradient-to-br from-primary to-primary/70 text-primary-foreground shadow-sm">
              <ClipboardList class="size-5" />
            </span>
            Leave requests
          </h1>
          <p class="mt-1 max-w-3xl text-sm text-muted-foreground">
            Every application, filtered and searchable. Open one to see the employee's balance position,
            the approval trail and what approving it would cost.
          </p>
        </div>
        <div class="flex gap-2">
          <Button size="sm" variant="outline" @click="router.visit(route('hrm.leaves.approvals'))">
            <CalendarPlus class="size-4" /> Approval queue
          </Button>
          <Button size="sm" @click="router.visit(route('hrm.leaves.create'))">
            <Plus class="size-4" /> New application
          </Button>
        </div>
      </div>

      <DataTable
        :columns="columns"
        :rows="leaves.data"
        :meta="leaves.meta"
        route-name="hrm.leaves.requests"
        title="Leave requests"
        :filter-defs="filterDefs"
        search-placeholder="Employee, employee ID or reason…"
        :default-sort="{ key: 'created_at', direction: 'desc' }"
        :totals="totals"
        totals-label="Page"
        empty-title="No applications match"
        empty-description="Clear the filters, or apply for leave from the button above."
      >
        <template #cell-employee="{ row }">
          <button type="button" class="group text-left" @click="open(row)">
            <span class="block text-[13px] font-semibold group-hover:text-primary group-hover:underline">{{ row.employee }}</span>
            <span class="block font-mono text-[11px] text-muted-foreground">{{ row.employee_id }}<template v-if="row.department"> · {{ row.department }}</template></span>
          </button>
        </template>

        <template #cell-leave_type="{ row }">
          <span class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] font-medium ring-1 ring-inset"
                :style="{ backgroundColor: (row.leave_type_color ?? '#94a3b8') + '1a', color: row.leave_type_color ?? '#64748b', borderColor: 'transparent' }">
            <span class="size-1.5 rounded-full" :style="{ backgroundColor: row.leave_type_color ?? '#94a3b8' }" />
            {{ row.leave_type_code ?? row.leave_type }}
          </span>
          <span class="ml-1.5 text-[11px] text-muted-foreground">{{ row.leave_type }}</span>
        </template>

        <template #cell-period="{ row }">
          <span class="tabular-nums text-[13px]">{{ formatDate(row.from_date) }}</span>
          <template v-if="row.from_date !== row.to_date">
            <span class="mx-1 text-muted-foreground">→</span>
            <span class="tabular-nums text-[13px]">{{ formatDate(row.to_date) }}</span>
          </template>
          <span v-if="row.is_half_day" class="ml-1.5 rounded bg-info/10 px-1.5 py-0.5 text-[10px] font-semibold text-info">half day</span>
        </template>

        <template #cell-days="{ row }">
          <span class="font-semibold tabular-nums">{{ formatNumber(row.days) }}d</span>
        </template>

        <template #cell-status="{ row }">
          <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium ring-1 ring-inset" :class="statusTone(row.status)">
            {{ row.status }}
          </span>
        </template>

        <template #cell-created_at="{ row }">
          <span class="text-xs text-muted-foreground">{{ formatDate(row.created_at) }}</span>
        </template>

        <template #cell-actions="{ row }">
          <div class="flex items-center justify-end gap-1">
            <Button size="sm" variant="ghost" class="h-7 px-2 text-[12px]" @click="open(row)">Open</Button>
            <Button
              v-if="row.status === 'Pending' && canApprove"
              size="sm"
              variant="ghost"
              class="h-7 px-2 text-[12px] text-primary"
              @click="router.visit(route('hrm.leaves.show', row.id))"
            >
              Decide
            </Button>
          </div>
        </template>
      </DataTable>

      <p class="text-xs text-muted-foreground">
        The days figure is what the application charges: {{ 'calendar days by default' }}, or working days for a type
        configured that way (weekends and holidays excluded). Half days count as 0.5.
      </p>
    </div>
  </AdminLayout>
</template>
