<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import {
  ArrowRight,
  CalendarDays,
  ChevronLeft,
  ChevronRight,
  Download,
  Info,
  Printer,
  Search,
  TriangleAlert,
  Users,
  X,
} from 'lucide-vue-next';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import EnterpriseCard from '@core/Components/EnterpriseCard.vue';
import EmptyState from '@core/Components/EmptyState.vue';
import ModuleNav from './ModuleNav.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDate, formatNumber } from '@core/Utils/format';
import { printDocument } from '@core/Composables/usePrint';
import { useReportPdf } from '@core/Composables/useReportPdf';

/**
 * Leave Balances — the register.
 *
 * The old page was a placeholder: "Choose an employee to view their balance across all active leave
 * types." An HR desk does not arrive with an employee ID; it arrives with "who is running out?" and
 * "how much leave do we owe in total?". This page opens on everybody and narrows to one person when
 * asked.
 *
 * The matrix is intentionally sparse: cells print days remaining, and a zero is printed as a dash
 * because a wall of 0.00 makes a full column unreadable.
 */

interface TypeMeta { id: number; name: string; code: string | null; color: string | null; is_long_leave: boolean; days_per_year: number }

interface RegisterRow {
  employee_id: string;
  employee: string;
  department: string | null;
  designation: string | null;
  years_of_service: number | null;
  available: number;
  core_available: number;
  long_leave_available: number;
  used: number;
  pending: number;
  by_type: Record<string, { available: number; approved: number; entitlement: number }>;
}

interface Register {
  rows: RegisterRow[];
  types: TypeMeta[];
  meta: { total: number; page: number; per_page: number; last_page: number };
  year: number;
}

interface Focused {
  employee_id: string;
  employee: string;
  department: string | null;
  designation: string | null;
  balances: Array<{
    leave_type_id: number;
    leave_type: string;
    code: string | null;
    color: string | null;
    is_paid: boolean;
    opening: number;
    entitlement: number;
    carry_forward: number;
    approved: number;
    pending: number;
    available: number;
    total: number;
    frozen: boolean;
    day_count_mode: string;
    accrual_mode: string;
    proration_mode: string;
    approval_steps: string[];
  }>;
  history: Array<{
    id: number;
    leave_type: string | null;
    leave_type_code: string | null;
    leave_type_color: string | null;
    from_date: string | null;
    to_date: string | null;
    days: number;
    status: string;
    is_half_day: boolean;
  }>;
}

const { downloadPdf } = useReportPdf();
const pdfPayload = computed(() => ({
    title: `Leave balances — ${props.year}`,
    subtitle: 'Per-employee leave ledger',
    permission: 'hrm.leaves.balance.view',
    orientation: 'landscape',
    meta: {
        'Year': String(props.year),
        'Employees': String(props.register.meta.total),
    },
    headings: ['Employee', 'Department', 'Designation', 'Years', 'Available', 'Used', 'Pending'],
    rows: props.register.rows.map((r: any) => [r.employee, r.department ?? '—', r.designation ?? '—', r.years_of_service ?? 0, r.available, r.used, r.pending]),
    footer: `Leave balances ${props.year} · HR`,
}));

const props = defineProps<{
  register: Register;
  focused: Focused | null;
  employeeOptions: Array<{ value: string; label: string }>;
  year: number;
  longLeaveThreshold: number;
}>();

const search = ref('');
let searchTimer: ReturnType<typeof setTimeout> | undefined;

watch(search, (value) => {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => {
    router.get(route('hrm.leaves.balances'), { year: props.year, search: value || undefined, employee_id: props.focused?.employee_id }, { preserveState: true, preserveScroll: true, replace: true });
  }, 350);
});

const goPage = (page: number): void => {
  router.get(route('hrm.leaves.balances'), { year: props.year, page, search: search.value || undefined }, { preserveState: true, preserveScroll: true });
};

const changeYear = (value: number): void => {
  router.get(route('hrm.leaves.balances'), { year: value, search: search.value || undefined }, { preserveState: true, preserveScroll: true });
};

const focus = (employeeId: string): void => {
  router.get(route('hrm.leaves.balances'), { year: props.year, employee_id: employeeId, search: search.value || undefined }, { preserveState: true, preserveScroll: true });
};

const clearFocus = (): void => {
  router.get(route('hrm.leaves.balances'), { year: props.year, search: search.value || undefined }, { preserveState: true, preserveScroll: true });
};

const years = Array.from({ length: 7 }, (_, i) => new Date().getFullYear() - 3 + i);

