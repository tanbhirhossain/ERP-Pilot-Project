<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import {
  AlertTriangle,
  ArrowRight,
  ArrowUpRight,
  BadgeCheck,
  CalendarClock,
  ChevronRight,
  CircleAlert,
  CircleCheck,
  Landmark,
  PiggyBank,
  TrendingUp,
  Users,
  Wallet,
} from 'lucide-vue-next';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import BaseChart from '@core/Components/BaseChart.vue';
import { Button } from '@/components/ui/button';
import { formatCurrency } from '@core/Utils/format';

/**
 * The fund desk.
 *
 * Four figures, the contribution trend, what is waiting on a decision, and whether the books agree.
 * Everything here is a *reading* of the fund — every action lives on the page that owns it, which is
 * the whole point of splitting the module up: a dashboard you can also transact from is a dashboard
 * nobody trusts, because it is never clear which numbers are stale after a write.
 */

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

interface TrendPoint {
  period: string;
  label: string;
  label_long: string;
  employee_side: number;
  employer_side: number;
  total: number;
  members: number;
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

interface WithdrawalRow {
  id: number;
  account_id: number;
  employee_id: string;
  employee_name: string | null;
  type: string;
  requested_amount: number;
  approved_amount: number | null;
  reason: string;
  status: string;
}

const props = defineProps<{
  summary: Summary;
  cash: Cash;
  trend: TrendPoint[];
  recent_withdrawals: WithdrawalRow[];
  periods: Period[];
  drift_count: number;
  integrity?: { entries: number; orphan_entries: number; orphan_amount: number; future_entries: number; future_amount: number; duplicate_groups: number; duplicate_amount: number };
  composition: { employee: number; employer: number; paid_out: number; outstanding: number };
  canManage: boolean;
}>();

const money = (value: number | string | null | undefined): string => formatCurrency(Number(value ?? 0));

/** A figure for a stat card: full below a lakh, compact above, because nobody reads ten digits. */
const figure = (value: number | null | undefined): string => {
  const v = Number(value ?? 0);
  if (Math.abs(v) >= 1_000_000) return `৳ ${(v / 1_000_000).toFixed(2)}M`;
  if (Math.abs(v) >= 100_000) return `৳ ${(v / 100_000).toFixed(2)}L`;
  return money(v);
};

const monthLabel = (period: string | null): string => {
  if (!period) return '—';
  const [year, month] = period.split('-').map(Number);
  if (!year || !month) return period;
  return new Date(year, month - 1, 1).toLocaleDateString('en-GB', { month: 'long', year: 'numeric' });
};

/* -------------------------------- signals -------------------------------- */

const awaitingRelease = computed(() => props.periods.filter((p) => !p.can_accrue && p.posted_members === 0).length);

const alerts = computed(() => {
  const list: Array<{ tone: 'warn' | 'info' | 'ok' | 'error'; text: string; to?: string; label?: string }> = [];

  // A balance can agree with the ledger and both can still be wrong, which is exactly what happened.
  if ((props.integrity?.orphan_entries ?? 0) > 0) {
    list.push({
      tone: 'error',
      text: `${props.integrity!.orphan_entries} ledger entr(ies) worth ${money(props.integrity!.orphan_amount)} name a salary slip that no longer exists — the payroll behind them was deleted and the accrual stayed.`,
      to: route('payroll.pf.members'),
      label: 'Review',
    });
  }
  if ((props.integrity?.future_entries ?? 0) > 0) {
    list.push({
      tone: 'warn',
      text: `${props.integrity!.future_entries} entr(ies) worth ${money(props.integrity!.future_amount)} are dated in the future.`,
      to: route('payroll.pf.members'),
      label: 'Review',
    });
  }
  if (props.drift_count > 0) {
    list.push({ tone: 'error', text: `${props.drift_count} account(s) disagree with the ledger.`, to: route('payroll.pf.members'), label: 'Review' });
  }
  if (props.summary.pending_withdrawals > 0) {
    list.push({ tone: 'warn', text: `${props.summary.pending_withdrawals} withdrawal request(s) awaiting a decision.`, to: route('payroll.pf.withdrawals.index'), label: 'Review' });
  }
  if (Number(props.cash.outstanding) > 0.009) {
    list.push({ tone: 'info', text: `${money(props.cash.outstanding)} recognised but not yet collected into the fund.` });
  }
  if (props.summary.unallocated_employees > 0) {
    list.push({ tone: 'warn', text: `${props.summary.unallocated_employees} active employee(s) hold no account.`, to: route('payroll.pf.members'), label: 'Enrol' });
  }
  if (awaitingRelease > 0) {
    list.push({ tone: 'info', text: `${awaitingRelease} payroll month(s) cannot be accrued until the sheet is released.`, to: route('payroll.pf.index'), label: 'Register' });
  }
  if (list.length === 0) {
    list.push({ tone: 'ok', text: 'Nothing needs attention. Every balance reconciles to the ledger.' });
  }

  return list;
});

const toneClass = (tone: string): string => ({
  error: 'bg-rose-500/10 text-rose-600 dark:text-rose-400',
  warn: 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
  info: 'bg-sky-500/10 text-sky-600 dark:text-sky-400',
  ok: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
}[tone] ?? 'bg-muted text-muted-foreground');

const statusTone = (status: string): string => ({
  pending: 'bg-amber-500/15 text-amber-700 dark:text-amber-400',
  approved: 'bg-sky-500/15 text-sky-700 dark:text-sky-400',
  paid: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-400',
  rejected: 'bg-rose-500/15 text-rose-700 dark:text-rose-400',
}[status] ?? 'bg-muted text-muted-foreground');

/* --------------------------------- chart --------------------------------- */

/** Stacked bars: the member's share under the employer's, so the total height is the month's contribution. */
const trendOption = computed(() => ({
  grid: { left: 8, right: 12, top: 36, bottom: 4, containLabel: true },
  tooltip: {
    trigger: 'axis',
    axisPointer: { type: 'shadow' },
    valueFormatter: (value: number) => money(value),
  },
  legend: {
    right: 0,
    top: 0,
    icon: 'roundRect',
    itemWidth: 10,
    itemHeight: 10,
    textStyle: { fontSize: 11 },
  },
  xAxis: {
    type: 'category',
    data: props.trend.map((point) => point.label),
    axisTick: { show: false },
    axisLine: { lineStyle: { color: 'rgba(120,120,140,0.25)' } },
    axisLabel: { fontSize: 11, color: 'rgba(120,120,140,1)' },
  },
  yAxis: {
    type: 'value',
    splitLine: { lineStyle: { color: 'rgba(120,120,140,0.12)' } },
    axisLabel: {
      fontSize: 11,
      color: 'rgba(120,120,140,1)',
      // One unit for the whole axis. Mixing "M" and "L" produced two ticks both labelled "2M"
      // (1.7M and 2.0M), which is worse than no axis at all.
      formatter: (value: number) => (value === 0
        ? '0'
        : value >= 1_000_000
          ? `${(value / 1_000_000).toFixed(1)}M`
          : `${Math.round(value / 1000)}k`),
    },
  },
  series: [
    {
      name: 'Employee share',
      type: 'bar',
      stack: 'contribution',
      barMaxWidth: 34,
      itemStyle: { color: '#3b82f6', borderRadius: [0, 0, 3, 3] },
      data: props.trend.map((point) => point.employee_side),
    },
    {
      name: 'Employer share',
      type: 'bar',
      stack: 'contribution',
      barMaxWidth: 34,
      itemStyle: { color: '#22c55e', borderRadius: [3, 3, 0, 0] },
      data: props.trend.map((point) => point.employer_side),
    },
  ],
}));

/** Composition of the liability: member share against employer share. */
const compositionOption = computed(() => {
  const employee = Number(props.composition.employee ?? 0);
  const employer = Number(props.composition.employer ?? 0);

  return {
    tooltip: { trigger: 'item', valueFormatter: (value: number) => money(value) },
    legend: { bottom: 0, icon: 'circle', itemWidth: 8, itemHeight: 8, textStyle: { fontSize: 11 } },
    series: [
      {
        type: 'pie',
        radius: ['58%', '80%'],
        center: ['50%', '44%'],
        avoidLabelOverlap: false,
        itemStyle: { borderWidth: 3, borderColor: 'transparent' },
        label: {
          show: true,
          position: 'center',
          formatter: () => figure(employee + employer),
          fontSize: 15,
          fontWeight: 700,
        },
        labelLine: { show: false },
        data: [
          { value: employee, name: 'Employee share', itemStyle: { color: '#3b82f6' } },
          { value: employer, name: 'Employer share', itemStyle: { color: '#22c55e' } },
        ],
      },
    ],
  };
});

const typeLabel = (type: string): string => ({
  partial: 'Partial withdrawal',
  final_settlement: 'Final settlement',
  loan_adjustment: 'Loan adjustment',
}[type] ?? type.replaceAll('_', ' '));
</script>

<template>
  <AdminLayout :breadcrumbs="[{ title: 'Payroll' }, { title: 'Provident Fund' }]">
    <Head title="Provident Fund" />

