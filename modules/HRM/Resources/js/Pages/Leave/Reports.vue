<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { FileDown, Building2, CalendarDays, Download, FileBarChart2, Info, Printer, Timer, Users } from 'lucide-vue-next';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import EnterpriseCard from '@core/Components/EnterpriseCard.vue';
import ModuleNav from './ModuleNav.vue';
import { Button } from '@/components/ui/button';
import { formatNumber } from '@core/Utils/format';
import { printDocument } from '@core/Composables/usePrint';
import { useReportPdf } from '@core/Composables/useReportPdf';

/**
 * Leave reports.
 *
 * The old HR report was four numeric tiles over a one-bar "by type" chart and a table of the top two
 * takers — in a database where two applications existed. It answered "how many" and nothing else.
 *
 * This page answers the questions that get asked in an annual HR review: how much leave did we grant,
 * how much did people take, which departments are heavy or light, who is carrying unused days, who
 * approves quickly and who sits on applications.
 */

interface TypeRow {
  id: number;
  name: string;
  code: string | null;
  color: string | null;
  is_paid: boolean;
  days_per_year: number;
  entitlement_pool: number;
  approved: number;
  pending: number;
  requests: number;
}

interface DepartmentRow {
  department: string;
  employees: number;
  days: number;
  requests: number;
  average: number;
}

interface TakerRow {
  employee_id: string;
  employee: string;
  department: string | null;
  days: number;
  requests: number;
}

const { downloadPdf } = useReportPdf();
const pdfPayload = computed(() => ({
    title: `Leave report — ${props.report.year}`,
    subtitle: 'Entitlement, utilisation and pending by leave type',
    permission: 'hrm.leaves.view',
    orientation: 'landscape',
    meta: {
        'Year': String(props.report.year),
        'Active employees': String(props.report.totals.active_employees),
        'Approved days': String(props.report.totals.approved_days),
        'Pending days': String(props.report.totals.pending_days),
        'Decisions': String(props.report.totals.decisions),
    },
    headings: ['Type', 'Code', 'Paid', 'Days/yr', 'Entitlement', 'Approved', 'Pending', 'Requests'],
    rows: props.report.types.map((r: any) => [r.name, r.code ?? '—', r.is_paid ? 'Yes' : 'No', r.days_per_year, r.entitlement_pool, r.approved, r.pending, r.requests]),
    footer: `Leave report ${props.report.year} · HR`,
}));

const props = defineProps<{
  report: {
    year: number;
    types: TypeRow[];
    departments: DepartmentRow[];
    top_takers: TakerRow[];
    totals: {
      active_employees: number;
      approved_days: number;
      pending_days: number;
      entitlement_pool: number;
      carried_forward: number;
      lapsed: number;
      year_closed: boolean;
      average_days_per_employee: number;
      decisions: number;
      average_decision_days: number | null;
      impossible_timestamps: number;
      entitlement_pool_spendable: number;
      entitlement_pool_long: number;
    };
    types_count: number;
  };
  canExport: boolean;
}>();

const years = Array.from({ length: 7 }, (_, i) => new Date().getFullYear() - 3 + i);
const goYear = (year: number): void => router.get(route('hrm.leaves.reports'), { year }, { preserveState: true, preserveScroll: true });

// One decimal, thousands-separated: a pool of 46,953 days must not print as "46953d".
const dayFormatter = new Intl.NumberFormat('en-US', { maximumFractionDigits: 1 });
const days = (value: number): string => `${dayFormatter.format(Number(value ?? 0))}d`;

const totals = computed(() => props.report.totals);

/** Department bars are scaled to the busiest department, not to an abstract 100%. */
const departmentPeak = computed(() => Math.max(1, ...props.report.departments.map((d) => d.days)));
const departmentWidth = (value: number): string => `${Math.max(2, Math.round((value / departmentPeak.value) * 100))}%`;

const busy = ref(false);

const exportCsv = (): void => {
  const header = ['Section', 'Item', 'Employees', 'Applications', 'Days', 'Average'];
  const lines: Array<Array<string | number>> = [];

  for (const type of props.report.types) {
    lines.push(['Leave type', type.name, '', type.requests, type.approved, '']);
  }
  for (const dept of props.report.departments) {
    lines.push(['Department', dept.department, dept.employees, dept.requests, dept.days, dept.average]);
  }
  for (const taker of props.report.top_takers) {
    lines.push(['Top taker', `${taker.employee} (${taker.employee_id})`, '', taker.requests, taker.days, '']);
  }

  const csv = [header, ...lines].map((line) => line.map((v) => `"${String(v ?? '').replace(/"/g, '""')}"`).join(',')).join('\r\n');
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
  const url = URL.createObjectURL(blob);
  const anchor = document.createElement('a');
  anchor.href = url;
  anchor.download = `leave-report-${props.report.year}.csv`;
  anchor.click();
  URL.revokeObjectURL(url);
  busy.value = false;
};
</script>

