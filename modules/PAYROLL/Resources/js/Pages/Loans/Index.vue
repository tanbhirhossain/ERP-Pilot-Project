<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import {LayoutDashboard, Plus} from 'lucide-vue-next';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import PageHeader from '../../../../../CORE/Resources/js/Components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import DataTable, { type DataTableColumn } from '../../../../../CORE/Resources/js/Components/DataTable.vue';
import EmployeeMultiSearchSelect from '../../Components/EmployeeMultiSearchSelect.vue';
import EmployeeSearchSelect from '../../Components/EmployeeSearchSelect.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Paginator } from '../../../../../CORE/Resources/js/types';
import { confirmDialog, promptDialog } from '../../../../../CORE/Resources/js/Composables/useConfirm';
import { useToast } from '../../../../../CORE/Resources/js/Composables/useToast';

interface Row {
  id: number;
  employee_id: string;
  employee_name?: string;
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
}
interface EmployeeOption { value: string; label: string; description?: string }
interface AllocationBatch { pay_year: number; pay_month: number; scope: 'selected' | 'all' | 'automatic'; status: 'open' | 'locked'; allocations_count: number }
const props = defineProps<{
  loans: Paginator<Row>;
  employeeOptions: EmployeeOption[];
  allocationPeriod: { year: number; month: number };
  allocationBatch: AllocationBatch | null;
  currentUserId: number;
  canApproveLoans: boolean;
}>();
const month = ref(`${props.allocationPeriod.year}-${String(props.allocationPeriod.month).padStart(2, '0')}`);
const allocation = useForm({ pay_year: props.allocationPeriod.year, pay_month: props.allocationPeriod.month, scope: 'all' as 'single' | 'selected' | 'all', employee_id: '', employee_ids: [] as string[] });
const selectedScopeText = computed(() => allocation.scope === 'all' ? 'all employees with due, approved loans' : allocation.scope === 'single' ? 'one employee' : `${allocation.employee_ids.length} selected employee(s)`);
const approvalError = ref('');
const actionInProgressId = ref<number | null>(null);
watch(month, (value) => {
  const [year, monthNumber] = value.split('-').map(Number);
  if (year && monthNumber) { allocation.pay_year = year; allocation.pay_month = monthNumber; }
});
const toast = useToast();

async function allocateMonthly() {
  if (allocation.scope === 'single' && !allocation.employee_id) { toast.warning('Select an employee.'); return; }
  if (allocation.scope === 'selected' && !allocation.employee_ids.length) { toast.warning('Select at least one employee.'); return; }
  const period = `${allocation.pay_year}-${String(allocation.pay_month).padStart(2, '0')}`;
  const proceed = await confirmDialog({
    title: 'Allocate monthly installments',
    description: `Allocate at most one due 0% installment per active loan for ${selectedScopeText.value} in ${period}? This must be done before creating that month's salary sheet.`,
    confirmLabel: 'Allocate installments',
    danger: false,
  });
  if (!proceed) return;
  allocation.post(route('payroll.loans.allocate-monthly'), { preserveScroll: true });
}
function approve(id: number) {
  approvalError.value = '';
  actionInProgressId.value = id;
  router.post(route('payroll.loans.approve', id), {}, {
    preserveScroll: true,
    onError: (errors) => { approvalError.value = Object.values(errors).join(' '); },
    onFinish: () => { actionInProgressId.value = null; },
  });
}
async function reject(id: number) {
  const asked = await promptDialog({
    title: 'Reject loan request',
    description: 'The reason is written to the audit trail.',
    inputLabel: 'Rejection reason',
    inputPlaceholder: 'Why is this request being rejected?',
    inputType: 'textarea',
    confirmLabel: 'Reject request',
  });
  if (asked === null) return;
  if (asked.trim().length < 3) { toast.warning('A rejection reason needs at least three characters.'); return; }
  const reason = asked;
  approvalError.value = '';
  actionInProgressId.value = id;
  router.post(route('payroll.loans.reject', id), { comments: reason.trim() }, {
    preserveScroll: true,
    onError: (errors) => { approvalError.value = Object.values(errors).join(' '); },
    onFinish: () => { actionInProgressId.value = null; },
  });
}
const columns: DataTableColumn[] = [
  { key: 'employee_id', label: 'Employee' },
  { key: 'principal_amount', label: 'Principal', sortable: true },
  { key: 'installment_count', label: 'Repayments' },
  { key: 'first_due_date', label: 'First due' },
  { key: 'current_approval_step', label: 'Approval' },
  { key: 'status', label: 'Status' },
  { key: 'actions', label: '', align: 'right', exportable: false },
];
</script>

