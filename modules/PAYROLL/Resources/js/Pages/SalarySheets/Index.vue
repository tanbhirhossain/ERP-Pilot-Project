<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import {
  AlertTriangle,
  ArrowRight,
  CalendarDays,
  CircleCheck,
  Clock,
  FileStack,
  Landmark,
  PlusCircle,
  TrendingUp,
  Users,
  Wallet,
} from 'lucide-vue-next';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import DataTable, { type DataTableColumn } from '@core/Components/DataTable.vue';
import BaseChart from '@core/Components/BaseChart.vue';
import StatusBadge from '@core/Components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import ModuleNav from './ModuleNav.vue';
import type { Paginator } from '@core/types';
import { formatCurrency, formatDateTime, formatNumber } from '@core/Utils/format';

/**
 * The payroll desk.
 *
 * This page used to be the run list and nothing else — a status pill, two totals and a link, which
 * answers "which runs exist" and leaves "what does the month cost, and is anything wrong with it"
 * to whoever opens each sheet in turn. For a hospital running 981 slips a month that is the wrong
 * question to be able to answer cheaply.
 *
 * So the list stays, because it is the only way to reach an old month, and everything above it is
 * the reading a controller wants before opening anything: what is committed, what is still
 * unreleased, where it went, and what has changed since the last run.
 *
 * Every figure here comes from slips rather than from the sheet's cached totals, so it survives a
 * re-priced month — the state this database is in right now.
 */

interface WorkspaceSheet {
  id: number;
  pay_year: number;
  pay_month: number;
  period: string;
  status: string;
  include_punishments: boolean;
  created_at: string | null;
  finished_at: string | null;
  slips: number;
  gross: number;
  deductions: number;
  net: number;
  withheld_tax: number;
  paid_slips: number;
  paid_amount: number;
  held_slips: number;
  unpaid_slips: number;
  failure_message: string | null;
  accrual_posted: boolean;
  has_cutlist: boolean;
}

interface Workspace {
  sheets: WorkspaceSheet[];
  current: WorkspaceSheet | null;
  previous: WorkspaceSheet | null;
  counts: { sheets: number; ready: number; released: number; failed: number };
  totals: {
    headcount: number;
    gross: number;
    deductions: number;
    net: number;
    withheld_tax: number;
    unreleased_net: number;
    waiting_slips: number;
    unpaid_net: number;
  };
}

interface TrendPoint {
  id: number;
  period: string;
  short: string;
  status: string;
  slips: number;
  gross: number;
  net: number;
  average: number;
}

interface Row {
  id: number;
  pay_year: number;
  pay_month: number;
  status: string;
  employee_count: number;
  processed_count: number;
  gross_total: string;
  net_total: string;
  slips_count: number;
}

const props = defineProps<{
  sheets: Paginator<Row>;
  workspace: Workspace;
  trend: TrendPoint[];
  canCreate: boolean;
}>();

const money = (value: number | string | null | undefined): string => formatCurrency(Number(value ?? 0));

/** A stat card is read as a size, not reconciled — ৳1.40 Cr beats ৳14,011,199.45 at 24px. */
function compact(value: number): string {
  const v = Number(value ?? 0);
  if (v >= 10_000_000) return `${(v / 10_000_000).toFixed(2)} Cr`;
  if (v >= 100_000) return `${(v / 100_000).toFixed(2)} Lakh`;
  return money(v);
}

const current = computed(() => props.workspace.current);
const totals = computed(() => props.workspace.totals);

/**
 * Change against the previous run, per head.
 *
 * Comparing total payroll month-on-month is misleading when the headcount also moved: a ৳2 lakh
 * increase across 40 new employees is not a pay rise, it is 40 more people. So the comparison is
 * average net per slip, which is the number that actually responds to a salary revision.
 */
const perHead = computed(() => {
  const now = current.value;
  const before = props.workspace.previous;
  if (!now || !before || now.slips === 0 || before.slips === 0) return null;
  const a = now.net / now.slips;
  const b = before.net / before.slips;
  return { now: a, before: b, delta: a - b, pct: b > 0 ? ((a - b) / b) * 100 : 0 };
});