    <!-- Header -->
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="flex items-center gap-2.5 text-xl font-bold tracking-tight">
          <span class="grid size-9 place-items-center rounded-xl bg-gradient-to-br from-primary to-primary/70 text-primary-foreground shadow-sm">
            <Landmark class="size-5" />
          </span>
          Provident Fund
        </h1>
        <p class="mt-1 text-sm text-muted-foreground">
          Manage the fund, its members, contributions and withdrawals — accrued when a payroll month closes, settled when the cash arrives.
        </p>
      </div>
      <div class="flex flex-wrap gap-2">
        <Button size="sm" variant="outline" @click="router.visit(route('payroll.pf.index'))">
          <CalendarClock class="size-4" />Contribution register
        </Button>
        <Button size="sm" @click="router.visit(route('payroll.pf.withdrawals.index'))">
          <Wallet class="size-4" />Withdrawals
          <span v-if="summary.pending_withdrawals" class="ml-1 rounded-full bg-primary-foreground/20 px-1.5 text-[10px] font-bold">{{ summary.pending_withdrawals }}</span>
        </Button>
      </div>
    </div>

    <!-- Stat cards -->
    <div class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <div class="rounded-xl border bg-card p-5 shadow-sm">
        <div class="flex items-start justify-between">
          <span class="grid size-11 place-items-center rounded-xl bg-sky-500/10 text-sky-600 dark:text-sky-400"><Users class="size-5" /></span>
          <ArrowUpRight class="size-4 text-emerald-500" />
        </div>
        <p class="mt-3 text-2xl font-bold tabular-nums tracking-tight">{{ summary.accounts.toLocaleString() }}</p>
        <p class="text-[13px] font-medium text-muted-foreground">Fund members</p>
        <p class="mt-1 text-xs text-muted-foreground">
          <span :class="summary.unallocated_employees > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400'">
            {{ summary.unallocated_employees > 0 ? summary.unallocated_employees + ' not yet enrolled' : 'everyone enrolled' }}
          </span>
        </p>
      </div>

