<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { CalendarRange, Layers, Printer, TrendingUp } from 'lucide-vue-next';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import ModuleNav from './ModuleNav.vue';
import EmployeeSearchSelect from '../../Components/EmployeeSearchSelect.vue';
import EmployeeMultiSearchSelect from '../../Components/EmployeeMultiSearchSelect.vue';
import { confirmDialog } from '@core/Composables/useConfirm';
import { useToast } from '@core/Composables/useToast';
import { printDocument } from '@core/Composables/usePrint';
import { formatCurrency } from '@core/Utils/format';

/**
 * The schedule — one payroll month, every advance that falls due in it.
 *
 * The register is ordered by advance, so it cannot answer "what will payroll deduct next month":
 * that is a question across advances, grouped by a date. This page is the answer, and it is also
 * where staging happens, because staging is a decision about a month rather than about a loan.
 *
 * Installments appear here whatever their state — paid, staged or merely scheduled — because a
 * schedule that hides what has already happened cannot be used to check a payslip.
 */

interface InstallmentRow {
  id: number;
  loan_id: number;
  employee_id: string | null;
  employee_name: string | null;
  installment_number: number;
  installment_count: number;
  due_date: string | null;
  amount: number;
  status: string;
}

interface Totals {
  count: number;
  amount: number;
  due: number;
  paid: number;
  staged: number;
}

interface Batch {
  id: number;
  status: string;
  scope: string;
  allocations: number;
  locked_at: string | null;
}

interface EmployeeOption { value: string; label: string; description?: string }

const props = defineProps<{
  period: { year: number; month: number; label: string };
  installments: InstallmentRow[];
  totals: Totals;
  batch: Batch | null;
  employeeOptions: EmployeeOption[];
  canManage: boolean;
}>();

const toast = useToast();
const money = (value: number | null | undefined): string => formatCurrency(Number(value ?? 0));

/* -------------------------------- period -------------------------------- */

const periodValue = ref(`${props.period.year}-${String(props.period.month).padStart(2, '0')}`);

watch(periodValue, (value) => {
  const [year, month] = value.split('-').map(Number);
  if (!year || !month) return;
  router.get(route('payroll.loans.schedule'), { year, month }, { preserveState: true, preserveScroll: true, replace: true });
});

function shiftMonth(delta: number): void {
  const [year, month] = periodValue.value.split('-').map(Number);
  const next = new Date(year, month - 1 + delta, 1);
  periodValue.value = `${next.getFullYear()}-${String(next.getMonth() + 1).padStart(2, '0')}`;
}

/* ------------------------------- staging -------------------------------- */

const staging = useForm({
  pay_year: props.period.year,
  pay_month: props.period.month,
  scope: 'all' as 'single' | 'selected' | 'all',
  employee_id: '',
  employee_ids: [] as string[],
});

watch(periodValue, (value) => {
  const [year, month] = value.split('-').map(Number);
  if (year && month) {
    staging.pay_year = year;
    staging.pay_month = month;
  }
});

const scopeText = computed(() =>
  staging.scope === 'all'
    ? 'every employee with a due advance'
    : staging.scope === 'single'
      ? 'one employee'
      : `${staging.employee_ids.length} selected employee(s)`,
);

async function stage(): Promise<void> {
  if (staging.scope === 'single' && !staging.employee_id) {
    toast.warning('Select an employee.');
    return;
  }
  if (staging.scope === 'selected' && !staging.employee_ids.length) {
    toast.warning('Select at least one employee.');
    return;
  }
  const proceed = await confirmDialog({
    title: `Stage installments for ${props.period.label}`,
    description: `Stage at most one due installment per active advance for ${scopeText.value}? Staging must happen before the salary sheet for this month is created.`,
    confirmLabel: 'Stage installments',
    danger: false,
  });
  if (!proceed) return;
  staging.post(route('payroll.loans.allocate-monthly'), { preserveScroll: true });
}

/* -------------------------------- output -------------------------------- */

const print = (): void => {
  printDocument({
    title: `Advance schedule — ${props.period.label}`,
    region: 'loan-schedule',
    landscape: false,
    meta: [
      { label: 'Payroll month', value: props.period.label },
      { label: 'Installments', value: String(props.totals.count) },
      { label: 'Due', value: money(props.totals.due) },
    ],
  });
};

const statusTone = (status: string): string => ({
  paid: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
  attached: 'bg-sky-500/10 text-sky-600 dark:text-sky-400',
  allocated: 'bg-violet-500/10 text-violet-600 dark:text-violet-400',
  scheduled: 'bg-muted text-muted-foreground',
}[status] ?? 'bg-muted text-muted-foreground');
</script>

