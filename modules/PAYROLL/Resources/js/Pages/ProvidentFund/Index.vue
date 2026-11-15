<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import {
  CalendarClock,
  CheckCircle2,
  CircleAlert,
  History,
  Landmark,
  Printer,
} from 'lucide-vue-next';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import { Button } from '@/components/ui/button';
import { useToast } from '@core/Composables/useToast';
import { confirmDialog } from '@core/Composables/useConfirm';
import { printDocument } from '@core/Composables/usePrint';
import { formatCurrency } from '@core/Utils/format';

/**
 * The contribution register.
 *
 * One line per payroll month: which sheet produced it, how many members, both sides of the
 * contribution, how much has been recognised and how much of that has reached the fund.
 *
 * A month can only be accrued once its sheet is **released**. While it is `ready` it can still be
 * recalculated, and recognising a liability against figures that can still move is how a fund ends
 * up describing a payroll that never happened. So every month carries its own reason, on the row —
 * a disabled button with no explanation is what made the old screen look broken.
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

interface Activity {
  period: string;
  members: number;
  employee_side: number;
  employer_side: number;
  accrued: number;
  settled: number;
  total: number;
}

const props = defineProps<{
  summary: Summary;
  cash: Cash;
  periods: Period[];
  activity: Activity[];
  canManage: boolean;
}>();

const toast = useToast();
const money = (value: number | string | null | undefined): string => formatCurrency(Number(value ?? 0));

const monthLabel = (period: string): string => {
  if (!period) return '—';
  const [year, month] = period.split('-').map(Number);
  if (!year || !month) return period;
  return new Date(year, month - 1, 1).toLocaleDateString('en-GB', { month: 'long', year: 'numeric' });
};

/** `activity` and `periods` describe the same months — joined here, so a month appears exactly once. */
const rows = computed(() => {
  const byPeriod = new Map(props.activity.map((row) => [row.period, row]));

  return props.periods
    .map((period) => {
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
    })
    .sort((a, b) => b.period.localeCompare(a.period));
});

const totals = computed(() => rows.value.reduce((carry, row) => ({
  employees: carry.employees + row.employee_side,
  employer: carry.employer + row.employer_side,
  accrued: carry.accrued + row.accrued,
  settled: carry.settled + row.settled,
}), { employees: 0, employer: 0, accrued: 0, settled: 0 }));

const awaitingRelease = computed(() => rows.value.filter((r) => !r.can_accrue && r.posted_members === 0).length);

/* -------------------------------- actions -------------------------------- */

const accrueForm = useForm({ pay_year: 0, pay_month: 0 });
const busy = ref<string | null>(null);

async function accrue(row: { period: string; employees: number; posted_members: number }): Promise<void> {
  const [year, month] = row.period.split('-').map(Number);
  const proceed = await confirmDialog({
    title: `Accrue ${monthLabel(row.period)}`,
    description: `Recognise both sides of the fund for ${row.employees} employee(s). Posts one credit per member per side; re-running changes nothing. Cash does not move, so the liability rises and the bank balance does not.`,
    confirmLabel: 'Accrue contributions',
    danger: false,
  });
  if (!proceed) return;

  busy.value = `accrue:${row.period}`;
  accrueForm.pay_year = year;
  accrueForm.pay_month = month;
  accrueForm.post(route('payroll.pf.accrue'), {
    preserveScroll: true,
    onError: (errors) => toast.error(Object.values(errors).join(' ')),
    onFinish: () => { busy.value = null; },
  });
}

