<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { confirmDialog } from '@core/Composables/useConfirm';
import { useToast } from '@core/Composables/useToast';
import { computed } from 'vue';
import {
  AlertTriangle,
  ArrowRight,
  BadgeCheck,
  Banknote,
  CalendarClock,
  ChevronRight,
  CircleAlert,
  CircleCheck,
  BookOpenCheck,
  Clock,
  FileStack,
  HandCoins,
  PlusCircle,
  TrendingUp,
  Users,
  Wallet,
} from 'lucide-vue-next';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import BaseChart from '@core/Components/BaseChart.vue';
import { Button } from '@/components/ui/button';
import ModuleNav from './ModuleNav.vue';
import { formatCurrency } from '@core/Utils/format';

/**
 * The staff-advance desk.
 *
 * Everything on this page is a *reading* of the advances book. There is no form on it, deliberately:
 * the old screen mixed the approval queue, the monthly staging form and the register, and once a
 * write happened nothing on it told you which figures were older than that write. Each job now has
 * the page that owns it and this one only reports.
 *
 * The figures come from `payroll_loan_installments`, not from the loan rows. A loan row says what was
 * promised; an installment says what payroll actually took. Only the second one is recoverable.
 */

interface Summary {
  loans: number;
  active_loans: number;
  pending_loans: number;
  completed_loans: number;
  rejected_loans: number;
  employees_with_loans: number;
  principal: number;
  recovered: number;
  outstanding: number;
  overdue_amount: number;
  overdue_count: number;
  due_this_month: number;
  staged_this_month: number;
}

interface Cash {
  recovered: number;
  outstanding: number;
  recovered_pct: number;
  overdue_amount: number;
}

interface TrendPoint {
  period: string;
  label: string;
  label_long: string;
  recovered: number;
  scheduled: number;
  loans: number;
}

interface RecentRow {
  id: number;
  employee_id: string;
  employee_name: string | null;
  principal_amount: number;
  installment_count: number;
  status: string;
  purpose: string | null;
  created_at: string | null;
}

interface OverdueRow {
  id: number;
  loan_id: number;
  employee_id: string | null;
  employee_name: string | null;
  installment_number: number;
  due_date: string | null;
  amount: number;
  days_late: number;
}

interface WatchRow {
  id: number;
  employee_id: string;
  employee_name: string | null;
  principal_amount: number;
  recovered: number;
  remaining: number;
  installments: number;
  paid_installments: number;
  remaining_installments: number;
  progress_pct: number;
}

interface Period {
  year: number;
  month: number;
  batch: { pay_year: number; pay_month: number; status: string; scope: string; installments: number } | null;
}

/**
 * How much of the advances book the ledger knows about.
 *
 * This is not decoration. Payroll credits a recovery to `2125 Payroll Deductions Payable` — money the
 * company owes the employee — so a staff advance never becomes an asset in the books. The module can
 * report ৳50,000 outstanding while the trial balance shows nothing at all, and a loan book an auditor
 * cannot tie out is worse than no loan book. The number here is the size of that gap.
 */
interface Ledger {
  recoverable: number;
  ledger_receivable: number;
  unreconciled: number;
  gl_linked: boolean;
  note: string;
}

const props = defineProps<{
  summary: Summary;
  cash: Cash;
  trend: TrendPoint[];
  recent: RecentRow[];
  overdue: OverdueRow[];
  watchlist: WatchRow[];
  ledger: Ledger;
  period: Period;
  canManage: boolean;
  canApproveLoans: boolean;
}>();

const money = (value: number | string | null | undefined): string => formatCurrency(Number(value ?? 0));

/** The month whose approved advances are recognised in the ledger. */
const posting = useForm({ pay_year: props.period.year, pay_month: props.period.month });
const toast = useToast();

/**
 * Recognising a month is a write to the ledger, so it asks first and says what it will do.
 *
 * The previous version of this module posted nothing at all; the desk now offers the entry, which
 * means it has to behave like every other posting in the application — confirm the period, name the
 * accounts, then post.
 */
