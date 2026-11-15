<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { CalendarClock, Check, Clock, FileStack, Layers, PlusCircle, X } from 'lucide-vue-next';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import DataTable, { type DataTableColumn } from '@core/Components/DataTable.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import ModuleNav from './ModuleNav.vue';
import EmployeeSearchSelect from '../../Components/EmployeeSearchSelect.vue';
import EmployeeMultiSearchSelect from '../../Components/EmployeeMultiSearchSelect.vue';
import type { Paginator } from '@core/types';
import { confirmDialog, promptDialog } from '@core/Composables/useConfirm';
import { useToast } from '@core/Composables/useToast';
import { formatCurrency } from '@core/Utils/format';

/**
 * The register — every advance, with its schedule summarised on the row.
 *
 * The monthly staging form lives here rather than on the overview because staging changes the rows
 * you are about to look at: an installment that was `scheduled` becomes `allocated` for the month,
 * and the register's "remaining" column is the one that moves. A form that writes and a table that
 * reads the same data belong on the same page; a form that writes and a dashboard that summarises it
 * do not, because the dashboard is then stale the moment the form is submitted.
 */

interface Row {
  id: number;
  employee_id: string;
  employee_name?: string | null;
  principal_amount: number | string;
  installment_count: number;
  installment_amount: number | string;
  installments_count: number;
  paid_installments_count: number;
  remaining_installments_count: number;
  first_due_date: string;
  current_approval_step: number;
  approval_steps: { label: string; role: string }[];
  requested_by: number | null;
  status: string;
  purpose?: string | null;
}

interface EmployeeOption { value: string; label: string; description?: string }
interface AllocationBatch {
  pay_year: number;
  pay_month: number;
  scope: 'selected' | 'all' | 'automatic';
  status: 'open' | 'locked';
  allocations_count: number;
}

const props = defineProps<{
  loans: Paginator<Row>;
  employeeOptions: EmployeeOption[];
  allocationPeriod: { year: number; month: number };
  allocationBatch: AllocationBatch | null;
  currentUserId: number;
  canApproveLoans: boolean;
  canManage: boolean;
}>();

const toast = useToast();
const money = (value: number | string | null | undefined): string => formatCurrency(Number(value ?? 0));

/* ------------------------------ allocation ------------------------------- */

const month = ref(`${props.allocationPeriod.year}-${String(props.allocationPeriod.month).padStart(2, '0')}`);
const allocation = useForm({
  pay_year: props.allocationPeriod.year,
  pay_month: props.allocationPeriod.month,
  scope: 'all' as 'single' | 'selected' | 'all',
  employee_id: '',
  employee_ids: [] as string[],
});
const selectedScopeText = computed(() =>
  allocation.scope === 'all'
    ? 'every employee with a due advance'
    : allocation.scope === 'single'
      ? 'one employee'
      : `${allocation.employee_ids.length} selected employee(s)`,
);

watch(month, (value) => {
  const [year, monthNumber] = value.split('-').map(Number);
  if (year && monthNumber) {
    allocation.pay_year = year;
    allocation.pay_month = monthNumber;
  }
});

const batchForPeriod = computed(() =>
  props.allocationBatch && props.allocationBatch.pay_year === allocation.pay_year && props.allocationBatch.pay_month === allocation.pay_month
    ? props.allocationBatch
    : null,
);

async function allocateMonthly(): Promise<void> {
  if (allocation.scope === 'single' && !allocation.employee_id) {
    toast.warning('Select an employee.');
    return;
  }
  if (allocation.scope === 'selected' && !allocation.employee_ids.length) {
    toast.warning('Select at least one employee.');
    return;
  }
  const period = `${allocation.pay_year}-${String(allocation.pay_month).padStart(2, '0')}`;
  const proceed = await confirmDialog({
    title: 'Stage installments for payroll',
    description: `Stage at most one due installment per active advance for ${selectedScopeText.value} in ${period}? This has to happen before that month's salary sheet is created, because the sheet snapshots what it will deduct.`,
    confirmLabel: 'Stage installments',
    danger: false,
  });
  if (!proceed) return;
  allocation.post(route('payroll.loans.allocate-monthly'), { preserveScroll: true });
}

