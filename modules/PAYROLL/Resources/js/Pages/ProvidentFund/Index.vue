<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import {
  BookOpenCheck,
  CircleCheck,
  CircleAlert,
  History,
  Landmark,
  Printer,
  Settings2,
  UserPlus,
  Wallet,
} from 'lucide-vue-next';
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
import { printDocument } from '@core/Composables/usePrint';
import { formatCurrency } from '@core/Utils/format';

/**
 * Provident-fund control desk.
 *
 * Designed as a *ledger*, not a dashboard. A provident fund is a book of account balances that has to
 * survive an audit, so the page is built the way a passbook is: hairline rules, one accent colour,
 * figures on a tabular grid, and every label uppercase and small so the numbers carry the page.
 *
 * Two structural decisions worth stating, because they are what the previous screen got wrong:
 *
 *   1. **One monthly table, not two.** The old page rendered "contribution periods" and "monthly fund
 *      activity" as separate tables over the same months, so the same period appeared twice with
 *      different columns and neither was complete. They are one row of fact per month here.
 *   2. **The reconciliation is a status line, not four coloured cards.** Whether the fund balances is
 *      a yes/no with reasons; painting it across the middle of the page made an exception look like
 *      the normal state.
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
  accrued: number;
  banked: number;
  settled: number;
  paid_out: number;
  employee_side: number;
  employer_side: number;
  net: number;
  pending_withdrawals: number;
  last_period: string | null;
}

interface Cash {
  received: number;
  outstanding: number;
  paid_out: number;
  cash_position: number;
  liability: number;
  lines: number;
}

interface Activity {
  period: string;
  members: number;
  employee_side: number;
  employer_side: number;
  accrued: number;
  settled: number;
  total: number;
}

interface Period {
  sheet_id: number;
  period: string;
  status: string;
  employees: number;
  can_accrue: boolean;
  reason: string;
  posted_members: number;
  posted_value: number;
}

interface Drift {
  account_id: number;
  employee_id: string;
  employee_drift: number;
  employer_drift: number;
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
  cash: Cash;
  activity: Activity[];
  periods: Period[];
  reconciliation: Drift[];
  policies: Policy[];
  employeeOptions: SelectOption[];
  canManage: boolean;
}>();

const toast = useToast();

/* ------------------------------- formatting ------------------------------ */

const money = (value: number | string | null | undefined): string =>
  formatCurrency(Number(value ?? 0));

const monthLabel = (period: string): string => {
  if (!period) return '—';
  const [year, month] = period.split('-').map(Number);
  if (!year || !month) return period;
  return new Date(year, month - 1, 1).toLocaleDateString('en-GB', { month: 'short', year: 'numeric' });
};

/** A date, printed as the database holds it — never through a timezone. */
const plainDate = (value: string | null | undefined): string => {
  if (!value) return '—';
  const [datePart] = String(value).split('T');
  const [year, month, day] = datePart.split('-').map(Number);
  if (!year || !month || !day) return datePart;
  return new Date(year, month - 1, day).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
};

/* -------------------------------- derived -------------------------------- */

/**
 * One row of fact per month: the sheet that produced it, how much was recognised, and how much of
 * that has reached the fund. `activity` and `periods` are joined on the period, because they are two
 * halves of the same month and the old screen showed them as if they were different things.
 */
const monthlyLedger = computed(() => {
  const byPeriod = new Map<string, Activity>(props.activity.map((row) => [row.period, row]));

  return props.periods.map((period) => {
    const activity = byPeriod.get(period.period);

    return {
      ...period,
      members: activity?.members ?? 0,
      employee_side: activity?.employee_side ?? 0,
      employer_side: activity?.employer_side ?? 0,
      accrued: activity?.accrued ?? 0,
      settled: activity?.settled ?? 0,
      total: activity?.total ?? 0,
    };
  });
});

const collectedPercent = computed(() => {
  const liability = Number(props.cash.liability ?? 0);
  if (liability <= 0) return 0;
  return Math.max(0, Math.min(100, (Number(props.cash.received ?? 0) / liability) * 100));
});

const pendingPeriodCount = computed(() => props.periods.filter((p) => !p.can_accrue).length);

/** What is wrong, and what is merely worth knowing. Empty `problems` means a signable statement. */
const problems = computed(() => {
  const list: string[] = [];
  if (props.reconciliation.length > 0) {
    list.push(`${props.reconciliation.length} account(s) disagree with their ledger — rebuild balances before signing.`);
  }
  return list;
});