      <div class="rounded-xl border bg-card p-5 shadow-sm">
        <div class="flex items-start justify-between">
          <span class="grid size-11 place-items-center rounded-xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-400"><PiggyBank class="size-5" /></span>
        </div>
        <p class="mt-3 text-2xl font-bold tabular-nums tracking-tight">{{ figure(cash.liability) }}</p>
        <p class="text-[13px] font-medium text-muted-foreground">Total fund liability</p>
        <p class="mt-1 text-xs text-muted-foreground">{{ money(cash.received) }} collected</p>
      </div>

      <div class="rounded-xl border bg-card p-5 shadow-sm">
        <div class="flex items-start justify-between">
          <span class="grid size-11 place-items-center rounded-xl bg-violet-500/10 text-violet-600 dark:text-violet-400"><TrendingUp class="size-5" /></span>
        </div>
        <p class="mt-3 text-2xl font-bold tabular-nums tracking-tight">{{ figure(cash.outstanding) }}</p>
        <p class="text-[13px] font-medium text-muted-foreground">Awaiting collection</p>
        <p class="mt-1 text-xs text-muted-foreground">{{ cash.lines.toLocaleString() }} ledger entries</p>
      </div>

      <div class="rounded-xl border bg-card p-5 shadow-sm">
        <div class="flex items-start justify-between">
          <span class="grid size-11 place-items-center rounded-xl bg-amber-500/10 text-amber-600 dark:text-amber-400"><Wallet class="size-5" /></span>
        </div>
        <p class="mt-3 text-2xl font-bold tabular-nums tracking-tight">{{ summary.pending_withdrawals.toLocaleString() }}</p>
        <p class="text-[13px] font-medium text-muted-foreground">Pending requests</p>
        <button type="button" class="mt-1 inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline" @click="router.visit(route('payroll.pf.withdrawals.index'))">
          Review requests <ArrowRight class="size-3" />
        </button>
      </div>
    </div>

