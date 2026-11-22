<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import {
  ArrowLeft,
  ArrowRight,
  Building2,
  FileStack,
  PieChart,
  Printer,
  TrendingUp,
  Users,
  Wallet,
  X,
} from 'lucide-vue-next';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import BaseChart from '@core/Components/BaseChart.vue';
import StatusBadge from '@core/Components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import ModuleNav from './ModuleNav.vue';
import { printDocument } from '@core/Composables/usePrint';
import { useReportPdf } from '@core/Composables/useReportPdf';
import { formatCurrency, formatNumber } from '@core/Utils/format';

/**
 * Where the month's money went.
 *
 * The sheet page answers "is this payroll right"; this one answers "is this payroll *fair*" — which
 * department is carrying the cost, whether a unit's share of the money matches its share of the
 * people, and who inside it is at the top of the scale.
 *
 * Two deliberate choices:
 *
 *  - **Headcount share and money share are columns, not a single bar.** A department with 3% of the
 *    staff and 12% of the net is a finding; a single percentage would hide one of the two facts that
 *    produces it.
 *  - **The drill-down is a URL, not a modal.** Somebody will want to send "the 26 people in
 *    Maintenance" to a colleague, and a modal cannot be linked.
 */

interface Row {
  department: string;
  slips: number;
  gross: number;
  deductions: number;
  net: number;
  tax: number;
  headcount_pct: number;
  net_pct: number;
  average_net: number;
  gross_share_pct: number;
}

interface Totals {
  departments: number;
  slips: number;
  gross: number;
  deductions: number;
  net: number;
  tax: number;
  average_net: number;
}

interface DrillRow {
  id: number;
  employee_id: string;
  employee_name: string;
  designation: string;
  gross: number;
  deductions: number;
  net: number;
}

const props = defineProps<{
  sheet: { id: number; pay_year: number; pay_month: number; status: string; employee_count: number };
  context: { period: string; aggregate: { slips: number; gross: number; deductions: number; net: number; tax: number } };
  departments: { rows: Row[]; totals: Totals };
  selected: string | null;
  drilldown: DrillRow[];
  canRelease: boolean;
}>();

const money = (value: number | string | null | undefined): string => formatCurrency(Number(value ?? 0));

const totals = computed(() => props.departments.totals);
const rows = computed(() => props.departments.rows);

const open = (department: string): void => {
  router.visit(route('payroll.sheets.departments', props.sheet.id), { data: { department }, preserveScroll: true, preserveState: true });
};

const close = (): void => {
  router.visit(route('payroll.sheets.departments', props.sheet.id), { preserveScroll: true, preserveState: true });
};

/**
 * The distribution, not a pie.
 *
 * A pie of 25 departments is unreadable, and this module already learned that lesson on the fund
 * dashboard. Top ten by net, one unit on the axis, the rest folded into a single "remaining" bar so
 * the chart and the table agree on the total.
 */
const chartOption = computed(() => {
  const top = [...rows.value].slice(0, 10);
  const restNet = rows.value.slice(10).reduce((sum, row) => sum + row.net, 0);
  const labels = [...top.map((row) => row.department), ...(restNet > 0 ? [`${rows.value.length - 10} more`] : [])];
  const values = [...top.map((row) => row.net), ...(restNet > 0 ? [restNet] : [])];

  return {
    grid: { left: 8, right: 18, top: 18, bottom: 8, containLabel: true },
    tooltip: { trigger: 'axis', axisPointer: { type: 'shadow' }, valueFormatter: (v: number) => money(v) },
    xAxis: {
      type: 'value',
      splitLine: { lineStyle: { color: 'rgba(120,120,120,0.12)' } },
      axisLabel: { fontSize: 11, formatter: (v: number) => (v === 0 ? '0' : `${(v / 100_000).toFixed(0)}L`) },
    },
    yAxis: {
      type: 'category',
      inverse: true,
      data: labels,
      axisTick: { show: false },
      axisLine: { show: false },
      axisLabel: { fontSize: 11, width: 130, overflow: 'truncate' },
    },
    series: [
      {
        type: 'bar',
        data: values,
        itemStyle: { color: '#6366f1', borderRadius: [0, 4, 4, 0] },
        barMaxWidth: 18,
        label: { show: true, position: 'right', fontSize: 10, formatter: (p: { value: number }) => `${(p.value / 100_000).toFixed(1)}L` },
      },
    ],
  };
});

