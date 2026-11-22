<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import {
  AlertTriangle,
  ArrowRight,
  Banknote,
  CalendarDays,
  ClipboardCheck,
  FileStack,
  Landmark,
  ListChecks,
  PieChart,
  Printer,
  Receipt,
  RefreshCw,
  Users,
  Wallet,
} from 'lucide-vue-next';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import DataTable, { type DataTableColumn } from '@core/Components/DataTable.vue';
import StatusBadge from '@core/Components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import ModuleNav from './ModuleNav.vue';
import { confirmDialog } from '@core/Composables/useConfirm';
import { useToast } from '@core/Composables/useToast';
import { formatCurrency, formatDateTime } from '@core/Utils/format';
import type { Paginator } from '@core/types';

/**
 * One payroll month, inspected.
 *
 * The order of this page is the order the questions get asked, and it is deliberate:
 *
 *   1. what am I paying for  — the four figures, summed from slips
 *   2. does it hold together — the reconciliation strip, incl. the sheet's cached columns
 *   3. where did it go       — departments, with the drill-down one tap away
 *   4. can I release it      — the checklist, which is the same data the release desk reads
 *   5. who exactly           — the slips, searchable and sortable
 *
 * The old page answered only (5) and put the release button next to a sentence asking the operator to
 * review things they had no way to review. Everything above the table exists so that sentence can be
 * obeyed.
 */

interface Slip {
  id: number;
  employee_id: string;
  employee_name: string;
  department: string | null;
  gross_salary: number | string;
  total_deductions: number | string;
  net_salary: number | string;
  payment_status: string;
}

interface Check {
  key: string;
  label: string;
  ok: boolean;
  detail: string;
  blocking: boolean;
}

interface Context {
  period: string;
  period_start: string;
  period_end: string;
  sheet: Record<string, any>;
  aggregate: { slips: number; gross: number; deductions: number; net: number; tax: number };
  payment: { paid: number; paid_amount: number; held: number; unpaid: number };
  variance: { gross: number; deductions: number; net: number };
  cutlist: { id: number; status: string; generated_at: string | null; approved_at: string | null; items: number } | null;
  journal: { id: number; journal_no: string; journal_date: string; status: string; total_debit: number } | null;
  released_at: string | null;
  advice: { id: number; advice_no: string; status: string; total_amount: number } | null;
}

interface Departments {
  rows: Array<{ department: string; slips: number; gross: number; deductions: number; net: number; tax: number; headcount_pct: number; net_pct: number; average_net: number }>;
  totals: { departments: number; slips: number; gross: number; deductions: number; net: number; tax: number; average_net: number };
}

const props = defineProps<{
  sheet: {
    id: number;
    pay_year: number;
    pay_month: number;
    status: string;
    employee_count: number;
    processed_count: number;
    include_punishments: boolean;
    failure_message: string | null;
    gross_total: string;
    deduction_total: string;
    net_total: string;
  };
  context: Context;
  readiness: { checks: Check[]; blocking_failures: number; can_release_data: boolean; status: string };
  departmentSummary: Departments;
  slips: Paginator<Slip>;
  periodExclusions: Array<{ employee_id: string; employee_name: string | null; reason: string; created_by_name: string | null; created_at: string | null }>;
  canRelease: boolean;
  canRefreshCutlist: boolean;
  payrollWarnings: Array<{ employee_id: string; employee_name: string | null; department: string | null; joining_date: string | null; reason: string }>;
  releaseAudit: { actor_id: number | null; occurred_at: string } | null;
}>();

const toast = useToast();
const money = (value: number | string | null | undefined): string => formatCurrency(Number(value ?? 0));

const agg = computed(() => props.context.aggregate);
const payment = computed(() => props.context.payment);

const statusTone = computed<'neutral' | 'info' | 'success' | 'warning' | 'danger'>(() => ({
  ready: 'warning',
  released: 'success',
  failed: 'danger',
  queued: 'info',
  processing: 'info',
}[props.sheet.status] as 'neutral' | 'info' | 'success' | 'warning' | 'danger' ?? 'neutral'));