<template>
  <AdminLayout :breadcrumbs="[{ title: 'Payroll' }, { title: 'Staff advances', href: route('payroll.loans.index') }, { title: 'Schedule' }]">
    <Head :title="`Advance schedule — ${period.label}`" />

    <div class="space-y-4">
      <ModuleNav :can-manage="canManage" />

      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 class="flex items-center gap-2.5 text-xl font-bold tracking-tight">
            <span class="grid size-9 place-items-center rounded-xl bg-gradient-to-br from-primary to-primary/70 text-primary-foreground shadow-sm">
              <CalendarRange class="size-5" />
            </span>
            Repayment schedule
          </h1>
          <p class="mt-1 text-sm text-muted-foreground">
            Every advance installment that falls due in {{ period.label }} — what payroll will deduct, what it has already taken, and what is still to stage.
          </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
          <Button size="sm" variant="outline" @click="shiftMonth(-1)">Previous</Button>
          <Input v-model="periodValue" type="month" class="h-9 w-[150px]" />
          <Button size="sm" variant="outline" @click="shiftMonth(1)">Next</Button>
          <Button size="sm" variant="outline" @click="print"><Printer class="size-4" />Print</Button>
        </div>
      </div>

      <!-- Figures -->
      <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-xl border bg-card p-5 shadow-sm">
          <p class="text-xs font-medium uppercase tracking-wider text-muted-foreground">Falling due</p>
          <p class="mt-1.5 text-2xl font-bold tabular-nums tracking-tight">{{ totals.count.toLocaleString() }}</p>
          <p class="mt-1 text-xs text-muted-foreground">installment(s), {{ money(totals.amount) }} in total</p>
        </div>
        <div class="rounded-xl border bg-card p-5 shadow-sm">
          <p class="text-xs font-medium uppercase tracking-wider text-muted-foreground">Still to take</p>
          <p class="mt-1.5 text-2xl font-bold tabular-nums tracking-tight text-amber-600 dark:text-amber-400">{{ money(totals.due) }}</p>
          <p class="mt-1 text-xs text-muted-foreground">not yet deducted from a payslip</p>
        </div>
        <div class="rounded-xl border bg-card p-5 shadow-sm">
          <p class="text-xs font-medium uppercase tracking-wider text-muted-foreground">Already taken</p>
          <p class="mt-1.5 text-2xl font-bold tabular-nums tracking-tight text-emerald-600 dark:text-emerald-400">{{ money(totals.paid) }}</p>
          <p class="mt-1 text-xs text-muted-foreground">recovered against this month's schedule</p>
        </div>
        <div class="rounded-xl border bg-card p-5 shadow-sm">
          <p class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wider text-muted-foreground">
            Staged for payroll
            <span v-if="batch" class="rounded px-1.5 py-px text-[10px] font-semibold normal-case tracking-normal" :class="batch.status === 'locked' ? 'bg-muted text-muted-foreground' : 'bg-sky-500/10 text-sky-600 dark:text-sky-400'">
              {{ batch.status }}
            </span>
          </p>
          <p class="mt-1.5 text-2xl font-bold tabular-nums tracking-tight">{{ money(totals.staged) }}</p>
          <p class="mt-1 text-xs text-muted-foreground">
            {{ batch ? `${batch.allocations} allocation(s) · ${batch.scope}` : 'nothing staged for this month yet' }}
          </p>
        </div>
      </div>

      <!-- Staging form -->
      <section v-if="canManage" class="rounded-xl border bg-card shadow-sm">
        <div class="border-b px-5 py-4">
          <h2 class="flex items-center gap-2 text-sm font-semibold"><Layers class="size-4 text-muted-foreground" />Stage this month</h2>
          <p class="mt-0.5 max-w-3xl text-xs text-muted-foreground">
            One installment per advance per month. Staging again for the same month will not deduct twice. If a salary sheet already exists for {{ period.label }}, staging is refused — the sheet snapshotted its deductions at creation and cannot be changed afterwards.
          </p>
        </div>
        <form class="grid gap-4 p-5 lg:grid-cols-2" @submit.prevent="stage">
          <div class="space-y-2">
            <Label>Scope</Label>
            <select v-model="staging.scope" class="h-10 w-full rounded-md border bg-background px-3 text-sm">
              <option value="all">Every employee with a due advance</option>
              <option value="single">One employee</option>
              <option value="selected">Selected employees</option>
            </select>
          </div>
          <div v-if="staging.scope === 'single'" class="space-y-2">
            <Label>Employee</Label>
            <EmployeeSearchSelect v-model="staging.employee_id" :options="employeeOptions" />
            <p v-if="staging.errors.employee_id" class="text-xs text-destructive">{{ staging.errors.employee_id }}</p>
          </div>
          <div v-if="staging.scope === 'selected'" class="space-y-2 lg:col-span-2">
            <Label>Employees</Label>
            <EmployeeMultiSearchSelect v-model="staging.employee_ids" :options="employeeOptions" />
            <p v-if="staging.errors.employee_ids" class="text-xs text-destructive">{{ staging.errors.employee_ids }}</p>
          </div>
          <p v-if="staging.errors.period || staging.errors.allocation" class="text-sm text-destructive lg:col-span-2">
            {{ staging.errors.period || staging.errors.allocation }}
          </p>
          <div class="flex flex-wrap items-center gap-3 lg:col-span-2">
            <Button :disabled="staging.processing">{{ staging.processing ? 'Staging…' : `Stage for ${period.label}` }}</Button>
            <p class="text-xs text-muted-foreground">Staging never approves an advance: only approved advances have installments to stage.</p>
          </div>
        </form>
      </section>

      <!-- Schedule table (also the print region) -->
      <section data-core-print="loan-schedule" class="rounded-xl border bg-card shadow-sm">
        <div class="flex flex-wrap items-start justify-between gap-3 border-b px-5 py-4">
          <div>
            <h2 class="flex items-center gap-2 text-sm font-semibold"><TrendingUp class="size-4 text-muted-foreground" />{{ period.label }} schedule</h2>
            <p class="mt-0.5 text-xs text-muted-foreground">Ordered by due date, then by advance.</p>
          </div>
          <span class="rounded-md bg-muted px-2.5 py-1 text-xs text-muted-foreground">{{ totals.count }} row(s)</span>
        </div>

        <div v-if="installments.length === 0" class="px-5 py-16 text-center">
          <CalendarRange class="mx-auto size-8 text-muted-foreground/40" />
          <p class="mt-2 text-sm font-medium">Nothing falls due in {{ period.label }}</p>
          <p class="mt-1 text-xs text-muted-foreground">An advance only contributes a repayment in the month an installment is due.</p>
        </div>

        <div v-else class="overflow-x-auto">
          <table class="w-full text-[13px]">
            <thead>
              <tr class="border-b bg-muted/40 text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                <th class="px-5 py-2.5 font-medium">Employee</th>
                <th class="px-3 py-2.5 font-medium">Due</th>
                <th class="px-3 py-2.5 font-medium">Installment</th>
                <th class="px-3 py-2.5 text-right font-medium">Amount</th>
                <th class="px-5 py-2.5 text-right font-medium">State</th>
              </tr>
            </thead>
            <tbody class="divide-y">
              <tr v-for="row in installments" :key="row.id" class="transition hover:bg-muted/40">
                <td class="px-5 py-2.5">
                  <button type="button" class="text-left hover:underline" @click="router.visit(route('payroll.loans.show', row.loan_id))">
                    <span class="block font-medium leading-tight">{{ row.employee_name || row.employee_id }}</span>
                    <span class="block text-[11px] text-muted-foreground">advance #{{ row.loan_id }}</span>
                  </button>
                </td>
                <td class="px-3 py-2.5 tabular-nums text-muted-foreground">{{ row.due_date }}</td>
                <td class="px-3 py-2.5 tabular-nums">{{ row.installment_number }} <span class="text-muted-foreground">/ {{ row.installment_count }}</span></td>
                <td class="px-3 py-2.5 text-right tabular-nums font-medium">{{ money(row.amount) }}</td>
                <td class="px-5 py-2.5 text-right">
                  <span class="rounded px-1.5 py-px text-[10px] font-semibold uppercase tracking-wide" :class="statusTone(row.status)">{{ row.status }}</span>
                </td>
              </tr>
            </tbody>
            <tfoot>
              <tr class="border-t bg-muted/30 text-[13px] font-semibold">
                <td class="px-5 py-2.5" colspan="3">Total for {{ period.label }}</td>
                <td class="px-3 py-2.5 text-right tabular-nums">{{ money(totals.amount) }}</td>
                <td class="px-5 py-2.5" />
              </tr>
            </tfoot>
          </table>
        </div>
      </section>
    </div>
  </AdminLayout>
</template>