type Tone = 'ok' | 'info' | 'warn' | 'error';

function toneClass(tone: Tone): string {
  return {
    ok: 'bg-success/10 text-success',
    info: 'bg-info/10 text-info',
    warn: 'bg-warning/10 text-warning',
    error: 'bg-danger/10 text-danger',
  }[tone];
}

interface Signal {
  tone: Tone;
  text: string;
  to?: string;
  label?: string;
}

/** What a payroll controller should know before opening anything. */
const signals = computed<Signal[]>(() => {
  const list: Signal[] = [];
  const sheet = current.value;
  if (!sheet) {
    return [{ tone: 'info', text: 'No payroll run has been computed yet. Start one and the desk will report it here.' }];
  }

  const ready = props.workspace.sheets.filter((s) => s.status === 'ready');
  if (ready.length > 0) {
    const amount = ready.reduce((sum, s) => sum + s.net, 0);
    const slips = ready.reduce((sum, s) => sum + s.slips, 0);
    list.push({
      tone: 'warn',
      text: `${formatNumber(slips)} slip(s) worth ${money(amount)} are computed and not yet released, so no payment action is open on them.`,
      to: route('payroll.sheets.release-check', ready[0]!.id),
      label: 'Release desk',
    });
  }

  const failed = props.workspace.sheets.filter((s) => s.status === 'failed');
  if (failed.length > 0) {
    list.push({
      tone: 'error',
      text: `${failed.length} run(s) failed: ${failed.map((s) => s.period).join(', ')}. A failed run produces no payable slips.`,
      to: route('payroll.sheets.show', failed[0]!.id),
      label: 'Open',
    });
  }

  if (sheet.withheld_tax <= 0) {
    list.push({
      tone: 'error',
      text: `${sheet.period} withholds no income tax at all across ${sheet.slips.toLocaleString()} slips. Either every employee is below the exemption floor, or payroll ran with no usable tax policy.`,
      to: '/payroll/tax',
      label: 'Tax configuration',
    });
  }

  if (!sheet.accrual_posted && sheet.status === 'released') {
    list.push({
      tone: 'warn',
      text: `${sheet.period} is released but no accrual has been posted, so the ledger does not know about ${money(sheet.net)} of salary yet.`,
      to: route('payroll.finance.sheets.show', sheet.id),
      label: 'Finance bridge',
    });
  }

  if (list.length === 0) {
    list.push({ tone: 'ok', text: `Nothing is waiting: ${sheet.period} is released and every figure agrees with its slips.` });
  }

  return list;
});

/**
 * A single month, decomposed.
 *
 * Used instead of the chart while only one run exists. The three slices are the same three figures
 * the sheet page reconciles, so the overview and the sheet cannot tell different stories.
 */
const composition = computed(() => {
  const sheet = current.value;
  if (!sheet || sheet.gross <= 0) return [];
  const pct = (value: number): number => Math.round((value / sheet.gross) * 1000) / 10;
  return [
    { label: 'Gross earnings', value: sheet.gross, pct: 100, bar: 'bg-slate-400', note: 'before every deduction' },
    { label: 'Deductions', value: sheet.deductions, pct: pct(sheet.deductions), bar: 'bg-amber-500', note: `${pct(sheet.deductions).toFixed(1)}% of gross, incl. ${money(sheet.withheld_tax)} tax` },
    { label: 'Net payable', value: sheet.net, pct: pct(sheet.net), bar: 'bg-primary', note: `${pct(sheet.net).toFixed(1)}% of gross actually paid out` },
  ];
});

const statusTone = (status: string): 'neutral' | 'info' | 'success' | 'warning' | 'danger' => ({
  ready: 'warning',
  released: 'success',
  failed: 'danger',
  queued: 'info',
  processing: 'info',
  draft: 'neutral',
}[status] as 'neutral' | 'info' | 'success' | 'warning' | 'danger' ?? 'neutral');

/* -------------------------------- chart ---------------------------------- */

/**
 * One unit for the whole axis.
 *
 * The advance module learned this the expensive way: mixing M and L produced two ticks both labelled
 * "2M". A payroll month is seven figures here, so the axis is in lakh throughout and the tooltip
 * carries the exact amount.
 */