/**
 * The sheet's own columns against the sum of its slips.
 *
 * Payroll writes `gross_total` and friends at the end of a run. Anything that rewrites a slip
 * afterwards — the tax re-pricing this database just had, a re-open, a correction — leaves those
 * columns describing a payroll that no longer exists. Showing the difference is better than choosing
 * one of the two and hiding the other: an operator who sees a variance knows to re-price or re-run,
 * and an auditor who never sees one cannot tell which screen lied.
 */
const sheetColumns = computed(() => ({
  gross: Number(props.sheet.gross_total ?? 0),
  deductions: Number(props.sheet.deduction_total ?? 0),
  net: Number(props.sheet.net_total ?? 0),
}));

const variance = computed(() => ({
  gross: Number(props.context.variance.gross ?? 0),
  deductions: Number(props.context.variance.deductions ?? 0),
  net: Number(props.context.variance.net ?? 0),
}));

const hasVariance = computed(() => Math.abs(variance.value.gross) + Math.abs(variance.value.deductions) + Math.abs(variance.value.net) > 0.01);

const blocking = computed(() => props.readiness.checks.filter((check) => check.blocking && !check.ok));
const advisory = computed(() => props.readiness.checks.filter((check) => !check.blocking && !check.ok));

const release = async (): Promise<void> => {
  const proceed = await confirmDialog({
    title: `Release ${props.context.period}?`,
    description: `This turns payment on for ${agg.value.slips.toLocaleString()} payslip(s) worth ${money(agg.value.net)}. Slips can then be marked paid individually or by bank advice, and the accrual can be posted to the ledger. Releasing is a one-way action.`,
    confirmLabel: 'Release the month',
    danger: false,
  });
  if (!proceed) return;
  router.post(route('payroll.sheets.release', props.sheet.id), {}, { preserveScroll: true });
};

const generateCutlist = (): void => {
  router.post(route('payroll.cutlists.generate'), { pay_year: props.sheet.pay_year, pay_month: props.sheet.pay_month }, { preserveScroll: true });
};

const slipColumns: DataTableColumn[] = [
  { key: 'employee_name', label: 'Employee', sortable: true },
  { key: 'department', label: 'Department' },
  { key: 'gross_salary', label: 'Gross', align: 'right', sortable: true },
  { key: 'total_deductions', label: 'Deductions', align: 'right', sortable: true },
  { key: 'net_salary', label: 'Net pay', align: 'right', sortable: true },
  { key: 'payment_status', label: 'Payment', align: 'center' },
  { key: 'actions', label: '', align: 'right', exportable: false },
];
</script>