    <!-- Trend + recent -->
    <div class="mt-4 grid items-start gap-4 xl:grid-cols-5">
      <section class="rounded-xl border bg-card shadow-sm xl:col-span-3">
        <div class="flex flex-wrap items-start justify-between gap-3 border-b px-5 py-4">
          <div>
            <h2 class="flex items-center gap-2 text-sm font-semibold"><TrendingUp class="size-4 text-muted-foreground" />Contribution trend</h2>
            <p class="mt-0.5 text-xs text-muted-foreground">Recognised contributions per payroll month, both shares stacked.</p>
          </div>
          <span v-if="summary.last_period" class="rounded-md bg-muted px-2.5 py-1 text-xs text-muted-foreground">Last {{ monthLabel(summary.last_period) }}</span>
        </div>
        <div class="px-3 py-4">
          <BaseChart v-if="trend.length" :option="trendOption" height="290px" />
          <div v-else class="grid h-[290px] place-items-center text-center">
            <div>
              <TrendingUp class="mx-auto size-8 text-muted-foreground/40" />
              <p class="mt-2 text-sm font-medium">No contributions recognised yet</p>
              <p class="mt-1 text-xs text-muted-foreground">Accrue a released payroll month to start the trend.</p>
            </div>
          </div>
        </div>
      </section>

      <section class="rounded-xl border bg-card shadow-sm xl:col-span-2">
        <div class="flex items-start justify-between gap-3 border-b px-5 py-4">
          <div>
            <h2 class="flex items-center gap-2 text-sm font-semibold"><Wallet class="size-4 text-muted-foreground" />Recent requests</h2>
            <p class="mt-0.5 text-xs text-muted-foreground">The latest withdrawals and settlements.</p>
          </div>
          <button type="button" class="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline" @click="router.visit(route('payroll.pf.withdrawals.index'))">
            View all <ArrowRight class="size-3" />
          </button>
        </div>

        <div v-if="recent_withdrawals.length === 0" class="px-5 py-12 text-center">
          <Wallet class="mx-auto size-7 text-muted-foreground/40" />
          <p class="mt-2 text-sm font-medium">No requests yet</p>
          <p class="mt-1 text-xs text-muted-foreground">Requests are raised from a member's statement.</p>
        </div>

        <ul v-else class="divide-y">
          <li v-for="row in recent_withdrawals" :key="row.id">
            <button type="button" class="flex w-full items-center gap-3 px-5 py-3 text-left transition hover:bg-muted/40" @click="router.visit(route('payroll.pf.show', row.account_id))">
              <span class="grid size-8 shrink-0 place-items-center rounded-lg bg-muted text-[11px] font-bold">{{ (row.employee_name || row.employee_id).slice(0, 2).toUpperCase() }}</span>
              <span class="min-w-0 flex-1">
                <span class="block truncate text-[13px] font-medium leading-tight">{{ row.employee_name || row.employee_id }}</span>
                <span class="block truncate text-[11px] text-muted-foreground">{{ typeLabel(row.type) }} · {{ row.reason }}</span>
              </span>
              <span class="shrink-0 text-right">
                <span class="block text-[13px] font-semibold tabular-nums">{{ money(row.requested_amount) }}</span>
                <span class="mt-0.5 inline-block rounded px-1.5 py-px text-[10px] font-semibold uppercase tracking-wide" :class="statusTone(row.status)">{{ row.status }}</span>
              </span>
            </button>
          </li>
        </ul>
      </section>
    </div>

    <!-- Alerts + composition -->
    <div class="mt-4 grid items-start gap-4 xl:grid-cols-3">
      <section class="rounded-xl border bg-card shadow-sm xl:col-span-2">
        <div class="flex items-start justify-between gap-3 border-b px-5 py-4">
          <div>
            <h2 class="flex items-center gap-2 text-sm font-semibold"><CircleAlert class="size-4 text-muted-foreground" />Fund health</h2>
            <p class="mt-0.5 text-xs text-muted-foreground">What the books say, and what needs a decision.</p>
          </div>
          <span class="flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-medium" :class="(drift_count > 0 || (integrity?.orphan_entries ?? 0) > 0 || (integrity?.future_entries ?? 0) > 0) ? toneClass('error') : toneClass('ok')">
            <component :is="(drift_count > 0 || (integrity?.orphan_entries ?? 0) > 0 || (integrity?.future_entries ?? 0) > 0) ? CircleAlert : CircleCheck" class="size-3" />
            {{ (drift_count > 0 || (integrity?.orphan_entries ?? 0) > 0 || (integrity?.future_entries ?? 0) > 0) ? 'Does not reconcile' : 'Reconciled' }}
          </span>
        </div>

