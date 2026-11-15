<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Landmark, Settings2, UserPlus, Users } from 'lucide-vue-next';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import DataTable, { type DataTableColumn } from '@core/Components/DataTable.vue';
import EmployeeSearchSelect from '../../Components/EmployeeSearchSelect.vue';
import EmployeeMultiSearchSelect from '../../Components/EmployeeMultiSearchSelect.vue';
import type { Paginator, SelectOption } from '@core/types';
import { confirmDialog } from '@core/Composables/useConfirm';
import { useToast } from '@core/Composables/useToast';
import { formatCurrency } from '@core/Utils/format';

/**
 * The member register.
 *
 * Who is in the fund, what each holds, and how to enrol the rest. Enrolment lives here rather than on
 * a dashboard because it is a write that changes the register you are looking at — and because the
 * numbers it reports (examined / created / already enrolled) are only meaningful next to the list.
 */

interface AccountRow {
  id: number;
  employee_id: string;
  employee_name: string | null;
  employee_balance: string | number;
  employer_balance: string | number;
  total_balance: number;
  status: string;
  eligible_from: string | null;
  policy?: { id: number; name: string } | null;
  employee?: { full_name?: string; designation?: string; status?: string } | null;
}

interface Summary {
  accounts: number;
  active_accounts: number;
  closed_accounts: number;
  unallocated_employees: number;
  employee_side: number;
  employer_side: number;
}

interface Policy {
  id: number;
  name: string;
  employee_contribution_percent: string | number;
  employer_contribution_percent: string | number;
  minimum_service_months: number;
  contribution_base: string;
}

const props = defineProps<{
  accounts: Paginator<AccountRow>;
  summary: Summary;
  policies: Policy[];
  employeeOptions: SelectOption[];
  canManage: boolean;
}>();

const toast = useToast();
const money = (value: number | string | null | undefined): string => formatCurrency(Number(value ?? 0));

const plainDate = (value: string | null | undefined): string => {
  if (!value) return '—';
  const [datePart] = String(value).split('T');
  const [year, month, day] = datePart.split('-').map(Number);
  if (!year || !month || !day) return datePart;
  return new Date(year, month - 1, day).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
};

/** Page totals: the footer adds up the rows in front of the operator, not the whole fund. */
const pageTotals = computed<Record<string, string>>(() => {
  const rows = props.accounts.data;
  const employee = rows.reduce((sum, row) => sum + Number(row.employee_balance ?? 0), 0);
  const employer = rows.reduce((sum, row) => sum + Number(row.employer_balance ?? 0), 0);

  return {
    employee_balance: money(employee),
    employer_balance: money(employer),
    total_balance: money(employee + employer),
  };
});

/* ------------------------------- allocation ------------------------------ */

const allocation = useForm({
  scope: 'all' as 'single' | 'selected' | 'all',
  policy_id: props.policies[0]?.id ?? '',
  employee_id: '',
  employee_ids: [] as string[],
});
const allocating = ref(false);

const scopeText = computed(() => {
  if (allocation.scope === 'all') return 'every active or on-leave employee without an account';
  if (allocation.scope === 'single') return 'one employee';
  return `${allocation.employee_ids.length} selected employee(s)`;
});

async function allocate(): Promise<void> {
  if (allocation.scope === 'selected' && allocation.employee_ids.length === 0) { toast.warning('Select at least one employee.'); return; }
  if (allocation.scope === 'single' && !allocation.employee_id) { toast.warning('Select an employee.'); return; }
  if (!allocation.policy_id) { toast.warning('Create or select a policy first.'); return; }

  const proceed = await confirmDialog({
    title: 'Allocate provident-fund accounts',
    description: `Create accounts for ${scopeText.value}? ${props.summary.unallocated_employees} employee(s) currently hold none. Existing accounts and balances are never modified.`,
    confirmLabel: 'Allocate accounts',
    danger: false,
  });
  if (!proceed) return;

  allocating.value = true;
  allocation.post(route('payroll.pf.allocate'), {
    preserveScroll: true,
    onError: (errors) => toast.error(Object.values(errors).join(' ')),
    onFinish: () => { allocating.value = false; },
  });
}

/* --------------------------------- policy -------------------------------- */

const policyOpen = ref(false);
const policyForm = useForm({
  name: 'Standard PF',
  employee_contribution_percent: 10,
  employer_contribution_percent: 10,
  minimum_service_months: 6,
  contribution_base: 'basic',
  effective_from: new Date().toISOString().slice(0, 10),
  effective_to: '',
});