<template>
  <AdminLayout
    :breadcrumbs="[
      { title: 'Payroll' },
      { title: 'Salary sheets', href: route('payroll.sheets.index') },
      { title: context.period },
    ]"
  >
    <Head :title="`Salary sheet — ${context.period}`" />

    <div class="space-y-4">
      <ModuleNav />

      <!-- Header -->
      <PageHeader
        :title="context.period"
        :icon="CalendarDays"
        :description="`${agg.slips.toLocaleString()} payslip(s) · ${context.period_start} to ${context.period_end} · ${sheet.include_punishments ? 'punishments included' : 'punishments excluded'}${context.released_at ? ' · released ' + formatDateTime(context.released_at) : ''}`"
      >
        <template #badge>
          <StatusBadge :label="sheet.status" :tone="statusTone" dot />
        </template>
        <template #actions>
          <Button size="sm" variant="outline" @click="router.visit(route('payroll.sheets.departments', sheet.id))">
            <PieChart class="size-4" />Departments
          </Button>
          <Button size="sm" variant="outline" @click="router.visit(route('payroll.sheets.release-check', sheet.id))">
            <ListChecks class="size-4" />Release desk
          </Button>
          <Button v-if="canRefreshCutlist" size="sm" variant="outline" @click="generateCutlist">
            <RefreshCw class="size-4" />Refresh cutlist
          </Button>
          <Link v-if="sheet.status === 'failed'" :href="route('payroll.sheets.create', { pay_year: sheet.pay_year, pay_month: sheet.pay_month })">
            <Button size="sm" variant="outline">Retry payroll</Button>
          </Link>
          <Button
            v-if="canRelease && sheet.status === 'ready'"
            size="sm"
            :disabled="blocking.length > 0"
            :title="blocking.length > 0 ? blocking[0]?.detail : undefined"
            @click="release"
          >
            <ClipboardCheck class="size-4" />Release month
          </Button>
        </template>
      </PageHeader>

      <div v-if="sheet.failure_message" class="flex items-start gap-2 rounded-xl border border-danger/30 bg-danger/5 p-3 text-sm text-danger">
        <AlertTriangle class="mt-0.5 size-4 shrink-0" />
        <span>{{ sheet.failure_message }}</span>
      </div>

      <!-- Figures -->
      <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-xl border bg-card p-5 shadow-sm">
          <div class="flex items-start justify-between">
            <span class="grid size-11 place-items-center rounded-xl bg-info/10 text-info"><Wallet class="size-5" /></span>
          </div>
          <p class="mt-3 text-2xl font-bold tabular-nums tracking-tight">{{ money(agg.net) }}</p>
          <p class="text-[13px] font-medium text-muted-foreground">Net payable</p>
          <p class="mt-1 text-xs text-muted-foreground">average {{ money(agg.net / Math.max(agg.slips, 1)) }} per slip</p>
        </div>

        <div class="rounded-xl border bg-card p-5 shadow-sm">
          <div class="flex items-start justify-between">
            <span class="grid size-11 place-items-center rounded-xl bg-violet-500/10 text-violet-600 dark:text-violet-400"><Banknote class="size-5" /></span>
          </div>
          <p class="mt-3 text-2xl font-bold tabular-nums tracking-tight">{{ money(agg.gross) }}</p>
          <p class="text-[13px] font-medium text-muted-foreground">Gross earnings</p>
          <p class="mt-1 text-xs text-muted-foreground">before every deduction</p>
        </div>

        <div class="rounded-xl border bg-card p-5 shadow-sm">
          <div class="flex items-start justify-between">
            <span class="grid size-11 place-items-center rounded-xl bg-warning/10 text-warning"><Receipt class="size-5" /></span>
          </div>
          <p class="mt-3 text-2xl font-bold tabular-nums tracking-tight">{{ money(agg.deductions) }}</p>
          <p class="text-[13px] font-medium text-muted-foreground">Total deductions</p>
          <p class="mt-1 text-xs text-muted-foreground">{{ agg.gross > 0 ? ((agg.deductions / agg.gross) * 100).toFixed(1) : '0.0' }}% of gross · incl. {{ money(agg.tax) }} tax</p>
        </div>

        <div class="rounded-xl border bg-card p-5 shadow-sm">
          <div class="flex items-start justify-between">
            <span class="grid size-11 place-items-center rounded-xl" :class="payment.paid > 0 ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' : 'bg-muted text-muted-foreground'"><Users class="size-5" /></span>
          </div>
          <p class="mt-3 text-2xl font-bold tabular-nums tracking-tight">{{ payment.paid.toLocaleString() }} <span class="text-base font-medium text-muted-foreground">/ {{ agg.slips.toLocaleString() }}</span></p>
          <p class="text-[13px] font-medium text-muted-foreground">Slips paid</p>
          <p class="mt-1 text-xs text-muted-foreground">
            {{ money(payment.paid_amount) }} paid<template v-if="payment.held > 0"> · {{ payment.held }} on hold</template>
          </p>
        </div>
      </div>

      <!-- Reconciliation + readiness -->
      <div class="grid items-start gap-4 xl:grid-cols-5">
        <section class="rounded-xl border bg-card shadow-sm xl:col-span-3">
          <div class="flex flex-wrap items-start justify-between gap-3 border-b px-5 py-4">
            <div>
              <h2 class="flex items-center gap-2 text-sm font-semibold"><Receipt class="size-4 text-muted-foreground" />Reconciliation</h2>
              <p class="mt-0.5 text-xs text-muted-foreground">The arithmetic of the month, and whether the sheet's own columns still describe it.</p>
            </div>
            <span
              class="flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-medium"
              :class="Math.abs((agg.gross - agg.deductions) - agg.net) < 0.01 ? 'bg-success/10 text-success' : 'bg-danger/10 text-danger'"
            >
              {{ Math.abs((agg.gross - agg.deductions) - agg.net) < 0.01 ? 'Balanced' : 'Out of balance' }}
            </span>
          </div>
          <dl class="divide-y">
            <div class="flex items-center justify-between px-5 py-2.5 text-sm">
              <dt class="text-muted-foreground">Gross earnings</dt>
              <dd class="font-medium tabular-nums">{{ money(agg.gross) }}</dd>
            </div>
            <div class="flex items-center justify-between px-5 py-2.5 text-sm">
              <dt class="text-muted-foreground">Less deductions (PF, loan, tax, recurring, attendance)</dt>
              <dd class="font-medium tabular-nums">− {{ money(agg.deductions) }}</dd>
            </div>
            <div class="flex items-center justify-between bg-muted/40 px-5 py-3">
              <dt class="text-sm font-semibold">Net payable</dt>
              <dd class="font-mono text-sm font-bold tabular-nums">{{ money(agg.net) }}</dd>
            </div>
            <div class="flex items-center justify-between px-5 py-2.5 text-sm">
              <dt class="text-muted-foreground">Sheet columns (written by the run)</dt>
              <dd class="tabular-nums text-muted-foreground">{{ money(sheetColumns.gross) }} / {{ money(sheetColumns.deductions) }} / <strong class="text-foreground">{{ money(sheetColumns.net) }}</strong></dd>
            </div>
            <div v-if="hasVariance" class="flex items-start justify-between gap-4 bg-warning/5 px-5 py-3">
              <dt class="text-sm">
                <span class="flex items-center gap-1.5 font-medium text-warning"><AlertTriangle class="size-3.5" />The columns are stale</span>
                <span class="mt-1 block text-xs text-muted-foreground">
                  The slips were re-priced after the run finished, so the sheet's cached totals no longer match them. The figures on
                  this page come from the slips, which is what will actually be paid. A re-run re-states the columns.
                </span>
              </dt>
              <dd class="shrink-0 text-right text-xs tabular-nums">
                <span class="block text-muted-foreground">gross {{ money(variance.gross) }}</span>
                <span class="block text-muted-foreground">deductions {{ money(variance.deductions) }}</span>
                <span class="block font-medium text-warning">net {{ money(variance.net) }}</span>
              </dd>
            </div>
            <div v-else class="flex items-center justify-between px-5 py-2.5 text-xs text-muted-foreground">
              <dt>The sheet's cached columns agree with its slips</dt>
              <dd class="text-success">no variance</dd>
            </div>
          </dl>
        </section>

        <section class="rounded-xl border bg-card shadow-sm xl:col-span-2">
          <div class="flex items-start justify-between gap-3 border-b px-5 py-4">
            <div>
              <h2 class="flex items-center gap-2 text-sm font-semibold"><ListChecks class="size-4 text-muted-foreground" />Before release</h2>
              <p class="mt-0.5 text-xs text-muted-foreground">{{ blocking.length }} blocking · {{ advisory.length }} advisory</p>
            </div>
            <button type="button" class="text-[11px] font-medium text-primary hover:underline" @click="router.visit(route('payroll.sheets.release-check', sheet.id))">
              Release desk →
            </button>
          </div>
          <ul class="divide-y">
            <li v-for="check in readiness.checks" :key="check.key" class="flex items-start gap-2.5 px-5 py-2.5">
              <span
                class="mt-1 grid size-4 shrink-0 place-items-center rounded-full"
                :class="check.ok ? 'bg-success/15 text-success' : check.blocking ? 'bg-danger/15 text-danger' : 'bg-warning/15 text-warning'"
              >
                <component :is="check.ok ? ClipboardCheck : AlertTriangle" class="size-2.5" />
              </span>
              <span class="min-w-0 flex-1">
                <span class="block text-[13px] font-medium leading-snug">{{ check.label }}</span>
                <span class="block text-[11px] leading-snug text-muted-foreground">{{ check.detail }}</span>
              </span>
              <span
                v-if="!check.ok"
                class="shrink-0 rounded px-1.5 py-px text-[10px] font-semibold uppercase tracking-wide"
                :class="check.blocking ? 'bg-danger/10 text-danger' : 'bg-warning/10 text-warning'"
              >{{ check.blocking ? 'Block' : 'Note' }}</span>
            </li>
          </ul>
        </section>
      </div>

      <!-- Departments -->
      <section class="rounded-xl border bg-card shadow-sm">
        <div class="flex flex-wrap items-start justify-between gap-3 border-b px-5 py-4">
          <div>
            <h2 class="flex items-center gap-2 text-sm font-semibold"><PieChart class="size-4 text-muted-foreground" />Where the money went</h2>
            <p class="mt-0.5 text-xs text-muted-foreground">
              {{ departmentSummary.totals.departments }} cost centre(s), by net payable. Headcount share sits next to money share —
              a unit that is 5% of the people and 20% of the payroll is the interesting one.
            </p>
          </div>
          <button type="button" class="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline" @click="router.visit(route('payroll.sheets.departments', sheet.id))">
            Open departments <ArrowRight class="size-3" />
          </button>
        </div>
        <div class="grid gap-x-6 gap-y-2.5 p-5 sm:grid-cols-2 xl:grid-cols-3">
          <div v-for="row in departmentSummary.rows.slice(0, 6)" :key="row.department" class="min-w-0">
            <div class="flex items-baseline justify-between gap-2 text-[13px]">
              <span class="truncate font-medium">{{ row.department }}</span>
              <span class="shrink-0 tabular-nums">{{ money(row.net) }}</span>
            </div>
            <div class="mt-1 flex items-center gap-2">
              <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-muted">
                <div class="h-full rounded-full bg-primary/70" :style="{ width: `${row.net_pct}%` }" />
              </div>
              <span class="w-24 shrink-0 text-right text-[11px] tabular-nums text-muted-foreground">{{ row.net_pct }}% · {{ row.slips }} staff</span>
            </div>
          </div>
        </div>
      </section>

      <!-- Cutlist + ledger -->
      <div class="grid items-start gap-4 xl:grid-cols-3">
        <div class="rounded-xl border bg-card p-4 shadow-sm">
          <div class="flex items-start justify-between gap-3">
            <div>
              <h3 class="flex items-center gap-2 text-sm font-semibold"><FileStack class="size-4 text-muted-foreground" />Attendance cutlist</h3>
              <p v-if="context.cutlist" class="mt-1 text-xs text-muted-foreground">
                {{ context.cutlist.items.toLocaleString() }} item(s) · {{ context.cutlist.status }}
                <template v-if="context.cutlist.approved_at"> · approved {{ formatDateTime(context.cutlist.approved_at) }}</template>
              </p>
              <p v-else class="mt-1 text-xs text-muted-foreground">No cutlist is linked, so punishment and overtime cannot be traced to an approved source.</p>
            </div>
            <Link v-if="context.cutlist" :href="route('payroll.cutlists.show', context.cutlist.id)" class="shrink-0 text-xs font-medium text-primary hover:underline">Open →</Link>
          </div>
        </div>

        <div class="rounded-xl border bg-card p-4 shadow-sm">
          <div class="flex items-start justify-between gap-3">
            <div>
              <h3 class="flex items-center gap-2 text-sm font-semibold"><Landmark class="size-4 text-muted-foreground" />Ledger</h3>
              <p v-if="context.journal" class="mt-1 text-xs text-muted-foreground">
                {{ context.journal.journal_no }} posted {{ context.journal.journal_date }} · {{ money(context.journal.total_debit) }}
              </p>
              <p v-else class="mt-1 text-xs text-muted-foreground">No accrual posted, so the books do not carry this month's salary cost yet.</p>
            </div>
            <Link :href="route('payroll.finance.sheets.show', sheet.id)" class="shrink-0 text-xs font-medium text-primary hover:underline">Finance →</Link>
          </div>
        </div>

        <div class="rounded-xl border bg-card p-4 shadow-sm">
          <div class="flex items-start justify-between gap-3">
            <div>
              <h3 class="flex items-center gap-2 text-sm font-semibold"><Banknote class="size-4 text-muted-foreground" />Bank advice</h3>
              <p v-if="context.advice" class="mt-1 text-xs text-muted-foreground">
                {{ context.advice.advice_no }} · {{ context.advice.status }} · {{ money(context.advice.total_amount) }}
              </p>
              <p v-else class="mt-1 text-xs text-muted-foreground">No payment file generated for this month yet.</p>
            </div>
            <Link v-if="context.advice" :href="route('payroll.bank-advices.show', context.advice.id)" class="shrink-0 text-xs font-medium text-primary hover:underline">Open →</Link>
          </div>
        </div>
      </div>

      <!-- Exclusions + warnings -->
      <section v-if="periodExclusions.length" class="rounded-xl border bg-warning/5 p-4">
        <h2 class="text-sm font-semibold">Documented exclusions for this pay period</h2>
        <div class="mt-2 divide-y">
          <article v-for="item in periodExclusions" :key="item.employee_id" class="py-2 text-sm">
            <strong>{{ item.employee_name || item.employee_id }}</strong>
            <span class="ml-2 font-mono text-xs text-muted-foreground">{{ item.employee_id }}</span>
            <p class="mt-1 text-xs text-muted-foreground">
              Reason: {{ item.reason }} · Recorded by {{ item.created_by_name || 'System' }}{{ item.created_at ? ` · ${formatDateTime(item.created_at)}` : '' }}
            </p>
          </article>
        </div>
      </section>

      <section v-if="payrollWarnings.length" class="rounded-xl border border-warning/40 bg-warning/5 p-4">
        <h2 class="text-sm font-semibold">Left out of this pay period: {{ payrollWarnings.length }} employee(s) have no rate payroll can use</h2>
        <p class="mt-1 text-xs text-muted-foreground">
          These are not exclusions someone chose — no HRM salary record covers {{ context.period }}, so a slip cannot be computed for
          them and the sheet skips them instead of failing the whole month. Add the salary history and run the sheet again.
        </p>
        <ul class="mt-2 max-h-48 space-y-1 overflow-y-auto text-sm">
          <li v-for="w in payrollWarnings" :key="w.employee_id" class="flex flex-wrap items-baseline gap-2 border-b pb-1 last:border-0">
            <strong>{{ w.employee_name || w.employee_id }}</strong>
            <span class="font-mono text-xs text-muted-foreground">{{ w.employee_id }}</span>
            <span v-if="w.department" class="text-xs text-muted-foreground">· {{ w.department }}</span>
            <span class="text-xs">{{ w.reason }}</span>
          </li>
        </ul>
      </section>

      <!-- Slips -->
      <DataTable
        :route-name="'payroll.sheets.show'"
        :route-params="{ salarySheet: sheet.id }"
        title="Payslips"
        :columns="slipColumns"
        :rows="slips.data"
        :meta="slips.meta"
        :default-sort="{ key: 'employee_name', direction: 'asc' }"
        empty-title="No payslip in this run"
        empty-description="A run with no slips produced nothing payable — check the failure message above."
      >
        <template #cell-employee_name="{ row }">
          <div>
            <strong>{{ row.employee_name }}</strong>
            <span class="block text-xs text-muted-foreground">{{ row.employee_id }}</span>
          </div>
        </template>
        <template #cell-department="{ row }"><span class="text-muted-foreground">{{ row.department || '—' }}</span></template>
        <template #cell-gross_salary="{ row }">{{ money(row.gross_salary) }}</template>
        <template #cell-total_deductions="{ row }">{{ money(row.total_deductions) }}</template>
        <template #cell-net_salary="{ row }"><strong>{{ money(row.net_salary) }}</strong></template>
        <template #cell-payment_status="{ row }">
          <StatusBadge
            :label="row.payment_status"
            :tone="row.payment_status === 'paid' ? 'success' : row.payment_status === 'hold' ? 'warning' : 'neutral'"
            dot
          />
        </template>
        <template #cell-actions="{ row }">
          <Link :href="route('payroll.slips.show', row.id)" class="text-xs font-medium text-primary hover:underline">Payslip →</Link>
        </template>
      </DataTable>
    </div>
  </AdminLayout>
</template>