<template>
  <AdminLayout :breadcrumbs="[{ title: 'Payroll' }, { title: 'Loans' }]">
    <Head title="Payroll loans" />
    <PageHeader title="Employee loans" description="Strictly interest-free loans, controlled approval, and audited monthly payroll recovery." :icon="LayoutDashboard" :actions="[{ label:'New loan request', route:'payroll.loans.create', icon: Plus }]">

    <section class="mt-5 rounded-xl border bg-card p-5">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 class="font-semibold">Monthly installment allocation</h2>
          <p class="mt-1 max-w-3xl text-sm text-muted-foreground">Allocate due loan installments to the selected payroll month for one employee, a selected group, or all employees. Each loan contributes at most one installment per month; overdue installments are recovered gradually, never stacked into one oversized deduction. Loan interest is fixed at 0%.</p>
        </div>
        <div v-if="allocationBatch && allocationBatch.pay_year === allocation.pay_year && allocationBatch.pay_month === allocation.pay_month" class="rounded-md bg-muted px-3 py-2 text-xs">
          {{ allocationBatch.status === 'locked' ? 'Locked' : 'Open' }} · {{ allocationBatch.scope === 'automatic' ? 'automatic payroll deductions' : `${allocationBatch.allocations_count} installment(s) staged` }}
        </div>
      </div>
      <form class="mt-4 grid gap-4 lg:grid-cols-2" @submit.prevent="allocateMonthly">
        <div class="space-y-2">
          <Label>Payroll month</Label>
          <Input v-model="month" type="month" />
          <p v-if="allocation.errors.period" class="text-xs text-destructive">{{ allocation.errors.period }}</p>
        </div>
        <div class="space-y-2">
          <Label>Allocation scope</Label>
          <select v-model="allocation.scope" class="h-10 w-full rounded-md border bg-background px-3 text-sm">
            <option value="single">One employee</option>
            <option value="selected">Selected employees</option>
            <option value="all">One-click: all employees with due loans</option>
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
        <div v-if="allocationBatch?.scope === 'selected' && allocationBatch.status === 'open'" class="rounded-md bg-blue-500/10 p-3 text-sm lg:col-span-2">
          This month already has an open selected-employee batch. Additional one/multi-employee allocations append to that batch. You can switch to all employees before payroll locks it.
        </div>
        <p v-if="allocation.errors.allocation || allocation.errors.loan_installments" class="text-sm text-destructive lg:col-span-2">{{ allocation.errors.allocation || allocation.errors.loan_installments }}</p>
        <div class="space-y-2 lg:col-span-2">
          <Button :disabled="allocation.processing">{{ allocation.processing ? 'Allocating…' : 'Allocate monthly installments' }}</Button>
          <p class="text-xs text-muted-foreground">Allocate before creating the salary sheet. A one/selected scope deducts only allocated employees; other employees’ due installments are intentionally omitted for this month. Payroll locks this month’s allocation snapshot when calculation begins; after that, the batch cannot be changed.</p>
        </div>
      </form>
    </section>

    <p v-if="approvalError" role="alert" class="mt-4 rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive">{{ approvalError }}</p>

    <DataTable
      class="mt-5"
      route-name="payroll.loans.index"
      title="Employee loans"
      :columns="columns"
      :rows="loans.data as unknown as Record<string, unknown>[]"
      :meta="loans.meta"
      :filter-defs="[{ key: 'status', label: 'Status', kind: 'select', options: [{ value: 'pending', label: 'Pending' }, { value: 'active', label: 'Active' }, { value: 'completed', label: 'Completed' }, { value: 'rejected', label: 'Rejected' }, { value: 'cancelled', label: 'Cancelled' }] }]"
    >
      <template #cell-employee_id="{ row }">
        <strong>{{ row.employee_name || row.employee_id }}</strong>
        <span class="block text-xs text-muted-foreground">{{ row.employee_id }}</span>
      </template>
      <template #cell-principal_amount="{ row }">{{ Number(row.principal_amount).toLocaleString() }}</template>
      <template #cell-installment_count="{ row }">
        {{ row.installment_count }} × {{ Number(row.installment_amount).toLocaleString() }}
        <span class="block text-xs text-muted-foreground">{{ row.paid_installments_count || 0 }} paid · {{ row.remaining_installments_count ?? row.installment_count }} remaining</span>
      </template>
      <template #cell-current_approval_step="{ row }">
        <span>{{ Math.min(Number(row.current_approval_step) + (row.status === 'pending' ? 1 : 0), row.approval_steps?.length || 1) }} / {{ row.approval_steps?.length || 1 }}</span>
        <span v-if="row.status === 'pending' && row.approval_steps?.[Number(row.current_approval_step)]" class="block text-xs text-muted-foreground">{{ row.approval_steps[Number(row.current_approval_step)].label }} · {{ row.approval_steps[Number(row.current_approval_step)].role }}</span>
      </template>
      <template #cell-status="{ row }"><span class="capitalize">{{ row.status }}</span></template>
      <template #cell-actions="{ row }">
        <div class="flex justify-end gap-2">
          <template v-if="row.status === 'pending'">
            <template v-if="canApproveLoans">
              <Button size="sm" variant="outline" :disabled="actionInProgressId === Number(row.id) || Number(row.requested_by) === currentUserId" @click="reject(Number(row.id))">Reject</Button>
              <Button size="sm" :disabled="actionInProgressId === Number(row.id) || Number(row.requested_by) === currentUserId" @click="approve(Number(row.id))">
                {{ actionInProgressId === Number(row.id) ? 'Approving…' : 'Approve ' + (row.approval_steps?.[Number(row.current_approval_step)]?.label || 'stage') }}
              </Button>
              <span v-if="Number(row.requested_by) === currentUserId" class="text-xs text-muted-foreground">Requester cannot decide their own loan.</span>
            </template>
            <span v-else class="text-xs text-muted-foreground">Loan approval permission required.</span>
          </template>
        </div>
      </template>
    </DataTable>
      </PageHeader>
  </AdminLayout>
</template>