/* ------------------------------- decisions ------------------------------- */

const approvalError = ref('');
const actionInProgressId = ref<number | null>(null);

function approve(id: number): void {
  approvalError.value = '';
  actionInProgressId.value = id;
  router.post(route('payroll.loans.approve', id), {}, {
    preserveScroll: true,
    onError: (errors) => { approvalError.value = Object.values(errors).join(' '); },
    onFinish: () => { actionInProgressId.value = null; },
  });
}

async function reject(id: number): Promise<void> {
  const asked = await promptDialog({
    title: 'Reject advance request',
    description: 'The reason is written to the audit trail and shown on the advance.',
    inputLabel: 'Rejection reason',
    inputPlaceholder: 'Why is this request being rejected?',
    inputType: 'textarea',
    confirmLabel: 'Reject request',
  });
  if (asked === null) return;
  if (asked.trim().length < 3) {
    toast.warning('A rejection reason needs at least three characters.');
    return;
  }
  approvalError.value = '';
  actionInProgressId.value = id;
  router.post(route('payroll.loans.reject', id), { comments: asked.trim() }, {
    preserveScroll: true,
    onError: (errors) => { approvalError.value = Object.values(errors).join(' '); },
    onFinish: () => { actionInProgressId.value = null; },
  });
}

/* --------------------------------- table --------------------------------- */

const columns: DataTableColumn[] = [
  { key: 'employee_id', label: 'Employee' },
  { key: 'principal_amount', label: 'Advance', sortable: true, align: 'right' },
  { key: 'installment_count', label: 'Schedule', align: 'right' },
  { key: 'recovered', label: 'Recovered', align: 'right' },
  { key: 'first_due_date', label: 'First due' },
  { key: 'current_approval_step', label: 'Approval' },
  { key: 'status', label: 'Status' },
  { key: 'actions', label: '', align: 'right' },
];

const filterDefs = computed(() => [
  {
    key: 'status',
    label: 'Status',
    kind: 'select' as const,
    options: [
      { value: 'pending', label: 'Pending' },
      { value: 'active', label: 'Active' },
      { value: 'completed', label: 'Repaid' },
      { value: 'rejected', label: 'Rejected' },
      { value: 'cancelled', label: 'Cancelled' },
    ],
  },
]);

/** Reads the first ten characters of a date string; never constructs a Date, so no timezone is applied. */
function plainDate(value: string | null | undefined): string {
  if (!value) return '—';
  return String(value).slice(0, 10);
}

/** How far through its schedule a row is, as a bar. */
function progress(row: Row): number {
  const total = Number(row.installments_count || row.installment_count || 0);
  return total > 0 ? Math.round((Number(row.paid_installments_count || 0) / total) * 100) : 0;
}
</script>