        <ul class="divide-y">
          <li v-for="(alert, i) in alerts" :key="i" class="flex items-center gap-3 px-5 py-3">
            <span class="grid size-7 shrink-0 place-items-center rounded-lg" :class="toneClass(alert.tone)">
              <component :is="alert.tone === 'ok' ? BadgeCheck : alert.tone === 'error' ? AlertTriangle : CircleAlert" class="size-3.5" />
            </span>
            <span class="min-w-0 flex-1 text-[13px] leading-snug">{{ alert.text }}</span>
            <button v-if="alert.to" type="button" class="inline-flex shrink-0 items-center gap-0.5 text-[11px] font-medium text-primary hover:underline" @click="router.visit(alert.to)">
              {{ alert.label }} <ChevronRight class="size-3" />
            </button>
          </li>
        </ul>
      </section>

      <section class="rounded-xl border bg-card shadow-sm">
        <div class="border-b px-5 py-4">
          <h2 class="text-sm font-semibold">Fund composition</h2>
          <p class="mt-0.5 text-xs text-muted-foreground">How the liability is split.</p>
        </div>
        <div class="px-4 py-3">
          <BaseChart :option="compositionOption" height="210px" />
          <dl class="mt-1 space-y-1.5 border-t pt-3 text-[12px]">
            <div class="flex items-center justify-between gap-3">
              <dt class="flex items-center gap-1.5 text-muted-foreground"><span class="size-2 rounded-full bg-[#3b82f6]"></span>Employee share</dt>
              <dd class="tabular-nums font-medium">{{ money(summary.employee_side) }}</dd>
            </div>
            <div class="flex items-center justify-between gap-3">
              <dt class="flex items-center gap-1.5 text-muted-foreground"><span class="size-2 rounded-full bg-[#22c55e]"></span>Employer share</dt>
              <dd class="tabular-nums font-medium">{{ money(summary.employer_side) }}</dd>
            </div>
            <div class="flex items-center justify-between gap-3">
              <dt class="text-muted-foreground">Paid out</dt>
              <dd class="tabular-nums font-medium">{{ money(cash.paid_out) }}</dd>
            </div>
          </dl>
        </div>
      </section>
    </div>

    <!-- Quick actions -->
    <section class="mt-4 rounded-xl border bg-card shadow-sm">
      <div class="border-b px-5 py-4">
        <h2 class="text-sm font-semibold">Quick actions</h2>
        <p class="mt-0.5 text-xs text-muted-foreground">Each of these lives on its own page, where the numbers around it are live.</p>
      </div>
      <div class="grid gap-3 p-5 sm:grid-cols-2 lg:grid-cols-4">
        <button type="button" class="flex items-center gap-3 rounded-xl border p-3.5 text-left transition hover:border-primary/40 hover:bg-primary/[0.03]" @click="router.visit(route('payroll.pf.index'))">
          <span class="grid size-9 shrink-0 place-items-center rounded-lg bg-sky-500/10 text-sky-600 dark:text-sky-400"><CalendarClock class="size-4" /></span>
          <span><span class="block text-[13px] font-medium">Accrue a month</span><span class="block text-[11px] text-muted-foreground">Recognise contributions</span></span>
        </button>
        <button type="button" class="flex items-center gap-3 rounded-xl border p-3.5 text-left transition hover:border-primary/40 hover:bg-primary/[0.03]" @click="router.visit(route('payroll.pf.members'))">
          <span class="grid size-9 shrink-0 place-items-center rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400"><Users class="size-4" /></span>
          <span><span class="block text-[13px] font-medium">Enrol members</span><span class="block text-[11px] text-muted-foreground">{{ summary.unallocated_employees }} to allocate</span></span>
        </button>
        <button type="button" class="flex items-center gap-3 rounded-xl border p-3.5 text-left transition hover:border-primary/40 hover:bg-primary/[0.03]" @click="router.visit(route('payroll.pf.withdrawals.index'))">
          <span class="grid size-9 shrink-0 place-items-center rounded-lg bg-amber-500/10 text-amber-600 dark:text-amber-400"><Wallet class="size-4" /></span>
          <span><span class="block text-[13px] font-medium">Decide requests</span><span class="block text-[11px] text-muted-foreground">{{ summary.pending_withdrawals }} pending</span></span>
        </button>
        <button type="button" class="flex items-center gap-3 rounded-xl border p-3.5 text-left transition hover:border-primary/40 hover:bg-primary/[0.03]" @click="router.visit(route('payroll.pf.index'))">
          <span class="grid size-9 shrink-0 place-items-center rounded-lg bg-violet-500/10 text-violet-600 dark:text-violet-400"><BadgeCheck class="size-4" /></span>
          <span><span class="block text-[13px] font-medium">Settle a month</span><span class="block text-[11px] text-muted-foreground">Mark cash as received</span></span>
        </button>
      </div>
    </section>
  </AdminLayout>
</template>