<template>
  <AdminLayout :breadcrumbs="[{ title: 'Leave' }, { title: 'Reports' }]">
    <Head title="Leave reports" />

    <div class="space-y-4">
      <ModuleNav />

      <div class="flex flex-wrap items-start justify-between gap-3 no-print">
        <div>
          <h1 class="flex items-center gap-2.5 text-xl font-bold tracking-tight">
            <span class="grid size-9 place-items-center rounded-xl bg-gradient-to-br from-primary to-primary/70 text-primary-foreground shadow-sm">
              <FileBarChart2 class="size-5" />
            </span>
            Leave report · {{ report.year }}
          </h1>
          <p class="mt-1 max-w-3xl text-sm text-muted-foreground">
            What was granted, what was taken, and who is carrying days — the figures an annual HR review is
            written from, all in days.
          </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
          <select class="h-9 rounded-lg border bg-card px-3 text-sm" :value="report.year" aria-label="Report year" @change="goYear(Number(($event.target as HTMLSelectElement).value))">
            <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
          </select>
          <Button v-if="canExport" size="sm" variant="outline" :disabled="busy" @click="exportCsv"><Download class="size-4" /> CSV</Button>
          <Button size="sm" class="rounded-full" @click="downloadPdf(pdfPayload)"><FileDown class="size-4" /> Download PDF</Button>
          <Button size="sm" variant="outline" @click="printDocument()"><Printer class="size-4" /> Print</Button>
        </div>
      </div>

      <!-- The four numbers that matter, each different -->
      <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-xl border bg-card p-4 shadow-sm">
          <p class="flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
            <CalendarDays class="size-3" /> Days granted
          </p>
          <p class="mt-1 text-2xl font-bold tabular-nums">{{ days(totals.entitlement_pool_spendable) }}</p>
          <p class="text-xs text-muted-foreground">
            ordinary leave across {{ formatNumber(totals.active_employees) }} staff — a further
            {{ days(totals.entitlement_pool_long) }} is long-leave entitlement
          </p>
        </div>
        <div class="rounded-xl border bg-card p-4 shadow-sm">
          <p class="flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
            <Users class="size-3" /> Days taken
          </p>
          <p class="mt-1 text-2xl font-bold tabular-nums">{{ days(totals.approved_days) }}</p>
          <p class="text-xs text-muted-foreground">
            <template v-if="totals.average_days_per_employee >= 0.05">
              {{ formatNumber(totals.average_days_per_employee) }} days per employee on average
            </template>
            <template v-else>
              fewer than 0.1 days per employee — {{ formatNumber(totals.active_employees) }} staff on the books
            </template>
          </p>
        </div>
        <div class="rounded-xl border bg-card p-4 shadow-sm">
          <p class="flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
            <Timer class="size-3" /> Decisions
          </p>
          <p class="mt-1 text-2xl font-bold tabular-nums">{{ formatNumber(totals.decisions) }}</p>
          <p class="text-xs text-muted-foreground">
            <template v-if="totals.decisions === 0">no decisions recorded this year</template>
            <template v-else-if="totals.impossible_timestamps > 0">
              {{ formatNumber(totals.impossible_timestamps) }} of {{ formatNumber(totals.decisions) }} carry a decision
              date before the application date, so latency is not reported
            </template>
            <template v-else>
              average {{ formatNumber(totals.average_decision_days ?? 0) }} day(s) from application to decision
            </template>
          </p>
        </div>
        <div class="rounded-xl border bg-card p-4 shadow-sm">
          <p class="flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
            <Info class="size-3" /> Carried / lapsed
          </p>
          <p class="mt-1 text-2xl font-bold tabular-nums">
            {{ days(totals.carried_forward) }}<span class="text-sm font-medium text-muted-foreground"> / {{ days(totals.lapsed) }}</span>
          </p>
          <p class="text-xs text-muted-foreground">
            {{ totals.year_closed ? `${report.year} is closed — these figures are final` : `${report.year} is still open, so carry is not fixed yet` }}
          </p>
        </div>
      </div>

      <div class="grid gap-4 lg:grid-cols-2">
        <!-- By type -->
        <EnterpriseCard title="By leave type" :description="`Days approved in ${report.year}`" :icon="CalendarDays">
          <div class="space-y-2.5">
            <div v-for="type in report.types" :key="type.id">
              <div class="flex items-baseline justify-between text-[13px]">
                <span class="flex items-center gap-1.5 font-medium">
                  <span class="size-2 rounded-full" :style="{ backgroundColor: type.color ?? '#94a3b8' }" />
                  {{ type.name }}
                  <span class="text-[11px] text-muted-foreground">{{ formatNumber(type.requests) }} appl.</span>
                  <span v-if="type.days_per_year >= 90" class="rounded bg-muted px-1 py-0.5 text-[9px] font-semibold uppercase tracking-wide text-muted-foreground">long leave</span>
                </span>
                <span class="tabular-nums">{{ days(type.approved) }}<span v-if="type.pending > 0" class="ml-1 text-[11px] text-muted-foreground">+{{ days(type.pending) }} pending</span></span>
              </div>
              <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-muted">
                <div
                  class="h-full rounded-full"
                  :style="{
                    width: (type.approved / Math.max(1, ...report.types.map((t) => t.approved))) * 100 + '%',
                    backgroundColor: type.color ?? '#94a3b8',
                  }"
                />
              </div>
            </div>
            <p v-if="!report.types.length" class="py-6 text-center text-sm text-muted-foreground">No leave types configured.</p>
          </div>
        </EnterpriseCard>

        <!-- By department -->
        <EnterpriseCard title="By department" :description="`Approved days per department in ${report.year}`" :icon="Building2">
          <p v-if="report.departments.length === 1" class="text-[13px] leading-relaxed">
            All {{ days(report.departments[0].days) }} came from
            <span class="font-semibold">{{ report.departments[0].department }}</span> — a single department, so there is
            nothing to compare against yet.
          </p>
          <div v-else-if="report.departments.length" class="space-y-2">
            <div v-for="dept in report.departments" :key="dept.department">
              <div class="flex items-baseline justify-between text-[13px]">
                <span class="truncate font-medium">{{ dept.department }}</span>
                <span class="shrink-0 tabular-nums">
                  {{ days(dept.days) }}
                  <span class="ml-1 text-[11px] text-muted-foreground">{{ formatNumber(dept.average) }}d/person</span>
                </span>
              </div>
              <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-muted">
                <div class="h-full rounded-full bg-primary/60" :style="{ width: departmentWidth(dept.days) }" />
              </div>
            </div>
          </div>
          <p v-else class="py-6 text-center text-sm text-muted-foreground">No approved leave this year.</p>
        </EnterpriseCard>
      </div>

      <!-- Top takers -->
      <EnterpriseCard title="Most leave taken" :description="`The 15 employees with the most approved days in ${report.year}`" :icon="Users">
        <div v-if="report.top_takers.length" class="overflow-x-auto">
          <table class="w-full min-w-[560px] text-sm">
            <thead class="border-b text-[11px] uppercase tracking-wider text-muted-foreground">
              <tr>
                <th class="pb-2 text-left font-semibold">#</th>
                <th class="pb-2 text-left font-semibold">Employee</th>
                <th class="pb-2 text-left font-semibold">Department</th>
                <th class="pb-2 text-right font-semibold">Applications</th>
                <th class="pb-2 text-right font-semibold">Days</th>
              </tr>
            </thead>
            <tbody class="divide-y">
              <tr v-for="(taker, index) in report.top_takers" :key="taker.employee_id" class="hover:bg-muted/30">
                <td class="py-2 text-xs tabular-nums text-muted-foreground">{{ index + 1 }}</td>
                <td class="py-2">
                  <span class="block text-[13px] font-medium">{{ taker.employee }}</span>
                  <span class="block font-mono text-[11px] text-muted-foreground">{{ taker.employee_id }}</span>
                </td>
                <td class="py-2 text-xs text-muted-foreground">{{ taker.department ?? '—' }}</td>
                <td class="py-2 text-right tabular-nums">{{ formatNumber(taker.requests) }}</td>
                <td class="py-2 text-right font-mono font-semibold tabular-nums">{{ days(taker.days) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <p v-else class="py-6 text-center text-sm text-muted-foreground">No approved leave this year.</p>
      </EnterpriseCard>

      <p class="flex items-start gap-1.5 text-xs text-muted-foreground">
        <Info class="mt-0.5 size-3.5 shrink-0" />
        Counts are approved applications only, in days as charged by each type's policy. Pending days are shown
        separately because they reserve balance without having been taken. Carry and lapse are read from the
        year-end snapshot and are only final once the year is closed.
      </p>
    </div>
  </AdminLayout>
</template>