<template>
  <AdminLayout :breadcrumbs="[{ title: 'Payroll' }, { title: 'Staff advances', href: route('payroll.loans.index') }, { title: 'Register' }]">
    <Head title="Advance register" />

    <div class="space-y-4">
      <ModuleNav :can-manage="canManage" />

      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 class="flex items-center gap-2.5 text-xl font-bold tracking-tight">
            <span class="grid size-9 place-items-center rounded-xl bg-gradient-to-br from-primary to-primary/70 text-primary-foreground shadow-sm">
              <FileStack class="size-5" />
            </span>
            Advance register
          </h1>
          <p class="mt-1 text-sm text-muted-foreground">
            Every request and every approved advance, with its schedule, what payroll has recovered, and the approval stage it sits at.
          </p>
        </div>
        <div class="flex flex-wrap gap-2">
          <Button size="sm" variant="outline" @click="router.visit(route('payroll.loans.schedule'))">
            <CalendarClock class="size-4" />Schedule
          </Button>
          <Button v-if="canManage" size="sm" @click="router.visit(route('payroll.loans.create'))">
            <PlusCircle class="size-4" />New request
          </Button>
        </div>
      </div>

      <!-- Monthly staging -->
      <section v-if="canManage" class="rounded-xl border bg-card shadow-sm">
        <div class="flex flex-wrap items-start justify-between gap-3 border-b px-5 py-4">
          <div>
            <h2 class="flex items-center gap-2 text-sm font-semibold"><Layers class="size-4 text-muted-foreground" />Stage installments for a payroll month</h2>
            <p class="mt-0.5 max-w-3xl text-xs text-muted-foreground">
              Each advance contributes at most <strong>one</strong> installment per month, so a missed month is recovered gradually rather than all at once as one oversized deduction. Staging must happen before the salary sheet is created — the sheet snapshots what it will deduct, so a sheet that already exists cannot be given a new installment afterwards.
            </p>
          </div>
          <span
            v-if="batchForPeriod"
            class="flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-medium"
            :class="batchForPeriod.status === 'locked' ? 'bg-muted text-muted-foreground' : 'bg-sky-500/10 text-sky-600 dark:text-sky-400'"
          >
            <component :is="batchForPeriod.status === 'locked' ? Check : Clock" class="size-3" />
            {{ batchForPeriod.status === 'locked' ? 'Locked by payroll' : 'Open' }} · {{ batchForPeriod.allocations_count }} staged
          </span>
        </div>

        <form class="grid gap-4 p-5 lg:grid-cols-2" @submit.prevent="allocateMonthly">
          <div class="space-y-2">
            <Label>Payroll month</Label>
            <Input v-model="month" type="month" />
            <p v-if="allocation.errors.period" class="text-xs text-destructive">{{ allocation.errors.period }}</p>
          </div>
          <div class="space-y-2">
            <Label>Scope</Label>
            <select v-model="allocation.scope" class="h-10 w-full rounded-md border bg-background px-3 text-sm">
              <option value="all">Every employee with a due advance</option>
              <option value="single">One employee</option>
              <option value="selected">Selected employees</option>
            </select>
          </div>
          <div v-if="allocation.scope === 'single'" class="space-y-2 lg:col-span-2">
            <Label>Employee</Label>
            <EmployeeSearchSelect v-model="allocation.employee_id" :options="employeeOptions" />
            <p v-if="allocation.errors.employee_id" class="text-xs text-destructive">{{ allocation.errors.employee_id }}</p>
          </div>
          <div v-if="allocation.scope === 'selected'" class="space-y-2 lg:col-span-2">
            <Label>Employees</Label>
            <EmployeeMultiSearchSelect v-model="allocation.employee_ids" :options="employeeOptions" />
            <p v-if="allocation.errors.employee_ids" class="text-xs text-destructive">{{ allocation.errors.employee_ids }}</p>
          </div>
          <p v-if="allocation.errors.allocation || allocation.errors.loan_installments" class="text-sm text-destructive lg:col-span-2">
            {{ allocation.errors.allocation || allocation.errors.loan_installments }}
          </p>
          <div class="flex flex-wrap items-center gap-3 lg:col-span-2">
            <Button :disabled="allocation.processing">
              {{ allocation.processing ? 'Staging…' : 'Stage installments' }}
            </Button>
            <p class="text-xs text-muted-foreground">Staging is idempotent for a month: staging twice does not deduct twice.</p>
          </div>
        </form>
      </section>

      <p v-if="approvalError" role="alert" class="rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive">
        {{ approvalError }}
      </p>

      <DataTable
        route-name="payroll.loans.register"
        title="Advance register"
        :columns="columns"
        :rows="loans.data"
        :meta="loans.meta"
        :filter-defs="filterDefs"
        search-placeholder="Search employee or ID…"
        empty-title="No advances found"
        empty-description="Adjust the filters, or raise a new request."
      >
        <template #cell-employee_id="{ row }">
          <button type="button" class="text-left hover:underline" @click="router.visit(route('payroll.loans.show', row.id))">
            <strong class="block leading-tight">{{ row.employee_name || row.employee_id }}</strong>
            <span class="block text-xs text-muted-foreground">{{ row.employee_id }}</span>
          </button>
        </template>

        <template #cell-principal_amount="{ row }">
          <span class="tabular-nums font-medium">{{ money(row.principal_amount) }}</span>
        </template>

        <template #cell-installment_count="{ row }">
          <span class="tabular-nums">{{ row.installment_count }} × {{ money(row.installment_amount) }}</span>
          <span class="block text-xs text-muted-foreground">{{ row.remaining_installments_count ?? row.installment_count }} left</span>
        </template>

        <template #cell-recovered="{ row }">
          <span class="flex items-center justify-end gap-2">
            <span class="h-1 w-14 overflow-hidden rounded-full bg-muted">
              <span class="block h-full rounded-full bg-emerald-500" :style="{ width: `${progress(row)}%` }" />
            </span>
            <span class="tabular-nums text-xs text-muted-foreground">{{ row.paid_installments_count || 0 }}/{{ row.installments_count || row.installment_count }}</span>
          </span>
        </template>

        <template #cell-first_due_date="{ row }">
          <!--
            The server sends `Y-m-d`. It used to send an ISO timestamp, and `new Date(...)` on the
            browser side shifted 2026-10-01 back to 30 September for anyone west of UTC — a
            date-only column must never be round-tripped through a timezone.
          -->
          <span class="tabular-nums">{{ plainDate(row.first_due_date) }}</span>
        </template>

        <template #cell-current_approval_step="{ row }">
          <span class="tabular-nums">
            {{ Math.min(Number(row.current_approval_step) + (row.status === 'pending' ? 1 : 0), row.approval_steps?.length || 1) }} / {{ row.approval_steps?.length || 1 }}
          </span>
          <span v-if="row.status === 'pending' && row.approval_steps?.[Number(row.current_approval_step)]" class="block text-xs text-muted-foreground">
            {{ row.approval_steps[Number(row.current_approval_step)].label }} · {{ row.approval_steps[Number(row.current_approval_step)].role }}
          </span>
        </template>

        <template #cell-status="{ row }">
          <span
            class="rounded px-1.5 py-px text-[10px] font-semibold uppercase tracking-wide"
            :class="{
              'bg-amber-500/10 text-amber-600 dark:text-amber-400': row.status === 'pending',
              'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400': row.status === 'active',
              'bg-sky-500/10 text-sky-600 dark:text-sky-400': row.status === 'completed',
              'bg-rose-500/10 text-rose-600 dark:text-rose-400': row.status === 'rejected',
              'bg-muted text-muted-foreground': row.status === 'cancelled',
            }"
          >{{ row.status }}</span>
        </template>

        <template #cell-actions="{ row }">
          <div class="flex justify-end gap-2">
            <template v-if="row.status === 'pending'">
              <template v-if="canApproveLoans">
                <Button size="sm" variant="outline" :disabled="actionInProgressId === Number(row.id) || Number(row.requested_by) === currentUserId" @click="reject(Number(row.id))">
                  <X class="size-3.5" />Reject
                </Button>
                <Button size="sm" :disabled="actionInProgressId === Number(row.id) || Number(row.requested_by) === currentUserId" @click="approve(Number(row.id))">
                  <Check class="size-3.5" />{{ actionInProgressId === Number(row.id) ? 'Approving…' : 'Approve ' + (row.approval_steps?.[Number(row.current_approval_step)]?.label || 'stage') }}
                </Button>
                <span v-if="Number(row.requested_by) === currentUserId" class="self-center text-xs text-muted-foreground">Own request</span>
              </template>
              <span v-else class="self-center text-xs text-muted-foreground">Approval permission required</span>
            </template>
            <Button v-else size="sm" variant="ghost" @click="router.visit(route('payroll.loans.show', row.id))">Statement</Button>
          </div>
        </template>
      </DataTable>
    </div>
  </AdminLayout>
</template>