async function postToLedger(): Promise<void> {
  const proceed = await confirmDialog({
    title: `Recognise ${posting.pay_year}-${String(posting.pay_month).padStart(2, '0')} in the ledger?`,
    description: 'Posts Dr 1315 Staff Advance Receivable / Cr 2121 Salary Payable for every advance approved in that month. Posting the same month twice changes nothing.',
    confirmLabel: 'Post to ledger',
    danger: false,
  });
  if (!proceed) return;

  posting.post(route('payroll.loans.post-ledger'), {
    preserveScroll: true,
    onSuccess: () => toast.success('The advances were recognised in the ledger.'),
  });
}

const postingMonth = computed({
  get: () => `${posting.pay_year}-${String(posting.pay_month).padStart(2, '0')}`,
  set: (value: string) => {
    const [year, month] = value.split('-').map(Number);
    if (year && month) {
      posting.pay_year = year;
      posting.pay_month = month;
    }
  },
});

/** Large figures on a stat card are read as a size, not as a number to reconcile — ৳3.38M beats ৳3,382,484.56 at 24px. */
function figure(value: number): string {
  const v = Number(value ?? 0);
  if (v >= 10_000_000) return `${money(v / 10_000_000).replace(/\.\d+/, '')} Cr`;
  if (v >= 100_000) return `${(v / 100_000).toFixed(2)} Lakh`;
  return money(v);
}

const recoveredPct = computed(() => Math.max(0, Math.min(100, Number(props.cash.recovered_pct ?? 0))));

/* --------------------------------- chart --------------------------------- */

const trendOption = computed(() => ({
  grid: { left: 8, right: 12, top: 36, bottom: 4, containLabel: true },
  tooltip: {
    trigger: 'axis',
    valueFormatter: (value: number) => money(value),
  },
  legend: { top: 0, right: 0, itemWidth: 10, itemHeight: 10, textStyle: { fontSize: 11 } },
  xAxis: {
    type: 'category',
    data: props.trend.map((point) => point.label),
    axisTick: { show: false },
    axisLine: { lineStyle: { color: 'rgba(120,120,120,0.25)' } },
    axisLabel: { fontSize: 11 },
  },
  yAxis: {
    type: 'value',
    splitLine: { lineStyle: { color: 'rgba(120,120,120,0.12)' } },
    // One unit for the whole axis. Mixing M and L produced two ticks both labelled "2M", which is
    // worse than no axis at all — the same bug the fund trend had.
    axisLabel: {
      fontSize: 11,
      formatter: (value: number) =>
        value === 0 ? '0' : value >= 1_000_000 ? `${(value / 1_000_000).toFixed(1)}M` : `${Math.round(value / 1000)}k`,
    },
  },
  series: [
    {
      name: 'Recovered',
      type: 'bar',
      stack: 'recovery',
      data: props.trend.map((point) => point.recovered),
      itemStyle: { color: '#3b82f6', borderRadius: [0, 0, 3, 3] },
      barMaxWidth: 26,
    },
    {
      name: 'Still due',
      type: 'bar',
      stack: 'recovery',
      data: props.trend.map((point) => point.scheduled),
      itemStyle: { color: '#e2e8f0', borderRadius: [3, 3, 0, 0] },
      barMaxWidth: 26,
    },
  ],
}));

/* -------------------------------- signals -------------------------------- */

type Tone = 'ok' | 'info' | 'warn' | 'error';

function toneClass(tone: Tone): string {
  return {
    ok: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
    info: 'bg-sky-500/10 text-sky-600 dark:text-sky-400',
    warn: 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
    error: 'bg-rose-500/10 text-rose-600 dark:text-rose-400',
  }[tone];
}

interface Alert {
  tone: Tone;
  text: string;
  to?: string;
  label?: string;
}