/** A dash for zero keeps a 6-column matrix readable; the tooltip keeps the number available. */
const cell = (value: number | undefined): string => (value === undefined || value === 0 ? '–' : formatNumber(Math.round(value * 10) / 10));

const printing = computed(() => props.focused !== null);

const exportCsv = (): void => {
  const header = ['Employee ID', 'Employee', 'Department', ...props.register.types.map((t) => `${t.name} available`), 'Usable this year', 'Long leave', 'Taken'];
  const lines = props.register.rows.map((row) => [
    row.employee_id,
    row.employee,
    row.department ?? '',
    ...props.register.types.map((t) => row.by_type[t.id]?.available ?? 0),
    row.core_available,
    row.long_leave_available,
    row.used,
  ]);
  const csv = [header, ...lines].map((line) => line.map((v) => `"${String(v).replace(/"/g, '""')}"`).join(',')).join('\r\n');
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
  const url = URL.createObjectURL(blob);
  const anchor = document.createElement('a');
  anchor.href = url;
  anchor.download = `leave-balances-${props.year}-page-${props.register.meta.page}.csv`;
  anchor.click();
  URL.revokeObjectURL(url);
};
</script>

<template>
  <AdminLayout :breadcrumbs="[{ title: 'Leave' }, { title: 'Balances' }]">
    <Head title="Leave balances" />

    <div class="space-y-4">
      <ModuleNav />

      <div class="flex flex-wrap items-start justify-between gap-3 no-print">
        <div>
          <h1 class="flex items-center gap-2.5 text-xl font-bold tracking-tight">
            <span class="grid size-9 place-items-center rounded-xl bg-gradient-to-br from-primary to-primary/70 text-primary-foreground shadow-sm">
              <CalendarDays class="size-5" />
            </span>
            Leave balances
          </h1>
          <p class="mt-1 max-w-3xl text-sm text-muted-foreground">
            Days remaining per leave type for every active employee, with carry-forward included. Open a
            person to see how each figure was built.
          </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
          <select class="h-9 rounded-lg border bg-card px-3 text-sm" :value="year" aria-label="Leave year" @change="changeYear(Number(($event.target as HTMLSelectElement).value))">
            <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
          </select>
          <Button size="sm" variant="outline" @click="exportCsv"><Download class="size-4" /> CSV</Button>
          <Button size="sm" class="rounded-full" @click="downloadPdf(pdfPayload)"><FileDown class="size-4" /> Download PDF</Button>
          <Button size="sm" variant="outline" @click="printDocument()"><Printer class="size-4" /> Print</Button>
        </div>
      </div>

      <!-- One employee -->
      <template v-if="focused">
        <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl border bg-card px-4 py-3 shadow-sm">
          <div class="flex items-center gap-3">
            <span class="grid size-9 place-items-center rounded-full bg-primary/10 text-sm font-bold text-primary">
              {{ focused.employee.slice(0, 2).toUpperCase() }}
            </span>
            <div>
              <p class="text-sm font-semibold">{{ focused.employee }}</p>
              <p class="text-xs text-muted-foreground">
                {{ focused.employee_id }}<template v-if="focused.department"> · {{ focused.department }}</template>
                <template v-if="focused.designation"> · {{ focused.designation }}</template>
              </p>
            </div>
          </div>
          <div class="flex items-center gap-2">
            <Button size="sm" variant="outline" @click="router.visit(route('hrm.leaves.create', { employee_id: focused.employee_id }))">
              Apply for leave
            </Button>
            <Button size="sm" variant="ghost" @click="clearFocus"><X class="size-4" /> Close</Button>
          </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-3">
          <EnterpriseCard class="lg:col-span-2" :title="`Every leave type in ${year}`" description="Each row is one entitlement, built from the policy that governs it.">
            <div class="overflow-x-auto">
              <table class="w-full min-w-[680px] text-sm">
                <thead class="border-b text-[11px] uppercase tracking-wider text-muted-foreground">
                  <tr>
                    <th class="pb-2 text-left font-semibold">Type</th>
                    <th class="pb-2 text-right font-semibold">Entitlement</th>
                    <th class="pb-2 text-right font-semibold">Carried</th>
                    <th class="pb-2 text-right font-semibold">Taken</th>
                    <th class="pb-2 text-right font-semibold">Pending</th>
                    <th class="pb-2 text-right font-semibold">Available</th>
                    <th class="pb-2 pl-4 text-left font-semibold">Policy</th>
                  </tr>
                </thead>
                <tbody class="divide-y">
                  <tr v-for="row in focused.balances" :key="row.leave_type_id" class="hover:bg-muted/30">
                    <td class="py-2.5">
                      <span class="flex items-center gap-2 font-medium">
                        <span class="size-2 rounded-full" :style="{ backgroundColor: row.color ?? '#94a3b8' }" />
                        {{ row.leave_type }}
                        <span v-if="row.frozen" class="rounded bg-info/10 px-1.5 py-0.5 text-[10px] font-semibold text-info">closed</span>
                      </span>
                    </td>
                    <td class="py-2.5 text-right font-mono tabular-nums">{{ formatNumber(row.entitlement) }}d</td>
                    <td class="py-2.5 text-right font-mono tabular-nums" :class="row.carry_forward > 0 ? 'text-info' : 'text-muted-foreground'">
                      {{ row.carry_forward > 0 ? '+' + formatNumber(row.carry_forward) + 'd' : '–' }}
                    </td>
                    <td class="py-2.5 text-right font-mono tabular-nums">{{ formatNumber(row.approved) }}d</td>
                    <td class="py-2.5 text-right font-mono tabular-nums text-muted-foreground">{{ row.pending > 0 ? formatNumber(row.pending) + 'd' : '–' }}</td>
                    <td class="py-2.5 text-right font-mono font-bold tabular-nums" :class="row.available <= 0 ? 'text-muted-foreground' : ''">
                      {{ formatNumber(row.available) }}d
                    </td>
                    <td class="py-2.5 pl-4 text-[11px] text-muted-foreground">
                      {{ row.day_count_mode === 'working_days' ? 'working days' : 'calendar days' }} ·
                      {{ row.accrual_mode === 'monthly' ? 'monthly accrual' : 'annual grant' }} ·
                      {{ row.proration_mode === 'none' ? 'no pro-rating' : row.proration_mode + ' pro-rating' }}
                      <span class="block">approval: {{ row.approval_steps.join(' → ') }}</span>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </EnterpriseCard>

          <EnterpriseCard :title="`Applications in ${year}`" description="What built the 'taken' column.">
            <ul v-if="focused.history.length" class="divide-y">
              <li v-for="row in focused.history" :key="row.id" class="flex items-center gap-2 py-2 first:pt-0 last:pb-0">
                <span class="size-1.5 shrink-0 rounded-full" :style="{ backgroundColor: row.leave_type_color ?? '#94a3b8' }" />
                <Link :href="route('hrm.leaves.show', row.id)" class="min-w-0 flex-1 text-left text-xs hover:text-primary hover:underline">
                  {{ row.leave_type_code }} · {{ formatDate(row.from_date) }}
                  <template v-if="row.from_date !== row.to_date"> → {{ formatDate(row.to_date) }}</template>
                </Link>
                <span class="shrink-0 text-xs tabular-nums">{{ formatNumber(row.days) }}d</span>
                <span
                  class="shrink-0 rounded-full px-1.5 py-0.5 text-[10px] font-medium"
                  :class="{
                    'bg-success/10 text-success': row.status === 'Approved',
                    'bg-warning/10 text-warning': row.status === 'Pending',
                    'bg-danger/10 text-danger': row.status === 'Rejected',
                  }"
                >{{ row.status }}</span>
              </li>
            </ul>
            <p v-else class="py-6 text-center text-sm text-muted-foreground">No leave applications in {{ year }}.</p>
          </EnterpriseCard>
        </div>
      </template>

      <!-- The register -->
      <template v-else>
        <EnterpriseCard :title="`Balance register · ${year}`" :description="`${formatNumber(register.meta.total)} active employees, days remaining per leave type.`" :icon="Users">
          <template #actions>
            <div class="relative">
              <Search class="pointer-events-none absolute left-2.5 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground" />
              <Input v-model="search" class="h-8 w-56 pl-8 text-[13px]" placeholder="Name or employee ID…" />
            </div>
          </template>

          <div v-if="register.rows.length" class="overflow-x-auto">
            <table class="w-full min-w-[980px] text-sm">
              <thead class="border-b text-[11px] uppercase tracking-wider text-muted-foreground">
                <tr>
                  <th class="pb-2 text-left font-semibold">Employee</th>
                  <th class="pb-2 text-left font-semibold">Department</th>
                  <th v-for="type in register.types" :key="type.id" class="pb-2 text-right font-semibold" :title="`${type.name} — ${type.days_per_year} day(s) per year${type.is_long_leave ? ' (long leave)' : ''}`">
                    <span class="inline-flex items-center gap-1">
                      <span class="size-1.5 rounded-full" :style="{ backgroundColor: type.color ?? '#94a3b8' }" />
                      {{ type.code ?? type.name.slice(0, 4) }}
                      <span v-if="type.is_long_leave" class="text-[9px] opacity-70">*</span>
                    </span>
                  </th>
                  <th class="pb-2 pl-4 text-right font-semibold" title="Ordinary leave the employee can spend this year (excludes long leave such as maternity)">Usable</th>
                  <th class="pb-2 text-right font-semibold" title="Long-leave entitlements, shown apart because they are not spendable ordinary leave">Long</th>
                  <th class="pb-2 text-right font-semibold">Taken</th>
                  <th class="pb-2 text-right font-semibold" />
                </tr>
              </thead>
              <tbody class="divide-y">
                <tr v-for="row in register.rows" :key="row.employee_id" class="cursor-pointer hover:bg-muted/30" @click="focus(row.employee_id)">
                  <td class="py-2">
                    <span class="block text-[13px] font-medium">{{ row.employee }}</span>
                    <span class="block font-mono text-[11px] text-muted-foreground">{{ row.employee_id }}</span>
                  </td>
                  <td class="py-2 text-xs text-muted-foreground">{{ row.department ?? '—' }}</td>
                  <td v-for="type in register.types" :key="type.id" class="py-2 text-right font-mono text-[13px] tabular-nums"
                      :class="(row.by_type[type.id]?.available ?? 0) <= 0 ? 'text-muted-foreground' : ''"
                      :title="`${type.name}: ${row.by_type[type.id]?.available ?? 0} available of ${row.by_type[type.id]?.entitlement ?? 0} entitled`">
                    {{ cell(row.by_type[type.id]?.available) }}
                  </td>
                  <td class="py-2 pl-4 text-right font-mono font-bold tabular-nums">{{ formatNumber(row.core_available) }}d</td>
                  <td class="py-2 text-right font-mono text-[13px] tabular-nums text-muted-foreground" :title="`Includes long leave such as maternity`">
                    {{ row.long_leave_available > 0 ? formatNumber(row.long_leave_available) + 'd' : '–' }}
                  </td>
                  <td class="py-2 text-right font-mono text-[13px] tabular-nums text-muted-foreground">{{ formatNumber(row.used) }}d</td>
                  <td class="py-2 text-right">
                    <ArrowRight class="ml-auto size-3.5 text-muted-foreground" />
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <EmptyState v-else :icon="CalendarDays" title="No employees match" description="Clear the search, or check that employees are marked active." />

          <div class="mt-3 flex flex-wrap items-center justify-between gap-2 border-t pt-3 text-xs text-muted-foreground no-print">
            <p>
              Showing {{ register.rows.length }} of {{ formatNumber(register.meta.total) }} employees
              · page {{ register.meta.page }} of {{ register.meta.last_page }}
            </p>
            <div class="flex items-center gap-1">
              <Button size="sm" variant="outline" class="h-7 px-2" :disabled="register.meta.page <= 1" @click="goPage(register.meta.page - 1)">
                <ChevronLeft class="size-3.5" />
              </Button>
              <Button size="sm" variant="outline" class="h-7 px-2" :disabled="register.meta.page >= register.meta.last_page" @click="goPage(register.meta.page + 1)">
                <ChevronRight class="size-3.5" />
              </Button>
            </div>
          </div>

          <div class="mt-2 space-y-1.5 text-[11px] text-muted-foreground">
            <p class="flex items-start gap-1.5">
              <Info class="mt-0.5 size-3 shrink-0" />
              Columns are leave-type codes: days remaining after entitlement, carry-forward, approved and pending
              leave. A dash means none left. <span class="font-medium">Usable</span> is what the employee can spend
              this year; <span class="font-medium">Long</span> holds entitlements of
              {{ longLeaveThreshold }} days or more (marked <span class="font-medium">*</span> in the header),
              which are taken as a block rather than through the year.
            </p>
            <p v-if="register.types.some((t) => t.is_long_leave)" class="flex items-start gap-1.5">
              <TriangleAlert class="mt-0.5 size-3 shrink-0 text-warning" />
              <span>
                A long-leave type is granted to every active employee, because nothing on the employee record
                says who is eligible. <span class="font-medium">{{ register.types.filter((t) => t.is_long_leave).map((t) => t.name).join(', ') }}</span>
                currently appears on all {{ formatNumber(register.meta.total) }} records — including staff who will
                never take it. Gender is blank on every employee, so eligibility cannot be worked out yet.
              </span>
            </p>
          </div>
        </EnterpriseCard>
      </template>
    </div>
  </AdminLayout>
</template>