async function settle(period: string): Promise<void> {
  const proceed = await confirmDialog({
    title: `Settle ${monthLabel(period)}`,
    description: 'Mark this month\'s accruals settled: the cash has reached the fund. Member balances do not change — only the cash side moves.',
    confirmLabel: 'Mark settled',
    danger: false,
  });
  if (!proceed) return;

  busy.value = `settle:${period}`;
  router.post(route('payroll.pf.settle'), { period }, {
    preserveScroll: true,
    onFinish: () => { busy.value = null; },
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
</script>

<template>
  <AdminLayout :breadcrumbs="[{ title: 'Payroll' }, { title: 'Provident Fund', href: route('payroll.pf.dashboard') }, { title: 'Contributions' }]">
    <Head title="PF contributions" />

    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="flex items-center gap-2.5 text-xl font-bold tracking-tight">
          <span class="grid size-9 place-items-center rounded-xl bg-gradient-to-br from-primary to-primary/70 text-primary-foreground shadow-sm">
            <CalendarClock class="size-5" />
          </span>
          Contribution register
        </h1>
        <p class="mt-1 text-sm text-muted-foreground">
          Every payroll month, what it recognised and what has reached the fund — the ledger's chronological view.
        </p>
      </div>
      <div class="flex flex-wrap gap-2">
        <Button v-if="canManage" size="sm" variant="outline" :disabled="backfilling" @click="backfill">
          <History class="size-4" />{{ backfilling ? 'Posting…' : 'Post history' }}
        </Button>
        <Button size="sm" variant="outline" @click="printDocument()"><Printer class="size-4" />Print</Button>
        <Button size="sm" variant="outline" @click="router.visit(route('payroll.pf.members'))">
          <Landmark class="size-4" />Members
        </Button>
      </div>
    </div>

    <!-- Figures -->
    <div class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <div class="rounded-xl border bg-card p-5 shadow-sm">
        <p class="text-xs font-medium uppercase tracking-wider text-muted-foreground">Total recognised</p>
        <p class="mt-1.5 text-2xl font-bold tabular-nums tracking-tight">{{ money(summary.employee_side + summary.employer_side) }}</p>
        <p class="mt-1 text-xs text-muted-foreground">both shares, all months</p>
      </div>
      <div class="rounded-xl border bg-card p-5 shadow-sm">
        <p class="text-xs font-medium uppercase tracking-wider text-muted-foreground">Collected</p>
        <p class="mt-1.5 text-2xl font-bold tabular-nums tracking-tight text-emerald-600 dark:text-emerald-400">{{ money(cash.received) }}</p>
        <p class="mt-1 text-xs text-muted-foreground">cash actually in the fund</p>
      </div>
      <div class="rounded-xl border bg-card p-5 shadow-sm">
        <p class="text-xs font-medium uppercase tracking-wider text-muted-foreground">Outstanding</p>
        <p class="mt-1.5 text-2xl font-bold tabular-nums tracking-tight text-amber-600 dark:text-amber-400">{{ money(cash.outstanding) }}</p>
        <p class="mt-1 text-xs text-muted-foreground">recognised, not yet collected</p>
      </div>
      <div class="rounded-xl border bg-card p-5 shadow-sm">
        <p class="text-xs font-medium uppercase tracking-wider text-muted-foreground">Last accrued</p>
        <p class="mt-1.5 text-2xl font-bold tracking-tight">{{ summary.last_period ? monthLabel(summary.last_period) : '—' }}</p>
        <p class="mt-1 text-xs text-muted-foreground">{{ cash.lines.toLocaleString() }} ledger entries</p>
      </div>
    </div>

    <div v-if="awaitingRelease" class="mt-4 flex items-start gap-2.5 rounded-xl border border-amber-500/30 bg-amber-500/[0.06] px-4 py-3 text-sm">
      <CircleAlert class="mt-0.5 size-4 shrink-0 text-amber-600 dark:text-amber-400" />
      <p>
        <strong>{{ awaitingRelease }} month(s) cannot be accrued yet.</strong>
        A sheet must be <strong>released</strong> before its fund can be recognised — while it is <em>ready</em> it can still be
        recalculated. Release the month in <span class="font-medium">Payroll → Salary sheets</span>, then come back.
      </p>
    </div>

    <!-- Register -->
    <section class="mt-4 rounded-xl border bg-card shadow-sm" data-core-print>
      <div class="flex items-start justify-between gap-3 border-b px-5 py-4">
        <div>
          <h2 class="text-sm font-semibold">Contribution schedule</h2>
          <p class="mt-0.5 text-xs text-muted-foreground">Newest first. Accrue recognises the liability; settle marks the cash as received.</p>
        </div>
        <span class="rounded-md bg-muted px-2.5 py-1 text-xs text-muted-foreground">{{ rows.length }} month(s)</span>
      </div>

      <div v-if="rows.length === 0" class="px-5 py-16 text-center">
        <CalendarClock class="mx-auto size-8 text-muted-foreground/40" />
        <p class="mt-2 text-sm font-medium">No payroll month exists yet</p>
        <p class="mt-1 text-xs text-muted-foreground">Run payroll for a month and release the sheet before accruing its fund.</p>
      </div>

      <div v-else class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="border-b bg-muted/40 text-[11px] uppercase tracking-wider text-muted-foreground">
              <th class="px-5 py-2.5 text-left font-semibold">Payroll month</th>
              <th class="px-3 py-2.5 text-left font-semibold">Sheet</th>
              <th class="px-3 py-2.5 text-right font-semibold">Members</th>
              <th class="px-3 py-2.5 text-right font-semibold">Employee share</th>
              <th class="px-3 py-2.5 text-right font-semibold">Employer share</th>
              <th class="px-3 py-2.5 text-right font-semibold">Recognised</th>
              <th class="px-3 py-2.5 text-right font-semibold">Settled</th>
              <th class="px-5 py-2.5 text-right font-semibold">Action</th>
            </tr>
          </thead>
          <tbody class="divide-y">
            <tr v-for="row in rows" :key="row.period" class="transition hover:bg-muted/30">
              <td class="px-5 py-3">
                <span class="font-medium">{{ monthLabel(row.period) }}</span>
                <span v-if="!row.can_accrue" class="mt-0.5 block max-w-[18rem] text-[11px] leading-tight text-muted-foreground">{{ row.reason }}</span>
              </td>
              <td class="px-3 py-3">
                <span class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide" :class="row.status === 'released' ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400' : 'bg-amber-500/10 text-amber-700 dark:text-amber-400'">
                  <span class="size-1.5 rounded-full" :class="row.status === 'released' ? 'bg-emerald-500' : 'bg-amber-500'"></span>
                  {{ row.status }}
                </span>
              </td>
              <td class="px-3 py-3 text-right tabular-nums">{{ (row.members || row.employees).toLocaleString() }}</td>
              <td class="px-3 py-3 text-right tabular-nums">{{ money(row.employee_side) }}</td>
              <td class="px-3 py-3 text-right tabular-nums">{{ money(row.employer_side) }}</td>
              <td class="px-3 py-3 text-right tabular-nums">
                <span :class="row.accrued > 0 ? 'font-medium text-amber-600 dark:text-amber-400' : 'text-muted-foreground'">{{ money(row.accrued) }}</span>
              </td>
              <td class="px-3 py-3 text-right tabular-nums">
                <span :class="row.settled > 0 ? 'font-medium text-emerald-600 dark:text-emerald-400' : 'text-muted-foreground'">{{ money(row.settled) }}</span>
              </td>
              <td class="whitespace-nowrap px-5 py-3 text-right">
                <div v-if="canManage" class="flex justify-end gap-1.5">
                  <Button v-if="row.can_accrue" size="sm" variant="outline" class="h-7 px-2.5 text-[11px]"
                          :disabled="busy === 'accrue:' + row.period" @click="accrue(row)">
                    {{ busy === 'accrue:' + row.period ? 'Accruing…' : (row.posted_members ? 'Re-run' : 'Accrue') }}
                  </Button>
                  <Button v-if="row.accrued > 0" size="sm" variant="ghost" class="h-7 px-2.5 text-[11px]"
                          :disabled="busy === 'settle:' + row.period" @click="settle(row.period)">
                    {{ busy === 'settle:' + row.period ? 'Settling…' : 'Settle' }}
                  </Button>
                  <span v-if="!row.can_accrue && row.accrued === 0" class="self-center text-[11px] text-muted-foreground">Awaiting release</span>
                </div>
                <span v-else class="text-[11px] text-muted-foreground">—</span>
              </td>
            </tr>
          </tbody>
          <tfoot class="border-t bg-muted/40 text-sm font-semibold">
            <tr>
              <td class="px-5 py-2.5" colspan="3">Total</td>
              <td class="px-3 py-2.5 text-right tabular-nums">{{ money(totals.employees) }}</td>
              <td class="px-3 py-2.5 text-right tabular-nums">{{ money(totals.employer) }}</td>
              <td class="px-3 py-2.5 text-right tabular-nums">{{ money(totals.accrued) }}</td>
              <td class="px-3 py-2.5 text-right tabular-nums">{{ money(totals.settled) }}</td>
              <td class="px-5"></td>
            </tr>
          </tfoot>
        </table>
      </div>
    </section>

    <p class="mt-3 flex items-center gap-1.5 text-xs text-muted-foreground">
      <CheckCircle2 class="size-3.5 text-emerald-500" />
      Accrual is idempotent: re-running a month posts only what is missing, never a second credit.
    </p>
  </AdminLayout>
</template>