const drilldownTotal = computed(() => props.drilldown.reduce((sum, row) => sum + row.net, 0));

const { downloadPdf } = useReportPdf();
const pdfPayload = computed(() => ({
    title: `Payroll by department — ${props.context.period}`,
    permission: 'payroll.sheets.manage',
    orientation: 'landscape',
    meta: {
        'Pay period': props.context.period,
        'Departments': String(totals.value.departments),
        'Slips': String(totals.value.slips),
        'Gross': money(totals.value.gross),
        'Net payable': money(totals.value.net),
    },
    headings: ['Department', 'Slips', 'Gross', 'Deductions', 'Net', 'Tax'],
    rows: rows.value.map((r: any) => [r.department, r.slips, r.gross, r.deductions, r.net, r.tax]),
    footer: 'Payroll by department · Payroll',
}));

const print = (): void => {
  printDocument({
    title: `Payroll by department — ${props.context.period}`,
    region: 'sheet-departments',
    meta: [
      { label: 'Pay period', value: props.context.period },
      { label: 'Departments', value: String(totals.value.departments) },
      { label: 'Net payable', value: money(totals.value.net) },
      { label: 'Headcount', value: formatNumber(totals.value.slips) },
    ],
  });
};
</script>

<template>
  <AdminLayout
    :breadcrumbs="[
      { title: 'Payroll' },
      { title: 'Salary sheets', href: route('payroll.sheets.index') },
      { title: context.period, href: route('payroll.sheets.show', sheet.id) },
      { title: 'Departments' },
    ]"
  >
    <Head :title="`Departments — ${context.period}`" />

    <div class="space-y-4">
      <ModuleNav />

      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <button type="button" class="mb-1 flex items-center gap-1 text-[11px] text-muted-foreground hover:text-foreground" @click="router.visit(route('payroll.sheets.show', sheet.id))">
            <ArrowLeft class="size-3" />{{ context.period }}
          </button>
          <h1 class="flex items-center gap-2.5 text-xl font-bold tracking-tight">
            <span class="grid size-9 place-items-center rounded-xl bg-gradient-to-br from-primary to-primary/70 text-primary-foreground shadow-sm">
              <PieChart class="size-5" />
            </span>
            Where the money went
          </h1>
          <p class="mt-1 text-sm text-muted-foreground">
            {{ totals.departments }} cost centre(s) carrying {{ formatNumber(totals.slips) }} payslip(s) in {{ context.period }},
            {{ money(totals.net) }} net payable.
          </p>
        </div>
        <div class="flex flex-wrap gap-2">
          <Button size="sm" class="rounded-full" @click="downloadPdf(pdfPayload)"><FileDown class="size-4" />Download PDF</Button>
          <Button size="sm" variant="outline" @click="print"><Printer class="size-4" />Print</Button>
          <Button size="sm" variant="outline" @click="router.visit(route('payroll.sheets.release-check', sheet.id))">
            <FileStack class="size-4" />Release desk
          </Button>
        </div>
      </div>

      <!-- Figures -->
      <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4 print:hidden">
        <div class="rounded-xl border bg-card p-5 shadow-sm">
          <span class="grid size-11 place-items-center rounded-xl bg-info/10 text-info"><Wallet class="size-5" /></span>
          <p class="mt-3 text-2xl font-bold tabular-nums tracking-tight">{{ money(totals.net) }}</p>
          <p class="text-[13px] font-medium text-muted-foreground">Net payable</p>
        </div>
        <div class="rounded-xl border bg-card p-5 shadow-sm">
          <span class="grid size-11 place-items-center rounded-xl bg-violet-500/10 text-violet-600 dark:text-violet-400"><Users class="size-5" /></span>
          <p class="mt-3 text-2xl font-bold tabular-nums tracking-tight">{{ formatNumber(totals.slips) }}</p>
          <p class="text-[13px] font-medium text-muted-foreground">Payslips</p>
          <p class="mt-1 text-xs text-muted-foreground">across {{ totals.departments }} cost centre(s)</p>
        </div>
        <div class="rounded-xl border bg-card p-5 shadow-sm">
          <span class="grid size-11 place-items-center rounded-xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-400"><TrendingUp class="size-5" /></span>
          <p class="mt-3 text-2xl font-bold tabular-nums tracking-tight">{{ money(totals.average_net) }}</p>
          <p class="text-[13px] font-medium text-muted-foreground">Average net per slip</p>
        </div>
        <div class="rounded-xl border bg-card p-5 shadow-sm">
          <span class="grid size-11 place-items-center rounded-xl bg-warning/10 text-warning"><Building2 class="size-5" /></span>
          <p class="mt-3 text-2xl font-bold tabular-nums tracking-tight">{{ money(totals.tax) }}</p>
          <p class="text-[13px] font-medium text-muted-foreground">Income tax withheld</p>
          <p class="mt-1 text-xs text-muted-foreground">summed from the payslip snapshots</p>
        </div>
      </div>

      <!-- Chart -->
      <section class="rounded-xl border bg-card shadow-sm print:hidden">
        <div class="border-b px-5 py-4">
          <h2 class="flex items-center gap-2 text-sm font-semibold"><TrendingUp class="size-4 text-muted-foreground" />Net payable by cost centre</h2>
          <p class="mt-0.5 text-xs text-muted-foreground">Top ten, with the remainder folded so the bars add up to the month.</p>
        </div>
        <div class="px-3 py-4">
          <BaseChart v-if="rows.length" :option="chartOption" height="320px" />
        </div>
      </section>

      <!-- Table -->
      <section data-core-print="sheet-departments" class="rounded-xl border bg-card shadow-sm">
        <div class="flex flex-wrap items-start justify-between gap-3 border-b px-5 py-4">
          <div>
            <h2 class="flex items-center gap-2 text-sm font-semibold"><Building2 class="size-4 text-muted-foreground" />Cost centres</h2>
            <p class="mt-0.5 text-xs text-muted-foreground">Headcount share against money share. Click a row to see the people inside it.</p>
          </div>
          <span class="rounded-md bg-muted px-2.5 py-1 text-xs text-muted-foreground">{{ context.period }}</span>
        </div>

        <div class="overflow-x-auto">
          <table class="w-full text-[13px]">
            <thead>
              <tr class="border-b bg-muted/40 text-left text-[11px] uppercase tracking-wider text-muted-foreground">
                <th class="px-5 py-2.5 font-semibold">Department</th>
                <th class="px-3 py-2.5 text-right font-semibold">Slips</th>
                <th class="px-3 py-2.5 text-right font-semibold">Headcount</th>
                <th class="px-3 py-2.5 text-right font-semibold">Gross</th>
                <th class="px-3 py-2.5 text-right font-semibold">Deductions</th>
                <th class="px-3 py-2.5 text-right font-semibold">Net payable</th>
                <th class="px-3 py-2.5 text-right font-semibold">Share of net</th>
                <th class="px-3 py-2.5 text-right font-semibold">Average</th>
                <th class="px-5 py-2.5 print:hidden" />
              </tr>
            </thead>
            <tbody class="divide-y">
              <tr
                v-for="row in rows"
                :key="row.department"
                class="cursor-pointer transition-colors hover:bg-muted/40"
                :class="selected === row.department ? 'bg-primary/5' : ''"
                @click="open(row.department)"
              >
                <td class="px-5 py-2.5 font-medium">{{ row.department }}</td>
                <td class="px-3 py-2.5 text-right tabular-nums">{{ formatNumber(row.slips) }}</td>
                <td class="px-3 py-2.5 text-right tabular-nums text-muted-foreground">{{ row.headcount_pct }}%</td>
                <td class="px-3 py-2.5 text-right tabular-nums text-muted-foreground">{{ money(row.gross) }}</td>
                <td class="px-3 py-2.5 text-right tabular-nums text-muted-foreground">{{ money(row.deductions) }}</td>
                <td class="px-3 py-2.5 text-right font-semibold tabular-nums">{{ money(row.net) }}</td>
                <td class="px-3 py-2.5">
                  <div class="flex items-center justify-end gap-2">
                    <div class="h-1.5 w-16 overflow-hidden rounded-full bg-muted print:hidden">
                      <div class="h-full rounded-full bg-primary/70" :style="{ width: `${row.net_pct}%` }" />
                    </div>
                    <span class="w-10 text-right tabular-nums text-muted-foreground">{{ row.net_pct }}%</span>
                  </div>
                </td>
                <td class="px-3 py-2.5 text-right tabular-nums">{{ money(row.average_net) }}</td>
                <td class="px-5 py-2.5 text-right print:hidden"><ArrowRight class="ml-auto size-3.5 text-muted-foreground" /></td>
              </tr>
            </tbody>
            <tfoot>
              <tr class="border-t bg-muted/40 font-semibold">
                <td class="px-5 py-2.5">Total</td>
                <td class="px-3 py-2.5 text-right tabular-nums">{{ formatNumber(totals.slips) }}</td>
                <td class="px-3 py-2.5 text-right tabular-nums">100%</td>
                <td class="px-3 py-2.5 text-right tabular-nums">{{ money(totals.gross) }}</td>
                <td class="px-3 py-2.5 text-right tabular-nums">{{ money(totals.deductions) }}</td>
                <td class="px-3 py-2.5 text-right tabular-nums">{{ money(totals.net) }}</td>
                <td class="px-3 py-2.5 text-right tabular-nums">100%</td>
                <td class="px-3 py-2.5 text-right tabular-nums">{{ money(totals.average_net) }}</td>
                <td class="print:hidden" />
              </tr>
            </tfoot>
          </table>
        </div>
      </section>

      <!-- Drill-down -->
      <section v-if="selected" class="rounded-xl border bg-card shadow-sm">
        <div class="flex flex-wrap items-start justify-between gap-3 border-b px-5 py-4">
          <div>
            <h2 class="flex items-center gap-2 text-sm font-semibold"><Users class="size-4 text-muted-foreground" />{{ selected }}</h2>
            <p class="mt-0.5 text-xs text-muted-foreground">
              {{ drilldown.length }} payslip(s) shown, {{ money(drilldownTotal) }} of net payable — highest paid first.
            </p>
          </div>
          <Button size="sm" variant="outline" @click="close"><X class="size-4" />Close</Button>
        </div>
        <div class="max-h-[28rem] overflow-y-auto">
          <table class="w-full text-[13px]">
            <thead class="sticky top-0 bg-card">
              <tr class="border-b text-left text-[11px] uppercase tracking-wider text-muted-foreground">
                <th class="px-5 py-2 font-semibold">Employee</th>
                <th class="px-3 py-2 font-semibold">Designation</th>
                <th class="px-3 py-2 text-right font-semibold">Gross</th>
                <th class="px-3 py-2 text-right font-semibold">Deductions</th>
                <th class="px-3 py-2 text-right font-semibold">Net</th>
                <th class="px-5 py-2" />
              </tr>
            </thead>
            <tbody class="divide-y">
              <tr v-for="row in drilldown" :key="row.id" class="hover:bg-muted/40">
                <td class="px-5 py-2">
                  <strong class="font-medium">{{ row.employee_name }}</strong>
                  <span class="block text-[11px] text-muted-foreground">{{ row.employee_id }}</span>
                </td>
                <td class="px-3 py-2 text-muted-foreground">{{ row.designation || '—' }}</td>
                <td class="px-3 py-2 text-right tabular-nums">{{ money(row.gross) }}</td>
                <td class="px-3 py-2 text-right tabular-nums text-muted-foreground">{{ money(row.deductions) }}</td>
                <td class="px-3 py-2 text-right font-semibold tabular-nums">{{ money(row.net) }}</td>
                <td class="px-5 py-2 text-right">
                  <Link :href="route('payroll.slips.show', row.id)" class="text-xs font-medium text-primary hover:underline">Payslip →</Link>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <p class="text-[11px] text-muted-foreground">
        Every figure on this page is summed from the payslips of {{ context.period }}, so it always agrees with the sheet and with the
        individual payslip a person is handed.
      </p>
    </div>
  </AdminLayout>
</template>