const trendOption = computed(() => ({
  grid: { left: 8, right: 14, top: 30, bottom: 4, containLabel: true },
  tooltip: { trigger: 'axis', valueFormatter: (value: number) => money(value) },
  legend: { top: 0, right: 0, itemWidth: 10, itemHeight: 10, textStyle: { fontSize: 11 } },
  xAxis: {
    type: 'category',
    data: props.trend.map((point) => point.short),
    axisTick: { show: false },
    axisLine: { lineStyle: { color: 'rgba(120,120,120,0.25)' } },
    axisLabel: { fontSize: 11 },
  },
  yAxis: {
    type: 'value',
    splitLine: { lineStyle: { color: 'rgba(120,120,120,0.12)' } },
    axisLabel: {
      fontSize: 11,
      formatter: (value: number) => (value === 0 ? '0' : `${(value / 100_000).toFixed(0)}L`),
    },
  },
  series: [
    {
      name: 'Net payable',
      type: 'bar',
      data: props.trend.map((point) => point.net),
      itemStyle: { color: '#6366f1', borderRadius: [4, 4, 0, 0] },
      barMaxWidth: 34,
    },
    {
      name: 'Gross',
      type: 'line',
      smooth: true,
      data: props.trend.map((point) => point.gross),
      lineStyle: { width: 2, color: '#94a3b8' },
      itemStyle: { color: '#94a3b8' },
      symbolSize: 5,
    },
  ],
}));

/* -------------------------------- tables --------------------------------- */

const columns: DataTableColumn[] = [
  { key: 'period', label: 'Pay period', sortable: false },
  { key: 'status', label: 'Status' },
  { key: 'slips_count', label: 'Slips', align: 'right' },
  { key: 'gross_total', label: 'Gross', align: 'right' },
  { key: 'net_total', label: 'Net payable', align: 'right' },
  { key: 'actions', label: '', align: 'right', exportable: false },
];
</script>