function savePolicy(): void {
  policyForm.post(route('payroll.pf.policies.store'), {
    preserveScroll: true,
    onError: (errors) => toast.error(Object.values(errors).join(' ')),
    onSuccess: () => { policyOpen.value = false; },
  });
}

const openMember = (id: number): void => { router.visit(route('payroll.pf.show', id)); };

const columns: DataTableColumn[] = [
  { key: 'employee_id', label: 'Member' },
  { key: 'policy', label: 'Policy' },
  { key: 'eligible_from', label: 'Eligible from', sortable: true },
  { key: 'employee_balance', label: 'Employee share', align: 'right', sortable: true },
  { key: 'employer_balance', label: 'Employer share', align: 'right' },
  { key: 'total_balance', label: 'Total', align: 'right', sortable: true },
  { key: 'status', label: 'Status' },
  { key: 'actions', label: '', align: 'right', exportable: false },
];
</script>

<template>
  <AdminLayout :breadcrumbs="[{ title: 'Payroll' }, { title: 'Provident Fund', href: route('payroll.pf.dashboard') }, { title: 'Members' }]">
    <Head title="PF members" />

    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="flex items-center gap-2.5 text-xl font-bold tracking-tight">
          <span class="grid size-9 place-items-center rounded-xl bg-gradient-to-br from-primary to-primary/70 text-primary-foreground shadow-sm">
            <Users class="size-5" />
          </span>
          Fund members
        </h1>
        <p class="mt-1 text-sm text-muted-foreground">Every account, what it holds, and enrolment for the employees still outside the fund.</p>
      </div>
      <Button size="sm" variant="outline" @click="router.visit(route('payroll.pf.index'))">
        <Landmark class="size-4" />Contribution register
      </Button>
    </div>

    <div class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <div class="rounded-xl border bg-card p-5 shadow-sm">
        <p class="text-xs font-medium uppercase tracking-wider text-muted-foreground">Enrolled</p>
        <p class="mt-1.5 text-2xl font-bold tabular-nums tracking-tight">{{ summary.accounts.toLocaleString() }}</p>
        <p class="mt-1 text-xs text-muted-foreground">{{ summary.active_accounts }} active · {{ summary.closed_accounts }} closed</p>
      </div>
      <div class="rounded-xl border bg-card p-5 shadow-sm">
        <p class="text-xs font-medium uppercase tracking-wider text-muted-foreground">Without an account</p>
        <p class="mt-1.5 text-2xl font-bold tabular-nums tracking-tight" :class="summary.unallocated_employees > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400'">
          {{ summary.unallocated_employees.toLocaleString() }}
        </p>
        <p class="mt-1 text-xs text-muted-foreground">active employees contributing nothing</p>
      </div>
      <div class="rounded-xl border bg-card p-5 shadow-sm">
        <p class="text-xs font-medium uppercase tracking-wider text-muted-foreground">Employee share</p>
        <p class="mt-1.5 text-2xl font-bold tabular-nums tracking-tight">{{ money(summary.employee_side) }}</p>
        <p class="mt-1 text-xs text-muted-foreground">members' own money</p>
      </div>
      <div class="rounded-xl border bg-card p-5 shadow-sm">
        <p class="text-xs font-medium uppercase tracking-wider text-muted-foreground">Employer share</p>
        <p class="mt-1.5 text-2xl font-bold tabular-nums tracking-tight">{{ money(summary.employer_side) }}</p>
        <p class="mt-1 text-xs text-muted-foreground">matched contribution</p>
      </div>
    </div>

    <div class="mt-4 grid gap-4 xl:grid-cols-[minmax(0,1fr)_340px]">
      <!-- Register -->
      <section class="min-w-0 rounded-xl border bg-card shadow-sm">
        <div class="border-b px-5 py-4">
          <h2 class="text-sm font-semibold">Member register</h2>
          <p class="mt-0.5 text-xs text-muted-foreground">Open a row for the member's statement — the ledger that produces these figures.</p>
        </div>
        <div class="p-5">
          <DataTable
            route-name="payroll.pf.members"
            title="Provident Fund member register"
            :columns="columns"
            :rows="accounts.data"
            :meta="accounts.meta"
            :default-sort="{ key: 'employee_name', direction: 'asc' }"
            :totals="pageTotals"
            totals-label="Page total"
            search-placeholder="Search name, employee ID or policy…"
            empty-title="No members yet"
            empty-description="Allocate accounts to bring employees into the fund."
            :filter-defs="[
              { key: 'status', label: 'Status', kind: 'select', options: [{ value: 'active', label: 'Active' }, { value: 'suspended', label: 'Suspended' }, { value: 'closed', label: 'Closed' }] },
              { key: 'balance', label: 'Balance', kind: 'select', options: [{ value: 'positive', label: 'Has a balance' }, { value: 'zero', label: 'Nothing accrued' }] },
            ]"
          >
            <template #cell-employee_id="{ row }">
              <button type="button" class="text-left hover:underline" @click="openMember(row.id)">
                <span class="block text-[13px] font-medium leading-tight">{{ row.employee_name || row.employee?.full_name || row.employee_id }}</span>
                <span class="block font-mono text-[11px] leading-tight text-muted-foreground">{{ row.employee_id }}<template v-if="row.employee?.designation"> · {{ row.employee.designation }}</template></span>
              </button>
            </template>
            <template #cell-policy="{ row }">
              <span class="text-[12px] text-muted-foreground">{{ row.policy?.name || '—' }}</span>
            </template>
            <template #cell-eligible_from="{ row }">
              <span class="whitespace-nowrap tabular-nums text-muted-foreground">{{ plainDate(row.eligible_from) }}</span>
            </template>
            <template #cell-employee_balance="{ row }"><span class="tabular-nums">{{ money(row.employee_balance) }}</span></template>
            <template #cell-employer_balance="{ row }"><span class="tabular-nums">{{ money(row.employer_balance) }}</span></template>
            <template #cell-total_balance="{ row }"><span class="font-semibold tabular-nums">{{ money(row.total_balance) }}</span></template>
            <template #cell-status="{ row }">
              <span class="rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide" :class="{
                'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400': row.status === 'active',
                'bg-amber-500/10 text-amber-700 dark:text-amber-400': row.status === 'suspended',
                'bg-muted text-muted-foreground': row.status === 'closed',
              }">{{ row.status }}</span>
            </template>
            <template #cell-actions="{ row }">
              <Button size="sm" variant="ghost" class="h-7 px-2 text-[11px]" @click="openMember(row.id)">Statement</Button>
            </template>
          </DataTable>
        </div>
      </section>

      <!-- Enrolment + policy -->
      <aside class="space-y-4">
        <section class="rounded-xl border bg-card shadow-sm">
          <div class="flex items-center gap-2 border-b px-5 py-4">
            <UserPlus class="size-4 text-muted-foreground" />
            <h2 class="text-sm font-semibold">Enrol employees</h2>
          </div>
          <div class="p-5">
            <dl class="grid grid-cols-3 gap-2 text-center">
              <div class="rounded-lg border bg-muted/30 py-2">
                <dt class="text-[9.5px] font-semibold uppercase tracking-wider text-muted-foreground">Eligible</dt>
                <dd class="text-base font-semibold tabular-nums">{{ (summary.accounts + summary.unallocated_employees).toLocaleString() }}</dd>
              </div>
              <div class="rounded-lg border bg-muted/30 py-2">
                <dt class="text-[9.5px] font-semibold uppercase tracking-wider text-muted-foreground">Enrolled</dt>
                <dd class="text-base font-semibold tabular-nums">{{ summary.accounts.toLocaleString() }}</dd>
              </div>
              <div class="rounded-lg border py-2" :class="summary.unallocated_employees > 0 ? 'border-amber-500/40 bg-amber-500/[0.06]' : 'bg-muted/30'">
                <dt class="text-[9.5px] font-semibold uppercase tracking-wider text-muted-foreground">Missing</dt>
                <dd class="text-base font-semibold tabular-nums" :class="summary.unallocated_employees > 0 ? 'text-amber-700 dark:text-amber-400' : ''">{{ summary.unallocated_employees.toLocaleString() }}</dd>
              </div>
            </dl>

            <form class="mt-4 space-y-2.5" @submit.prevent="allocate">
              <div class="space-y-1">
                <Label class="text-[11px]">Scope</Label>
                <select v-model="allocation.scope" class="h-9 w-full rounded-md border bg-background px-2.5 text-[12px]">
                  <option value="all">All eligible employees</option>
                  <option value="selected">Selected employees</option>
                  <option value="single">One employee</option>
                </select>
              </div>
              <div class="space-y-1">
                <Label class="text-[11px]">Policy</Label>
                <select v-model="allocation.policy_id" class="h-9 w-full rounded-md border bg-background px-2.5 text-[12px]">
                  <option value="">Select a policy</option>
                  <option v-for="policy in policies" :key="policy.id" :value="policy.id">
                    {{ policy.name }} · {{ policy.employee_contribution_percent }}%/{{ policy.employer_contribution_percent }}%
                  </option>
                </select>
                <p v-if="allocation.errors.policy_id" class="text-[11px] text-destructive">{{ allocation.errors.policy_id }}</p>
              </div>
              <div v-if="allocation.scope === 'single'" class="space-y-1">
                <Label class="text-[11px]">Employee</Label>
                <EmployeeSearchSelect v-model="allocation.employee_id" :options="employeeOptions" />
                <p v-if="allocation.errors.employee_id" class="text-[11px] text-destructive">{{ allocation.errors.employee_id }}</p>
              </div>
              <div v-if="allocation.scope === 'selected'" class="space-y-1">
                <Label class="text-[11px]">Employees</Label>
                <EmployeeMultiSearchSelect v-model="allocation.employee_ids" :options="employeeOptions" />
                <p v-if="allocation.errors.employee_ids" class="text-[11px] text-destructive">{{ allocation.errors.employee_ids }}</p>
              </div>
              <Button class="w-full" size="sm" :disabled="allocating || !canManage">
                {{ allocating ? 'Allocating…' : 'Allocate accounts' }}
              </Button>
              <p class="text-[10.5px] leading-snug text-muted-foreground">
                Existing accounts, balances and history are never modified. The result reports how many employees were examined.
              </p>
            </form>
          </div>
        </section>

        <section class="rounded-xl border bg-card shadow-sm">
          <button type="button" class="flex w-full items-center justify-between gap-2 px-5 py-4 text-left" @click="policyOpen = !policyOpen">
            <span class="flex items-center gap-2 text-sm font-semibold"><Settings2 class="size-4 text-muted-foreground" />Contribution policy</span>
            <span class="text-[11px] text-muted-foreground">{{ policyOpen ? 'Hide' : 'Edit' }}</span>
          </button>

          <div class="px-5 pb-5" :class="policyOpen ? '' : 'pt-0'">
            <ul class="space-y-1.5 text-[12.5px]">
              <li v-for="policy in policies" :key="policy.id" class="flex items-center justify-between gap-2 rounded-lg bg-muted/40 px-2.5 py-1.5">
                <span class="truncate">{{ policy.name }}</span>
                <span class="whitespace-nowrap tabular-nums text-muted-foreground">{{ policy.employee_contribution_percent }}% / {{ policy.employer_contribution_percent }}%</span>
              </li>
              <li v-if="policies.length === 0" class="text-muted-foreground">No policy configured.</li>
            </ul>

            <form v-if="policyOpen" class="mt-4 space-y-2.5 border-t pt-4" @submit.prevent="savePolicy">
              <div class="space-y-1">
                <Label class="text-[11px]">Policy name</Label>
                <Input v-model="policyForm.name" class="h-9 text-[12px]" />
                <p v-if="policyForm.errors.name" class="text-[11px] text-destructive">{{ policyForm.errors.name }}</p>
              </div>
              <div class="grid grid-cols-2 gap-2">
                <div class="space-y-1">
                  <Label class="text-[11px]">Employee %</Label>
                  <Input v-model.number="policyForm.employee_contribution_percent" type="number" min="0" max="100" step="0.01" class="h-9 text-[12px]" />
                </div>
                <div class="space-y-1">
                  <Label class="text-[11px]">Employer %</Label>
                  <Input v-model.number="policyForm.employer_contribution_percent" type="number" min="0" max="100" step="0.01" class="h-9 text-[12px]" />
                </div>
              </div>
              <div class="grid grid-cols-2 gap-2">
                <div class="space-y-1">
                  <Label class="text-[11px]">Min. service (mo)</Label>
                  <Input v-model.number="policyForm.minimum_service_months" type="number" min="0" class="h-9 text-[12px]" />
                </div>
                <div class="space-y-1">
                  <Label class="text-[11px]">Base</Label>
                  <select v-model="policyForm.contribution_base" class="h-9 w-full rounded-md border bg-background px-2.5 text-[12px]">
                    <option value="basic">Basic</option>
                    <option value="gross">Gross</option>
                    <option value="house_rent">House rent</option>
                    <option value="pf_eligible">Basic + PF benefits</option>
                  </select>
                </div>
              </div>
              <div class="space-y-1">
                <Label class="text-[11px]">Effective from</Label>
                <Input v-model="policyForm.effective_from" type="date" class="h-9 text-[12px]" />
              </div>
              <Button class="w-full" size="sm" variant="outline" :disabled="policyForm.processing || !canManage">Save policy</Button>
            </form>

            <p v-if="!policyOpen" class="mt-2 text-[10.5px] leading-snug text-muted-foreground">
              Rates apply to new payroll calculations; posted accruals keep the figures they were recognised with.
            </p>
          </div>
        </section>
      </aside>
    </div>
  </AdminLayout>
</template>