const alerts = computed<Alert[]>(() => {
  const list: Alert[] = [];

  if (props.summary.overdue_count > 0) {
    list.push({
      tone: 'error',
      text: `${props.summary.overdue_count} repayment(s) worth ${money(props.summary.overdue_amount)} passed their due date without being taken. Payroll stages one installment per loan per month, so these clear slowly by design.`,
      to: route('payroll.loans.schedule'),
      label: 'Schedule',
    });
  }
  if (props.summary.pending_loans > 0) {
    const who = props.canApproveLoans ? 'awaiting a decision' : 'awaiting a decision from someone who may approve';
    list.push({
      tone: 'warn',
      text: `${props.summary.pending_loans} advance request(s) ${who}.`,
      to: `${route('payroll.loans.register')}?filters[status]=pending`,
      label: 'Review',
    });
  }
  if (props.summary.due_this_month > 0) {
    list.push({
      tone: 'info',
      text: `${money(props.summary.due_this_month)} falls due this month; ${money(props.summary.staged_this_month)} is already staged in the payroll batch.`,
      to: route('payroll.loans.schedule'),
      label: 'Schedule',
    });
  }
  if (list.length === 0) {
    list.push({ tone: 'ok', text: 'Nothing needs a decision: every installment is on schedule and no request is waiting.' });
  }
  return list;
});

const statusTone = (status: string): string => ({
  pending: 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
  active: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
  completed: 'bg-sky-500/10 text-sky-600 dark:text-sky-400',
  rejected: 'bg-rose-500/10 text-rose-600 dark:text-rose-400',
  cancelled: 'bg-muted text-muted-foreground',
}[status] ?? 'bg-muted text-muted-foreground');

const monthLabel = (value: string): string => {
  const [year, month] = value.split('-');
  if (!year || !month) return value;
  return new Date(Number(year), Number(month) - 1, 1).toLocaleDateString('en-GB', { month: 'long', year: 'numeric' });
};
</script>