<template>
  <AdminLayout :breadcrumbs="[{ title: 'Payroll' }, { title: 'Salary sheets' }]">
    <Head title="Salary sheets" />

    <div class="space-y-4">
      <ModuleNav />

      <!-- Header -->
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 class="flex items-center gap-2.5 text-xl font-bold tracking-tight">
            <span class="grid size-9 place-items-center rounded-xl bg-gradient-to-br from-primary to-primary/70 text-primary-foreground shadow-sm">
              <CalendarDays class="size-5" />
            </span>
            Monthly payroll
          </h1>
          <p class="mt-1 max-w-3xl text-sm text-muted-foreground">
            Every monthly run, what it committed, and what is still waiting to be released. Figures are summed
            from the payslips themselves, so a month that was re-priced reports what it will actually pay.
          </p>
        </div>
        <div class="flex flex-wrap gap-2">
          <Button v-if="current" size="sm" variant="outline" @click="router.visit(route('payroll.sheets.show', current.id))">
            <FileStack class="size-4" />Open {{ current.period }}
          </Button>
          <Button v-if="canCreate" size="sm" @click="router.visit(route('payroll.sheets.create'))">
            <PlusCircle class="size-4" />New run
          </Button>
        </div>
      </div>

      <!-- Figures: the whole book -->
      <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-xl border bg-card p-5 shadow-sm">
          <div class="flex items-start justify-between">
            <span class="grid size-11 place-items-center rounded-xl bg-info/10 text-info"><Wallet class="size-5" /></span>
            <span v-if="perHead" class="rounded-full px-2 py-0.5 text-[11px] font-medium" :class="perHead.delta >= 0 ? toneClass('warn') : toneClass('ok')">
              {{ perHead.delta >= 0 ? '+' : '' }}{{ perHead.pct.toFixed(1) }}% per head
            </span>
          </div>
          <p class="mt-3 text-2xl font-bold tabular-nums tracking-tight">{{ compact(totals.net) }}</p>
          <p class="text-[13px] font-medium text-muted-foreground">Net payable, all months</p>
          <p class="mt-1 text-xs text-muted-foreground">{{ money(totals.gross) }} gross across {{ workspace.counts.sheets }} run(s)</p>
        </div>

        <div class="rounded-xl border bg-card p-5 shadow-sm">
          <div class="flex items-start justify-between">
            <span class="grid size-11 place-items-center rounded-xl bg-warning/10 text-warning"><Clock class="size-5" /></span>
            <button
              v-if="totals.waiting_slips > 0 && workspace.sheets.find((s) => s.status === 'ready')"
              type="button"
              class="inline-flex items-center gap-0.5 text-[11px] font-medium text-primary hover:underline"
              @click="router.visit(route('payroll.sheets.release-check', workspace.sheets.find((s) => s.status === 'ready')!.id))"
            >
              Release <ArrowRight class="size-3" />
            </button>
          </div>
          <p class="mt-3 text-2xl font-bold tabular-nums tracking-tight">{{ formatNumber(totals.waiting_slips) }}</p>
          <p class="text-[13px] font-medium text-muted-foreground">Slips awaiting release</p>
          <p class="mt-1 text-xs text-muted-foreground">{{ money(totals.unreleased_net) }} still to become payable</p>
        </div>

        <div class="rounded-xl border bg-card p-5 shadow-sm">
          <div class="flex items-start justify-between">
            <span class="grid size-11 place-items-center rounded-xl bg-violet-500/10 text-violet-600 dark:text-violet-400"><Users class="size-5" /></span>
            <span v-if="current" class="text-[11px] text-muted-foreground">{{ current.period }}</span>
          </div>
          <p class="mt-3 text-2xl font-bold tabular-nums tracking-tight">{{ money(current ? current.net / Math.max(current.slips, 1) : 0) }}</p>
          <p class="text-[13px] font-medium text-muted-foreground">Average net per slip</p>
          <p class="mt-1 text-xs text-muted-foreground">{{ totals.headcount.toLocaleString() }} employee(s) on the largest run</p>
        </div>

        <div class="rounded-xl border bg-card p-5 shadow-sm">
          <div class="flex items-start justify-between">
            <span class="grid size-11 place-items-center rounded-xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-400"><Landmark class="size-5" /></span>
          </div>
          <p class="mt-3 text-2xl font-bold tabular-nums tracking-tight">{{ money(totals.withheld_tax) }}</p>
          <p class="text-[13px] font-medium text-muted-foreground">Income tax withheld</p>
          <p class="mt-1 text-xs text-muted-foreground">Across every computed month, from the payslip snapshots</p>
        </div>
      </div>

      <!-- Chart + signals -->
      <div class="grid items-start gap-4 xl:grid-cols-5">
        <section class="rounded-xl border bg-card shadow-sm xl:col-span-3">
          <div class="flex flex-wrap items-start justify-between gap-3 border-b px-5 py-4">
            <div>
              <h2 class="flex items-center gap-2 text-sm font-semibold">
                <component :is="trend.length > 1 ? TrendingUp : Wallet" class="size-4 text-muted-foreground" />
                {{ trend.length > 1 ? 'Payroll by month' : 'What this month is made of' }}
              </h2>
              <p class="mt-0.5 text-xs text-muted-foreground">
                {{ trend.length > 1
                  ? 'Net payable against gross, per run. One unit on the axis — lakh.'
                  : 'Gross, deductions and net for the only computed run, since one month is not a trend.' }}
              </p>
            </div>
          </div>
          <div class="px-3 py-4">
            <BaseChart v-if="trend.length > 1" :option="trendOption" height="286px" />
            <div v-else-if="current" class="grid gap-3 p-2 sm:grid-cols-3">
              <div v-for="slice in composition" :key="slice.label" class="rounded-xl border bg-muted/20 p-4">
                <p class="text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">{{ slice.label }}</p>
                <p class="mt-1 font-mono text-lg font-bold tabular-nums">{{ money(slice.value) }}</p>
                <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-muted">
                  <div class="h-full rounded-full" :class="slice.bar" :style="{ width: `${slice.pct}%` }" />
                </div>
                <p class="mt-1.5 text-[11px] text-muted-foreground">{{ slice.note }}</p>
              </div>
              <p class="text-[11px] leading-relaxed text-muted-foreground sm:col-span-3">
                One run so far, so there is no trend to draw yet. This is what {{ current.period }} is made of; the chart appears once a
                second month is computed.
              </p>
            </div>
            <div v-else class="grid h-[286px] place-items-center text-center">
              <div>
                <CalendarDays class="mx-auto size-8 text-muted-foreground/40" />
                <p class="mt-2 text-sm font-medium">No runs yet</p>
                <p class="mt-1 text-xs text-muted-foreground">Compute a month and it appears here.</p>
              </div>
            </div>
          </div>
        </section>

        <section class="rounded-xl border bg-card shadow-sm xl:col-span-2">
          <div class="flex items-start justify-between gap-3 border-b px-5 py-4">
            <div>
              <h2 class="flex items-center gap-2 text-sm font-semibold"><AlertTriangle class="size-4 text-muted-foreground" />Needs attention</h2>
              <p class="mt-0.5 text-xs text-muted-foreground">What the desk would flag before closing the month.</p>
            </div>
            <span
              class="flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-medium"
              :class="signals.some((s) => s.tone === 'error') ? toneClass('error') : signals.some((s) => s.tone === 'warn') ? toneClass('warn') : toneClass('ok')"
            >
              <component :is="signals.some((s) => s.tone === 'error' || s.tone === 'warn') ? AlertTriangle : CircleCheck" class="size-3" />
              {{ signals.some((s) => s.tone === 'error') ? 'Action needed' : signals.some((s) => s.tone === 'warn') ? 'Review' : 'Clear' }}
            </span>
          </div>
          <ul class="divide-y">
            <li v-for="(signal, i) in signals" :key="i" class="flex items-start gap-3 px-5 py-3">
              <span class="mt-0.5 grid size-7 shrink-0 place-items-center rounded-lg" :class="toneClass(signal.tone)">
                <component :is="signal.tone === 'ok' ? CircleCheck : AlertTriangle" class="size-3.5" />
              </span>
              <span class="min-w-0 flex-1 text-[13px] leading-snug">{{ signal.text }}</span>
              <button
                v-if="signal.to"
                type="button"
                class="mt-0.5 inline-flex shrink-0 items-center gap-0.5 text-[11px] font-medium text-primary hover:underline"
                @click="router.visit(signal.to)"
              >
                {{ signal.label }} <ArrowRight class="size-3" />
              </button>
            </li>
          </ul>
        </section>
      </div>

      <!-- Run list -->
      <DataTable
        route-name="payroll.sheets.index"
        title="Payroll runs"
        :columns="columns"
        :rows="(sheets.data as any)"
        :meta="sheets.meta"
        empty-title="No payroll has been computed"
        empty-description="A run turns attendance, salary history and every policy into one payable slip per employee."
      >
        <template #cell-period="{ row }">
          <div>
            <span class="font-medium">{{ new Date(row.pay_year, row.pay_month - 1, 1).toLocaleDateString('en-GB', { month: 'long', year: 'numeric' }) }}</span>
            <span class="block text-xs text-muted-foreground">{{ row.pay_year }}-{{ String(row.pay_month).padStart(2, '0') }}</span>
          </div>
        </template>
        <template #cell-status="{ row }">
          <StatusBadge :label="row.status" :tone="statusTone(row.status)" dot />
        </template>
        <template #cell-slips_count="{ row }">
          {{ Number(row.slips_count || row.processed_count || 0).toLocaleString() }} / {{ Number(row.employee_count || 0).toLocaleString() }}
        </template>
        <template #cell-gross_total="{ row }">{{ money(row.gross_total) }}</template>
        <template #cell-net_total="{ row }"><strong>{{ money(row.net_total) }}</strong></template>
        <template #cell-actions="{ row }">
          <div class="flex items-center justify-end gap-3">
            <button type="button" class="text-xs font-medium text-muted-foreground hover:text-foreground" @click="router.visit(route('payroll.sheets.departments', row.id))">
              Departments
            </button>
            <button type="button" class="text-xs font-medium text-primary hover:underline" @click="router.visit(route('payroll.sheets.show', row.id))">
              Open →
            </button>
          </div>
        </template>
      </DataTable>
    </div>
  </AdminLayout>
</template>