const notices = computed(() => {
  const list: string[] = [];
  if (props.summary.unallocated_employees > 0) {
    list.push(`${props.summary.unallocated_employees} active employee(s) hold no account and contribute nothing.`);
  }
  if (Number(props.cash.outstanding) > 0.009) {
    list.push(`${money(props.cash.outstanding)} recognised but not yet collected.`);
  }
  if (props.summary.pending_withdrawals > 0) {
    list.push(`${props.summary.pending_withdrawals} withdrawal request(s) awaiting a decision.`);
  }
  if (pendingPeriodCount.value > 0) {
    list.push(`${pendingPeriodCount.value} month(s) cannot be accrued until their sheet is released.`);
  }
  return list;
});

/** Page totals for the register footer — the fund's own total is in the header strip. */
const registerTotals = computed<Record<string, string>>(() => {
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
  if (allocation.scope === 'selected' && allocation.employee_ids.length === 0) {
    toast.warning('Select at least one employee.');
    return;
  }
  if (allocation.scope === 'single' && !allocation.employee_id) {
    toast.warning('Select an employee.');
    return;
  }
  if (!allocation.policy_id) {
    toast.warning('Create or select a policy first.');
    return;
  }

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

/* --------------------------------- accrual ------------------------------- */

const accrueForm = useForm({ pay_year: 0, pay_month: 0 });
const busyPeriod = ref<string | null>(null);

async function accrue(row: { period: string; members: number; employees: number }): Promise<void> {
  const [year, month] = row.period.split('-').map(Number);
  const proceed = await confirmDialog({
    title: `Accrue ${monthLabel(row.period)}`,
    description: `Recognise both sides of the fund for ${row.employees} employee(s). Posts one credit per member per side; re-running changes nothing. Cash does not move, so the liability rises and the bank balance does not.`,
    confirmLabel: 'Accrue contributions',
    danger: false,
  });
  if (!proceed) return;

  busyPeriod.value = `accrue:${row.period}`;
  accrueForm.pay_year = year;
  accrueForm.pay_month = month;
  accrueForm.post(route('payroll.pf.accrue'), {
    preserveScroll: true,
    onError: (errors) => toast.error(Object.values(errors).join(' ')),
    onFinish: () => { busyPeriod.value = null; },
  });
}

async function settle(period: string): Promise<void> {
  const proceed = await confirmDialog({
    title: `Settle ${monthLabel(period)}`,
    description: 'Mark this month\'s accruals settled: the cash has reached the fund. Member balances are unchanged — only the cash side moves.',
    confirmLabel: 'Mark settled',
    danger: false,
  });
  if (!proceed) return;

  busyPeriod.value = `settle:${period}`;
  router.post(route('payroll.pf.settle'), { period }, {
    preserveScroll: true,
    onFinish: () => { busyPeriod.value = null; },
  });
}

const backfilling = ref(false);
async function backfill(): Promise<void> {
  const proceed = await confirmDialog({
    title: 'Post historical contributions',
    description: 'Post every contribution already computed into an existing payroll slip, so the fund is correct for months that predate accrual. Idempotent; cash does not move.',
    confirmLabel: 'Post history',
    danger: false,
  });
  if (!proceed) return;

  backfilling.value = true;
  router.post(route('payroll.pf.backfill'), {}, {
    preserveScroll: true,
    onFinish: () => { backfilling.value = false; },
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

/* --------------------------------- print --------------------------------- */

function print(): void {
  printDocument();
}

const openMember = (id: number): void => { router.visit(route('payroll.pf.show', id)); };

/* -------------------------------- columns -------------------------------- */

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
  <AdminLayout :breadcrumbs="[{ title: 'Payroll' }, { title: 'Provident Fund' }]">
    <Head title="Provident Fund" />

    <!-- ============================== toolbar ============================== -->
    <header class="border-b pb-4">
      <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
          <h1 class="flex items-center gap-2 text-lg font-semibold tracking-tight">
            <Landmark class="size-[18px] text-muted-foreground" />
            Provident fund
          </h1>
          <p class="mt-0.5 text-[13px] leading-snug text-muted-foreground">
            Accrual basis — contributions are recognised when the payroll month is closed, settled when the cash is received.
          </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
          <Button v-if="canManage" size="sm" variant="outline" :disabled="backfilling" @click="backfill">
            <History class="size-3.5" />{{ backfilling ? 'Posting…' : 'Post history' }}
          </Button>
          <Button size="sm" variant="outline" @click="print">
            <Printer class="size-3.5" />Print
          </Button>
          <Button size="sm" variant="outline" @click="router.visit(route('payroll.pf.withdrawals.index'))">
            <Wallet class="size-3.5" />Withdrawals
            <span v-if="summary.pending_withdrawals" class="ml-1 rounded bg-amber-500/15 px-1.5 py-0.5 text-[10px] font-bold text-amber-700 dark:text-amber-400">{{ summary.pending_withdrawals }}</span>
          </Button>
        </div>
      </div>

      <!-- Balance strip: one row of figures, hairline dividers, no decoration. -->
      <dl class="mt-4 grid grid-cols-2 divide-y divide-border rounded-md border bg-card sm:grid-cols-3 sm:divide-x sm:divide-y-0 lg:grid-cols-5">
        <div class="px-4 py-3">
          <dt class="text-[10px] font-semibold uppercase tracking-[0.12em] text-muted-foreground">Fund liability</dt>
          <dd class="mt-1 text-[19px] font-semibold leading-none tabular-nums">{{ money(cash.liability) }}</dd>
          <dd class="mt-1 text-[11px] text-muted-foreground">{{ summary.accounts.toLocaleString() }} members</dd>
        </div>
        <div class="px-4 py-3">
          <dt class="text-[10px] font-semibold uppercase tracking-[0.12em] text-muted-foreground">Received</dt>
          <dd class="mt-1 text-[19px] font-semibold leading-none tabular-nums">{{ money(cash.received) }}</dd>
          <dd class="mt-1 text-[11px] text-muted-foreground">{{ collectedPercent.toFixed(1) }}% collected</dd>
        </div>
        <div class="px-4 py-3">
          <dt class="text-[10px] font-semibold uppercase tracking-[0.12em] text-muted-foreground">Outstanding</dt>
          <dd class="mt-1 text-[19px] font-semibold leading-none tabular-nums" :class="Number(cash.outstanding) > 0 ? 'text-amber-600 dark:text-amber-400' : ''">{{ money(cash.outstanding) }}</dd>
          <dd class="mt-1 text-[11px] text-muted-foreground">accrued, not banked</dd>
        </div>
        <div class="px-4 py-3">
          <dt class="text-[10px] font-semibold uppercase tracking-[0.12em] text-muted-foreground">Paid out</dt>
          <dd class="mt-1 text-[19px] font-semibold leading-none tabular-nums">{{ money(cash.paid_out) }}</dd>
          <dd class="mt-1 text-[11px] text-muted-foreground">withdrawals &amp; settlements</dd>
        </div>
        <div class="px-4 py-3">
          <dt class="text-[10px] font-semibold uppercase tracking-[0.12em] text-muted-foreground">Last accrued</dt>
          <dd class="mt-1 text-[19px] font-semibold leading-none">{{ summary.last_period ? monthLabel(summary.last_period) : '—' }}</dd>
          <dd class="mt-1 text-[11px] text-muted-foreground">{{ cash.lines.toLocaleString() }} ledger lines</dd>
        </div>
      </dl>

      <!-- Reconciliation: a status line with reasons, not four coloured cards. -->
      <div class="mt-2 rounded-md border text-[12px]" :class="problems.length ? 'border-rose-500/30 bg-rose-500/[0.04]' : 'bg-card'">
        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 px-3 py-2">
          <span v-if="problems.length === 0" class="flex items-center gap-1.5 font-medium text-emerald-700 dark:text-emerald-400">
            <CircleCheck class="size-3.5" />Balances reconcile to the ledger
          </span>
          <span v-else class="flex items-center gap-1.5 font-medium text-rose-700 dark:text-rose-400">
            <CircleAlert class="size-3.5" />{{ problems[0] }}
          </span>
          <span class="h-3 w-px bg-border" aria-hidden="true"></span>
          <span class="text-muted-foreground">Employee side <span class="ml-1 font-medium tabular-nums text-foreground">{{ money(summary.employee_side) }}</span></span>
          <span class="text-muted-foreground">Employer side <span class="ml-1 font-medium tabular-nums text-foreground">{{ money(summary.employer_side) }}</span></span>
          <span class="ml-auto text-[11px] text-muted-foreground">{{ cash.lines.toLocaleString() }} ledger entries</span>
        </div>
        <ul v-if="notices.length" class="flex flex-wrap gap-x-4 gap-y-1 border-t px-3 py-1.5 text-[11.5px] text-amber-700 dark:text-amber-400">
          <li v-for="(notice, i) in notices" :key="i" class="flex items-center gap-1.5">
            <span class="size-1 rounded-full bg-amber-500"></span>{{ notice }}
          </li>
        </ul>
      </div>
    </header>

    <div class="mt-5 grid gap-5 xl:grid-cols-[minmax(0,1fr)_320px]">
      <div class="min-w-0 space-y-5">
        <!-- ======================= monthly ledger ======================= -->
        <section class="rounded-md border bg-card" data-core-print>
          <div class="flex items-start justify-between gap-3 border-b px-4 py-3">
            <div>
              <h2 class="flex items-center gap-2 text-[13px] font-semibold"><BookOpenCheck class="size-4 text-muted-foreground" />Contribution register</h2>
              <p class="mt-0.5 text-[11px] leading-snug text-muted-foreground">
                One line per payroll month. A month is accrued only once its sheet is <strong>released</strong> — a
                <em>ready</em> sheet can still be recalculated, and a liability must never be recognised against figures that can move.
              </p>
            </div>
          </div>

          <div v-if="monthlyLedger.length === 0" class="px-4 py-12 text-center">
            <p class="text-[13px] font-medium">No payroll month exists yet</p>
            <p class="mt-1 text-[12px] text-muted-foreground">Run payroll for a month and release the sheet before accruing its fund.</p>
          </div>

          <div v-else class="overflow-x-auto">
            <table class="w-full text-[12.5px]">
              <thead>
                <tr class="border-b bg-muted/40 text-[10px] uppercase tracking-[0.1em] text-muted-foreground">
                  <th class="px-4 py-2 text-left font-semibold">Period</th>
                  <th class="px-2 py-2 text-left font-semibold">Sheet</th>
                  <th class="px-2 py-2 text-right font-semibold">Members</th>
                  <th class="px-2 py-2 text-right font-semibold">Employee share</th>
                  <th class="px-2 py-2 text-right font-semibold">Employer share</th>
                  <th class="px-2 py-2 text-right font-semibold">Accrued</th>
                  <th class="px-2 py-2 text-right font-semibold">Settled</th>
                  <th class="px-4 py-2 text-right font-semibold">Action</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-border/70">
                <tr v-for="row in monthlyLedger" :key="row.period" class="hover:bg-muted/30">
                  <td class="whitespace-nowrap px-4 py-2">
                    <span class="font-medium">{{ monthLabel(row.period) }}</span>
                    <span v-if="!row.can_accrue" class="mt-0.5 block max-w-[15rem] text-[10.5px] leading-tight text-muted-foreground">
                      {{ row.reason }}
                    </span>
                  </td>
                  <td class="px-2 py-2">
                    <span class="inline-flex items-center gap-1 text-[10px] font-semibold uppercase tracking-wide" :class="row.status === 'released' ? 'text-emerald-700 dark:text-emerald-400' : 'text-amber-700 dark:text-amber-400'">
                      <span class="size-1.5 rounded-full" :class="row.status === 'released' ? 'bg-emerald-500' : 'bg-amber-500'"></span>
                      {{ row.status }}
                    </span>
                  </td>
                  <td class="px-2 py-2 text-right tabular-nums">{{ (row.members || row.employees).toLocaleString() }}</td>
                  <td class="px-2 py-2 text-right tabular-nums">{{ money(row.employee_side) }}</td>
                  <td class="px-2 py-2 text-right tabular-nums">{{ money(row.employer_side) }}</td>
                  <td class="px-2 py-2 text-right tabular-nums">
                    <span :class="row.accrued > 0 ? 'text-amber-700 dark:text-amber-400' : 'text-muted-foreground'">{{ money(row.accrued) }}</span>
                  </td>
                  <td class="px-2 py-2 text-right tabular-nums">
                    <span :class="row.settled > 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-muted-foreground'">{{ money(row.settled) }}</span>
                  </td>
                  <td class="whitespace-nowrap px-4 py-2 text-right">
                    <div v-if="canManage" class="flex justify-end gap-1">
                      <Button v-if="row.can_accrue" size="sm" variant="outline" class="h-7 px-2 text-[11px]"
                              :disabled="busyPeriod === 'accrue:' + row.period" @click="accrue(row)">
                        {{ busyPeriod === 'accrue:' + row.period ? 'Accruing…' : (row.posted_members ? 'Re-run' : 'Accrue') }}
                      </Button>
                      <Button v-if="row.accrued > 0" size="sm" variant="ghost" class="h-7 px-2 text-[11px]"
                              :disabled="busyPeriod === 'settle:' + row.period" @click="settle(row.period)">
                        {{ busyPeriod === 'settle:' + row.period ? 'Settling…' : 'Settle' }}
                      </Button>
                      <span v-if="!row.can_accrue && row.accrued === 0" class="self-center text-[10.5px] text-muted-foreground">Awaiting release</span>
                    </div>
                    <span v-else class="text-[11px] text-muted-foreground">—</span>
                  </td>
                </tr>
              </tbody>
              <tfoot class="border-t bg-muted/40">
                <tr class="text-[12px] font-semibold">
                  <td class="px-4 py-2" colspan="3">Total recognised</td>
                  <td class="px-2 py-2 text-right tabular-nums">{{ money(summary.employee_side) }}</td>
                  <td class="px-2 py-2 text-right tabular-nums">{{ money(summary.employer_side) }}</td>
                  <td class="px-2 py-2 text-right tabular-nums">{{ money(cash.outstanding) }}</td>
                  <td class="px-2 py-2 text-right tabular-nums">{{ money(cash.received) }}</td>
                  <td class="px-4"></td>
                </tr>
              </tfoot>
            </table>
          </div>
        </section>

        <!-- ========================= register ========================== -->
        <section class="rounded-md border bg-card">
          <div class="flex items-start justify-between gap-3 border-b px-4 py-3">
            <div>
              <h2 class="text-[13px] font-semibold">Member accounts</h2>
              <p class="mt-0.5 text-[11px] text-muted-foreground">
                Every account and what it holds. Open a row for the member's statement — the ledger that produces these figures.
              </p>
            </div>
          </div>

          <div class="px-4 pb-4 pt-3">
            <DataTable
              route-name="payroll.pf.index"
              title="Provident Fund member register"
              :columns="columns"
              :rows="accounts.data"
              :meta="accounts.meta"
              :default-sort="{ key: 'employee_name', direction: 'asc' }"
              :totals="registerTotals"
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
                  <span class="block text-[12.5px] font-medium leading-tight">{{ row.employee_name || row.employee?.full_name || row.employee_id }}</span>
                  <span class="block font-mono text-[10.5px] leading-tight text-muted-foreground">{{ row.employee_id }}<template v-if="row.employee?.designation"> · {{ row.employee.designation }}</template></span>
                </button>
              </template>
              <template #cell-policy="{ row }">
                <span class="text-[12px] text-muted-foreground">{{ row.policy?.name || '—' }}</span>
              </template>
              <template #cell-eligible_from="{ row }">
                <span class="whitespace-nowrap tabular-nums text-muted-foreground">{{ plainDate(row.eligible_from) }}</span>
              </template>
              <template #cell-employee_balance="{ row }">
                <span class="tabular-nums">{{ money(row.employee_balance) }}</span>
              </template>
              <template #cell-employer_balance="{ row }">
                <span class="tabular-nums">{{ money(row.employer_balance) }}</span>
              </template>
              <template #cell-total_balance="{ row }">
                <span class="font-semibold tabular-nums">{{ money(row.total_balance) }}</span>
              </template>
              <template #cell-status="{ row }">
                <span class="text-[10px] font-semibold uppercase tracking-wide" :class="{
                  'text-emerald-700 dark:text-emerald-400': row.status === 'active',
                  'text-amber-700 dark:text-amber-400': row.status === 'suspended',
                  'text-muted-foreground': row.status === 'closed',
                }">{{ row.status }}</span>
              </template>
              <template #cell-actions="{ row }">
                <Button size="sm" variant="ghost" class="h-7 px-2 text-[11px]" @click="openMember(row.id)">Statement</Button>
              </template>
            </DataTable>
          </div>
        </section>
      </div>

      <!-- ============================ side rail =========================== -->
      <aside class="space-y-4">
        <!-- Allocation -->
        <section class="rounded-md border bg-card">
          <div class="flex items-center gap-2 border-b px-4 py-2.5">
            <UserPlus class="size-4 text-muted-foreground" />
            <h2 class="text-[13px] font-semibold">Enrolment</h2>
          </div>
          <div class="px-4 py-3">
            <dl class="grid grid-cols-3 gap-2 text-center">
              <div class="rounded border bg-muted/30 py-2">
                <dt class="text-[9.5px] font-semibold uppercase tracking-wider text-muted-foreground">Eligible</dt>
                <dd class="text-[15px] font-semibold tabular-nums">{{ (summary.accounts + summary.unallocated_employees).toLocaleString() }}</dd>
              </div>
              <div class="rounded border bg-muted/30 py-2">
                <dt class="text-[9.5px] font-semibold uppercase tracking-wider text-muted-foreground">Enrolled</dt>
                <dd class="text-[15px] font-semibold tabular-nums">{{ summary.accounts.toLocaleString() }}</dd>
              </div>
              <div class="rounded border py-2" :class="summary.unallocated_employees > 0 ? 'border-amber-500/40 bg-amber-500/[0.06]' : 'bg-muted/30'">
                <dt class="text-[9.5px] font-semibold uppercase tracking-wider text-muted-foreground">Missing</dt>
                <dd class="text-[15px] font-semibold tabular-nums" :class="summary.unallocated_employees > 0 ? 'text-amber-700 dark:text-amber-400' : ''">{{ summary.unallocated_employees.toLocaleString() }}</dd>
              </div>
            </dl>

            <form class="mt-3 space-y-2.5" @submit.prevent="allocate">
              <div class="space-y-1">
                <Label class="text-[11px]">Scope</Label>
                <select v-model="allocation.scope" class="h-8 w-full rounded border bg-background px-2 text-[12px]">
                  <option value="all">All eligible employees</option>
                  <option value="selected">Selected employees</option>
                  <option value="single">One employee</option>
                </select>
              </div>
              <div class="space-y-1">
                <Label class="text-[11px]">Policy</Label>
                <select v-model="allocation.policy_id" class="h-8 w-full rounded border bg-background px-2 text-[12px]">
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

        <!-- Policy -->
        <section class="rounded-md border bg-card">
          <button type="button" class="flex w-full items-center justify-between gap-2 px-4 py-2.5 text-left" @click="policyOpen = !policyOpen">
            <span class="flex items-center gap-2 text-[13px] font-semibold"><Settings2 class="size-4 text-muted-foreground" />Contribution policy</span>
            <span class="text-[11px] text-muted-foreground">{{ policyOpen ? 'Hide' : 'Edit' }}</span>
          </button>

          <div class="border-t px-4 py-3" :class="policyOpen ? '' : 'border-t-0 pt-0'">
            <ul class="space-y-1 text-[12px]">
              <li v-for="policy in policies" :key="policy.id" class="flex items-center justify-between gap-2">
                <span class="truncate">{{ policy.name }}</span>
                <span class="whitespace-nowrap tabular-nums text-muted-foreground">{{ policy.employee_contribution_percent }}% / {{ policy.employer_contribution_percent }}%</span>
              </li>
              <li v-if="policies.length === 0" class="text-muted-foreground">No policy configured.</li>
            </ul>

            <form v-if="policyOpen" class="mt-3 space-y-2.5 border-t pt-3" @submit.prevent="savePolicy">
              <div class="space-y-1">
                <Label class="text-[11px]">Policy name</Label>
                <Input v-model="policyForm.name" class="h-8 text-[12px]" />
                <p v-if="policyForm.errors.name" class="text-[11px] text-destructive">{{ policyForm.errors.name }}</p>
              </div>
              <div class="grid grid-cols-2 gap-2">
                <div class="space-y-1">
                  <Label class="text-[11px]">Employee %</Label>
                  <Input v-model.number="policyForm.employee_contribution_percent" type="number" min="0" max="100" step="0.01" class="h-8 text-[12px]" />
                </div>
                <div class="space-y-1">
                  <Label class="text-[11px]">Employer %</Label>
                  <Input v-model.number="policyForm.employer_contribution_percent" type="number" min="0" max="100" step="0.01" class="h-8 text-[12px]" />
                </div>
              </div>
              <div class="grid grid-cols-2 gap-2">
                <div class="space-y-1">
                  <Label class="text-[11px]">Min. service (mo)</Label>
                  <Input v-model.number="policyForm.minimum_service_months" type="number" min="0" class="h-8 text-[12px]" />
                </div>
                <div class="space-y-1">
                  <Label class="text-[11px]">Base</Label>
                  <select v-model="policyForm.contribution_base" class="h-8 w-full rounded border bg-background px-2 text-[12px]">
                    <option value="basic">Basic</option>
                    <option value="gross">Gross</option>
                    <option value="house_rent">House rent</option>
                    <option value="pf_eligible">Basic + PF benefits</option>
                  </select>
                </div>
              </div>
              <div class="space-y-1">
                <Label class="text-[11px]">Effective from</Label>
                <Input v-model="policyForm.effective_from" type="date" class="h-8 text-[12px]" />
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