<template>
  <AdminLayout :breadcrumbs="[{ title: 'Payroll' }, { title: 'Staff advances' }]">
    <Head title="Staff advances" />

    <div class="space-y-4">
      <ModuleNav :can-manage="canManage" />

      <!-- Header -->
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 class="flex items-center gap-2.5 text-xl font-bold tracking-tight">
            <span class="grid size-9 place-items-center rounded-xl bg-gradient-to-br from-primary to-primary/70 text-primary-foreground shadow-sm">
              <HandCoins class="size-5" />
            </span>
            Staff advances
          </h1>
          <p class="mt-1 text-sm text-muted-foreground">
            Interest-free advances to employees, recovered one installment per payroll month — approved in stages, staged before the sheet, taken when payroll runs.
          </p>
        </div>
        <div class="flex flex-wrap gap-2">
          <Button size="sm" variant="outline" @click="router.visit(route('payroll.loans.register'))">
            <FileStack class="size-4" />Register
          </Button>
          <Button v-if="canManage" size="sm" @click="router.visit(route('payroll.loans.create'))">
            <PlusCircle class="size-4" />New request
          </Button>
        </div>
      </div>

      <!-- Figures -->
      <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-xl border bg-card p-5 shadow-sm">
          <div class="flex items-start justify-between">
            <span class="grid size-11 place-items-center rounded-xl bg-sky-500/10 text-sky-600 dark:text-sky-400"><Banknote class="size-5" /></span>
          </div>
          <p class="mt-3 text-2xl font-bold tabular-nums tracking-tight">{{ figure(summary.outstanding) }}</p>
          <p class="text-[13px] font-medium text-muted-foreground">Still to recover</p>
          <p class="mt-1 text-xs text-muted-foreground">{{ money(summary.recovered) }} of {{ money(summary.principal) }} back</p>
        </div>

        <div class="rounded-xl border bg-card p-5 shadow-sm">
          <div class="flex items-start justify-between">
            <span class="grid size-11 place-items-center rounded-xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-400"><Wallet class="size-5" /></span>
          </div>
          <p class="mt-3 text-2xl font-bold tabular-nums tracking-tight">{{ summary.active_loans.toLocaleString() }}</p>
          <p class="text-[13px] font-medium text-muted-foreground">Active advances</p>
          <p class="mt-1 text-xs text-muted-foreground">{{ summary.employees_with_loans }} employee(s) · {{ summary.completed_loans }} repaid</p>
        </div>

        <div class="rounded-xl border bg-card p-5 shadow-sm">
          <div class="flex items-start justify-between">
            <span class="grid size-11 place-items-center rounded-xl bg-violet-500/10 text-violet-600 dark:text-violet-400"><TrendingUp class="size-5" /></span>
          </div>
          <p class="mt-3 text-2xl font-bold tabular-nums tracking-tight">{{ recoveredPct }}%</p>
          <p class="text-[13px] font-medium text-muted-foreground">Recovered</p>
          <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-muted">
            <div class="h-full rounded-full bg-violet-500 transition-all" :style="{ width: `${recoveredPct}%` }" />
          </div>
        </div>

        <div class="rounded-xl border bg-card p-5 shadow-sm">
          <div class="flex items-start justify-between">
            <span
              class="grid size-11 place-items-center rounded-xl"
              :class="summary.overdue_count > 0 ? 'bg-rose-500/10 text-rose-600 dark:text-rose-400' : 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'"
            >
              <Clock class="size-5" />
            </span>
            <button
              v-if="summary.overdue_count > 0"
              type="button"
              class="inline-flex items-center gap-0.5 text-[11px] font-medium text-primary hover:underline"
              @click="router.visit(route('payroll.loans.schedule'))"
            >
              Open <ArrowRight class="size-3" />
            </button>
          </div>
          <p class="mt-3 text-2xl font-bold tabular-nums tracking-tight">{{ summary.overdue_count.toLocaleString() }}</p>
          <p class="text-[13px] font-medium text-muted-foreground">Overdue repayments</p>
          <p class="mt-1 text-xs" :class="summary.overdue_count > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-muted-foreground'">
            {{ summary.overdue_count > 0 ? money(summary.overdue_amount) + ' past due' : 'everything on schedule' }}
          </p>
        </div>
      </div>

      <!-- Chart + what needs a decision -->
      <div class="grid items-start gap-4 xl:grid-cols-5">
        <section class="rounded-xl border bg-card shadow-sm xl:col-span-3">
          <div class="flex flex-wrap items-start justify-between gap-3 border-b px-5 py-4">
            <div>
              <h2 class="flex items-center gap-2 text-sm font-semibold"><TrendingUp class="size-4 text-muted-foreground" />Recovery by payroll month</h2>
              <p class="mt-0.5 text-xs text-muted-foreground">What payroll took, against what was due in that month.</p>
            </div>
            <span class="rounded-md bg-muted px-2.5 py-1 text-xs text-muted-foreground">{{ monthLabel(`${period.year}-${String(period.month).padStart(2, '0')}`) }}</span>
          </div>
          <div class="px-3 py-4">
            <BaseChart v-if="trend.length" :option="trendOption" height="290px" />
            <div v-else class="grid h-[290px] place-items-center text-center">
              <div>
                <TrendingUp class="mx-auto size-8 text-muted-foreground/40" />
                <p class="mt-2 text-sm font-medium">No recoveries yet</p>
                <p class="mt-1 text-xs text-muted-foreground">Run payroll with a staged installment to start the trend.</p>
              </div>
            </div>
          </div>
        </section>

        <section class="rounded-xl border bg-card shadow-sm xl:col-span-2">
          <div class="flex items-start justify-between gap-3 border-b px-5 py-4">
            <div>
              <h2 class="flex items-center gap-2 text-sm font-semibold"><CircleAlert class="size-4 text-muted-foreground" />Needs a decision</h2>
              <p class="mt-0.5 text-xs text-muted-foreground">What the book says, and what is waiting.</p>
            </div>
            <span
              class="flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-medium"
              :class="summary.overdue_count > 0 ? toneClass('error') : toneClass('ok')"
            >
              <component :is="summary.overdue_count > 0 ? AlertTriangle : CircleCheck" class="size-3" />
              {{ summary.overdue_count > 0 ? 'Attention' : 'On track' }}
            </span>
          </div>
          <ul class="divide-y">
            <li v-for="(alert, i) in alerts" :key="i" class="flex items-center gap-3 px-5 py-3">
              <span class="grid size-7 shrink-0 place-items-center rounded-lg" :class="toneClass(alert.tone)">
                <component :is="alert.tone === 'ok' ? BadgeCheck : alert.tone === 'error' ? AlertTriangle : CircleAlert" class="size-3.5" />
              </span>
              <span class="min-w-0 flex-1 text-[13px] leading-snug">{{ alert.text }}</span>
              <button
                v-if="alert.to"
                type="button"
                class="inline-flex shrink-0 items-center gap-0.5 text-[11px] font-medium text-primary hover:underline"
                @click="router.visit(alert.to)"
              >
                {{ alert.label }} <ChevronRight class="size-3" />
              </button>
            </li>
          </ul>
        </section>
      </div>

      <!-- Overdue + biggest exposures -->
      <div class="grid items-start gap-4 xl:grid-cols-2">
        <section class="rounded-xl border bg-card shadow-sm">
          <div class="flex items-start justify-between gap-3 border-b px-5 py-4">
            <div>
              <h2 class="flex items-center gap-2 text-sm font-semibold"><Clock class="size-4 text-muted-foreground" />Past due, not taken</h2>
              <p class="mt-0.5 text-xs text-muted-foreground">Repayments whose month has passed. Payroll clears one per loan per month, so a backlog ages.</p>
            </div>
            <button type="button" class="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline" @click="router.visit(route('payroll.loans.schedule'))">
              Schedule <ArrowRight class="size-3" />
            </button>
          </div>

          <div v-if="overdue.length === 0" class="px-5 py-12 text-center">
            <CircleCheck class="mx-auto size-7 text-emerald-500/60" />
            <p class="mt-2 text-sm font-medium">Nothing past due</p>
            <p class="mt-1 text-xs text-muted-foreground">Every unpaid repayment is still within its month.</p>
          </div>

          <table v-else class="w-full text-[13px]">
            <thead>
              <tr class="border-b text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                <th class="px-5 py-2 font-medium">Employee</th>
                <th class="px-2 py-2 font-medium">Due</th>
                <th class="px-2 py-2 text-right font-medium">Amount</th>
                <th class="px-5 py-2 text-right font-medium">Late</th>
              </tr>
            </thead>
            <tbody class="divide-y">
              <tr v-for="row in overdue" :key="row.id" class="transition hover:bg-muted/40">
                <td class="px-5 py-2.5">
                  <button type="button" class="text-left hover:underline" @click="router.visit(route('payroll.loans.show', row.loan_id))">
                    <span class="block font-medium leading-tight">{{ row.employee_name || row.employee_id }}</span>
                    <span class="block text-[11px] text-muted-foreground">installment {{ row.installment_number }}</span>
                  </button>
                </td>
                <td class="px-2 py-2.5 text-muted-foreground">{{ row.due_date }}</td>
                <td class="px-2 py-2.5 text-right tabular-nums font-medium">{{ money(row.amount) }}</td>
                <td class="px-5 py-2.5 text-right">
                  <span class="rounded px-1.5 py-px text-[10px] font-semibold uppercase tracking-wide" :class="row.days_late > 60 ? toneClass('error') : toneClass('warn')">
                    {{ row.days_late }}d
                  </span>
                </td>
              </tr>
            </tbody>
          </table>
        </section>

        <section class="rounded-xl border bg-card shadow-sm">
          <div class="flex items-start justify-between gap-3 border-b px-5 py-4">
            <div>
              <h2 class="flex items-center gap-2 text-sm font-semibold"><Users class="size-4 text-muted-foreground" />Largest balances</h2>
              <p class="mt-0.5 text-xs text-muted-foreground">Where the company's money is, ordered by what is still owed.</p>
            </div>
            <button type="button" class="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline" @click="router.visit(`${route('payroll.loans.register')}?filters[status]=active`)">
              All active <ArrowRight class="size-3" />
            </button>
          </div>

          <div v-if="watchlist.length === 0" class="px-5 py-12 text-center">
            <Wallet class="mx-auto size-7 text-muted-foreground/40" />
            <p class="mt-2 text-sm font-medium">No active advances</p>
            <p class="mt-1 text-xs text-muted-foreground">Approved advances appear here until the last installment is taken.</p>
          </div>

          <ul v-else class="divide-y">
            <li v-for="row in watchlist" :key="row.id">
              <button type="button" class="flex w-full items-center gap-3 px-5 py-3 text-left transition hover:bg-muted/40" @click="router.visit(route('payroll.loans.show', row.id))">
                <span class="grid size-8 shrink-0 place-items-center rounded-lg bg-muted text-[11px] font-bold">
                  {{ (row.employee_name || row.employee_id).slice(0, 2).toUpperCase() }}
                </span>
                <span class="min-w-0 flex-1">
                  <span class="block truncate text-[13px] font-medium leading-tight">{{ row.employee_name || row.employee_id }}</span>
                  <span class="mt-1 flex items-center gap-2">
                    <span class="h-1 w-16 overflow-hidden rounded-full bg-muted">
                      <span class="block h-full rounded-full bg-emerald-500" :style="{ width: `${row.progress_pct}%` }" />
                    </span>
                    <span class="text-[11px] text-muted-foreground">{{ row.paid_installments }}/{{ row.installments }} taken</span>
                  </span>
                </span>
                <span class="shrink-0 text-right">
                  <span class="block text-[13px] font-semibold tabular-nums">{{ money(row.remaining) }}</span>
                  <span class="block text-[11px] text-muted-foreground">of {{ money(row.principal_amount) }}</span>
                </span>
              </button>
            </li>
          </ul>
        </section>
      </div>

      <!-- Ledger -->
      <section class="rounded-xl border bg-card shadow-sm">
        <div class="flex flex-wrap items-start justify-between gap-3 border-b px-5 py-4">
          <div>
            <h2 class="flex items-center gap-2 text-sm font-semibold"><BookOpenCheck class="size-4 text-muted-foreground" />In the books</h2>
            <p class="mt-0.5 max-w-2xl text-xs text-muted-foreground">
              Payroll credits a recovery to <strong>2125 Payroll Deductions Payable</strong> — money the company owes the employee — so an advance never becomes an asset on its own. This is how much of the book the trial balance does not show.
            </p>
          </div>
          <span
            class="flex shrink-0 items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-medium"
            :class="Math.abs(ledger.unreconciled) < 0.01 ? toneClass('ok') : toneClass('warn')"
          >
            <component :is="Math.abs(ledger.unreconciled) < 0.01 ? CircleCheck : CircleAlert" class="size-3" />
            {{ Math.abs(ledger.unreconciled) < 0.01 ? 'Recognised' : 'Not recognised' }}
          </span>
        </div>

        <div class="grid gap-4 p-5 sm:grid-cols-3">
          <div>
            <p class="text-xs font-medium uppercase tracking-wider text-muted-foreground">Recoverable from employees</p>
            <p class="mt-1.5 text-xl font-bold tabular-nums tracking-tight">{{ money(ledger.recoverable) }}</p>
            <p class="mt-1 text-xs text-muted-foreground">unpaid installments on approved advances</p>
          </div>
          <div>
            <p class="text-xs font-medium uppercase tracking-wider text-muted-foreground">On the ledger (1315)</p>
            <p class="mt-1.5 text-xl font-bold tabular-nums tracking-tight">{{ money(ledger.ledger_receivable) }}</p>
            <p class="mt-1 text-xs text-muted-foreground">{{ ledger.gl_linked ? 'Staff Advance Receivable' : 'account does not exist yet' }}</p>
          </div>
          <div>
            <p class="text-xs font-medium uppercase tracking-wider text-muted-foreground">Not in the books</p>
            <p class="mt-1.5 text-xl font-bold tabular-nums tracking-tight" :class="Math.abs(ledger.unreconciled) < 0.01 ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400'">
              {{ money(ledger.unreconciled) }}
            </p>
            <p class="mt-1 text-xs text-muted-foreground">difference an auditor would find</p>
          </div>
        </div>

        <div class="flex flex-wrap items-end justify-between gap-4 border-t px-5 py-4">
          <p class="max-w-xl text-xs leading-snug text-muted-foreground">{{ ledger.note }}</p>
          <form v-if="canManage" class="flex flex-wrap items-end gap-2" @submit.prevent="postToLedger">
            <div class="space-y-1">
              <label class="text-[11px] font-medium text-muted-foreground" for="posting-month">Recognise a month</label>
              <input
                id="posting-month"
                v-model="postingMonth"
                type="month"
                class="h-9 rounded-md border bg-background px-2.5 text-sm"
              />
            </div>
            <Button size="sm" :disabled="posting.processing">
              <BookOpenCheck class="size-4" />{{ posting.processing ? 'Posting…' : 'Post to ledger' }}
            </Button>
          </form>
        </div>
        <p v-if="posting.errors.pay_year || posting.errors.accounts || posting.errors.period" class="border-t px-5 py-2 text-xs text-destructive">
          {{ posting.errors.pay_year || posting.errors.accounts || posting.errors.period }}
        </p>
      </section>

      <!-- Recent requests -->
      <section class="rounded-xl border bg-card shadow-sm">
        <div class="flex items-start justify-between gap-3 border-b px-5 py-4">
          <div>
            <h2 class="flex items-center gap-2 text-sm font-semibold"><CalendarClock class="size-4 text-muted-foreground" />Latest requests</h2>
            <p class="mt-0.5 text-xs text-muted-foreground">Newest first, whatever their state.</p>
          </div>
          <button type="button" class="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline" @click="router.visit(route('payroll.loans.register'))">
            Register <ArrowRight class="size-3" />
          </button>
        </div>

        <div v-if="recent.length === 0" class="px-5 py-12 text-center">
          <HandCoins class="mx-auto size-7 text-muted-foreground/40" />
          <p class="mt-2 text-sm font-medium">No advances yet</p>
          <p class="mt-1 text-xs text-muted-foreground">Raise the first request and it will appear here for approval.</p>
        </div>

        <div v-else class="grid gap-3 p-5 sm:grid-cols-2 lg:grid-cols-5">
          <button
            v-for="row in recent"
            :key="row.id"
            type="button"
            class="flex flex-col gap-2 rounded-xl border p-3.5 text-left transition hover:border-primary/40 hover:bg-primary/[0.03]"
            @click="router.visit(route('payroll.loans.show', row.id))"
          >
            <span class="flex items-start justify-between gap-2">
              <span class="truncate text-[13px] font-medium leading-tight">{{ row.employee_name || row.employee_id }}</span>
              <span class="shrink-0 rounded px-1.5 py-px text-[10px] font-semibold uppercase tracking-wide" :class="statusTone(row.status)">{{ row.status }}</span>
            </span>
            <span class="text-lg font-bold tabular-nums tracking-tight">{{ money(row.principal_amount) }}</span>
            <span class="text-[11px] text-muted-foreground">{{ row.installment_count }} installment(s) · {{ row.created_at }}</span>
          </button>
        </div>
      </section>
    </div>
  </AdminLayout>
</template>
