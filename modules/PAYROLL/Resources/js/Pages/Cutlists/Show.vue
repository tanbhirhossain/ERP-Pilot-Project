<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import {
  ArrowLeft,
  CalendarRange,
  Check,
  ClipboardCheck,
  Filter,
  Info,
  Minus,
  Pencil,
  Users,
  Wallet,
  X,
} from 'lucide-vue-next';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import DataTable, { type DataTableColumn } from '@core/Components/DataTable.vue';
import StatusBadge from '@core/Components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import ModuleNav from './ModuleNav.vue';
import { confirmDialog } from '@core/Composables/useConfirm';
import { useToast } from '@core/Composables/useToast';
import { formatCurrency, formatDate, formatDateTime, formatNumber } from '@core/Utils/format';
import type { Paginator } from '@core/types';

/**
 * Reviewing one month's proposal.
 *
 * The table this replaces had sixteen columns and no answer. Four of them were money and twelve were
 * attendance counts, and the two were interleaved with nothing saying which was which — so "8
 * punishment days" sat next to "0.00" on 465 rows and read like a fault, when it is the correct
 * outcome of a non-punishable HRM policy.
 *
 * What changed here is not the styling:
 *
 *  - **Money is separated from evidence.** Deductions and extra pay are columns; late days, missing
 *    punches and approved hours are the reason a number exists, shown on demand per row.
 *  - **Every row explains itself.** `explain()` turns the stored `source_breakdown` into sentences —
 *    the day rate, the multiplier, the dates — instead of raw JSON in a `<pre>`.
 *  - **Segments come before search.** A reviewer works through 4 rows where money moves, not 981
 *    alphabetically, so those four are one click away and the count is on the tab.
 */

interface ExplanationLine { tone: 'danger' | 'ok' | 'warn' | 'muted'; text: string }
interface Explanation {
  lines: ExplanationLine[];
  dates: { late: string[]; early_out: string[]; missing_punch: string[] };
  attendance_rows: number;
  duty_hours: number;
  adjustments: Array<{ at: string; name: string | null; reason: string; changes: Record<string, { from: number; to: number }> }>;
}

interface Row {
  id: number;
  employee_id: string;
  employee_name: string;
  unpaid_leave_days: number | string;
  late_count: number;
  early_out_count: number;
  punishment_days: number;
  punishable: boolean;
  missing_punch_count: number;
  unpaid_leave_deduction: number | string;
  late_penalty_deduction: number | string;
  missing_punch_deduction: number | string;
  approved_overtime_minutes: number;
  approved_extra_duty_minutes: number;
  overtime_pay: number | string;
  extra_duty_pay: number | string;
  explanation: Explanation;
}

interface Segment { key: string; label: string; count: number; hint: string }

const props = defineProps<{
  cutlist: {
    id: number;
    pay_year: number;
    pay_month: number;
    status: string;
    generated_at: string | null;
    approved_at: string | null;
    approval_note: string | null;
  };
  items: Paginator<Row>;
  context: {
    period: string;
    aggregate: { items: number; deductions: number; earnings: number; penalty: number; chargeable: number; flagged: number; with_ot: number; adjustments: number };
    segments: Segment[];
    sheet: { id: number; status: string } | null;
    consumed: boolean;
    review_required: boolean;
    editable: boolean;
    adjustments: Array<{ employee_id: string; employee_name: string; at: string; name: string | null; reason: string; changes: Array<{ field: string; from: number; to: number }> }>;
  };
  readiness: { checks: Array<{ key: string; label: string; ok: boolean; blocking: boolean; detail: string }>; blocking_failures: number; status: string };
  segment: string;
  totals: { deductions: number; earnings: number; penalty: number; rows: number };
  editable: boolean;
  consumedBySheet: boolean;
  reviewRequired: boolean;
}>();

const toast = useToast();
const money = (value: number | string | null | undefined): string => formatCurrency(Number(value ?? 0));
const hours = (minutes: number | string | null | undefined): string => (Number(minutes ?? 0) / 60).toFixed(2);

const agg = computed(() => props.context.aggregate);

/* -------------------------------- segments ------------------------------- */

const goSegment = (key: string): void => {
  router.visit(route('payroll.cutlists.show', props.cutlist.id), { data: { segment: key }, preserveScroll: true, preserveState: true });
};

/* -------------------------------- explain -------------------------------- */

const expanded = ref<number | null>(null);
const toggle = (id: number): void => { expanded.value = expanded.value === id ? null : id; };

const toneClass = (tone: string): string => ({
  danger: 'text-danger',
  ok: 'text-success',
  warn: 'text-warning',
  muted: 'text-muted-foreground',
}[tone] ?? 'text-muted-foreground');

/* -------------------------------- adjust --------------------------------- */

const editing = ref<Row | null>(null);
const moneyFields = [
  { key: 'unpaid_leave_deduction', label: 'Unpaid leave deduction' },
  { key: 'late_penalty_deduction', label: 'Punishment deduction' },
  { key: 'missing_punch_deduction', label: 'Missing-punch deduction' },
  { key: 'overtime_pay', label: 'Overtime pay' },
  { key: 'extra_duty_pay', label: 'Extra duty pay' },
] as const;

const adjust = useForm<Record<string, number | string | null>>({ reason: '', unpaid_leave_deduction: null, late_penalty_deduction: null, missing_punch_deduction: null, overtime_pay: null, extra_duty_pay: null });

const openAdjust = (row: Row): void => {
  if (!props.editable) return;
  editing.value = row;
  adjust.clearErrors();
  for (const field of moneyFields) {
    (adjust as unknown as Record<string, number>)[field.key] = Number((row as unknown as Record<string, number>)[field.key] ?? 0);
  }
  adjust.reason = '';
};

/** The delta is shown live, because "3,000 → 0" is the decision and the box only holds a number. */
const delta = computed(() => {
  if (!editing.value) return 0;
  let sum = 0;
  for (const field of moneyFields) {
    const before = Number((editing.value as unknown as Record<string, number>)[field.key] ?? 0);
    const after = Number((adjust as unknown as Record<string, number>)[field.key] ?? 0);
    // Deductions and earnings both move the payslip, in opposite directions.
    const sign = field.key.endsWith('_pay') ? 1 : -1;
    sum += (after - before) * sign;
  }
  return sum;
});

const submitAdjust = async (): Promise<void> => {
  if (!editing.value) return;
  if ((adjust.reason ?? '').trim().length < 3) {
    toast.warning('A reason is required — it is kept on the row and shown to auditors.');
    return;
  }
  const proceed = await confirmDialog({
    title: `Change ${editing.value.employee_name}'s ${props.context.period} figures?`,
    description: 'The amounts you entered replace what HRM produced for this employee when the salary sheet calculates. The change and your reason are stored on the row permanently.',
    confirmLabel: 'Save adjustment',
    danger: false,
  });
  if (!proceed) return;
  adjust.patch(route('payroll.cutlists.items.update', [props.cutlist.id, editing.value.id]), {
    preserveScroll: true,
    onSuccess: () => { editing.value = null; toast.success('Adjustment recorded against the row.'); },
  });
};

/* -------------------------------- columns -------------------------------- */

const columns: DataTableColumn[] = [
  { key: 'employee_name', label: 'Employee', sortable: true },
  { key: 'deductions', label: 'Deductions', align: 'right' },
  { key: 'earnings', label: 'Extra pay', align: 'right' },
  { key: 'evidence', label: 'Attendance evidence' },
  { key: 'punishable', label: 'Chargeable', align: 'center' },
  { key: 'actions', label: '', align: 'right', exportable: false },
];

const anyDeduction = (row: Row): number =>
  Number(row.unpaid_leave_deduction ?? 0) + Number(row.late_penalty_deduction ?? 0) + Number(row.missing_punch_deduction ?? 0);
const anyEarning = (row: Row): number => Number(row.overtime_pay ?? 0) + Number(row.extra_duty_pay ?? 0);
</script>

<template>
  <AdminLayout
    :breadcrumbs="[
      { title: 'Payroll' },
      { title: 'Salary cutlists', href: route('payroll.cutlists.index') },
      { title: context.period },
    ]"
  >
    <Head :title="`Cutlist — ${context.period}`" />

    <div class="space-y-4">
      <ModuleNav />

      <!-- Header -->
      <PageHeader
        :title="context.period"
        :icon="ClipboardCheck"
        :description="`${formatNumber(agg.items)} proposal row(s) from HRM attendance · generated ${formatDate(cutlist.generated_at)}${cutlist.approved_at ? ' · approved ' + formatDate(cutlist.approved_at) : ''}`"
      >
        <template #badge>
          <StatusBadge :label="cutlist.status" :tone="cutlist.status === 'approved' ? 'success' : cutlist.status === 'generated' ? 'warning' : 'neutral'" dot />
        </template>
        <template #actions>
          <Link :href="route('payroll.cutlists.index')">
            <Button size="sm" variant="outline"><ArrowLeft class="size-4" />All periods</Button>
          </Link>
          <Button size="sm" @click="router.visit(route('payroll.cutlists.sign-off', cutlist.id))">
            <Check class="size-4" />Sign-off desk
          </Button>
        </template>
      </PageHeader>

      <!-- The one sentence that was missing from the old screen -->
      <div v-if="agg.chargeable < agg.flagged" class="flex items-start gap-2.5 rounded-xl border border-info/30 bg-info/5 p-3.5">
        <Info class="mt-0.5 size-4 shrink-0 text-info" />
        <p class="text-[13px] leading-snug">
          <strong>{{ formatNumber(agg.flagged) }} employee(s) recorded late or early-out days; {{ formatNumber(agg.chargeable) }} are actually chargeable.</strong>
          A punishment day only becomes money when the employee's HRM policy is <em>punishable</em> — the rest keep the
          attendance record but are charged nothing. That is why rows below show days beside ৳0.00.
        </p>
      </div>

      <!-- Figures -->
      <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-xl border bg-card p-5 shadow-sm">
          <span class="grid size-11 place-items-center rounded-xl bg-warning/10 text-warning"><Wallet class="size-5" /></span>
          <p class="mt-3 text-2xl font-bold tabular-nums tracking-tight">{{ money(agg.deductions) }}</p>
          <p class="text-[13px] font-medium text-muted-foreground">Deductions proposed</p>
          <p class="mt-1 text-xs text-muted-foreground">{{ money(agg.penalty) }} of it punishment</p>
        </div>
        <div class="rounded-xl border bg-card p-5 shadow-sm">
          <span class="grid size-11 place-items-center rounded-xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-400"><Wallet class="size-5" /></span>
          <p class="mt-3 text-2xl font-bold tabular-nums tracking-tight">{{ money(agg.earnings) }}</p>
          <p class="text-[13px] font-medium text-muted-foreground">Approved extra pay</p>
          <p class="mt-1 text-xs text-muted-foreground">{{ formatNumber(agg.with_ot) }} employee(s) with approved OT or extra duty</p>
        </div>
        <div class="rounded-xl border bg-card p-5 shadow-sm">
          <span class="grid size-11 place-items-center rounded-xl bg-violet-500/10 text-violet-600 dark:text-violet-400"><Users class="size-5" /></span>
          <p class="mt-3 text-2xl font-bold tabular-nums tracking-tight">{{ formatNumber(agg.chargeable) }}</p>
          <!--
            This counted `punishable`, which is the *attendance policy's* flag — "this employee's
            policy allows punishment" — not a finding about the month. Four employees had it set while
            every counter on their row was zero, so the card read "4 Chargeable employees" above a
            table in which all four say "Not charged". It also printed "4 of 0": the denominator
            counted rows with late days, a different population again.
          -->
          <p class="text-[13px] font-medium text-muted-foreground">Employees whose policy allows punishment</p>
          <p v-if="agg.with_attendance > 0" class="mt-1 text-xs text-muted-foreground">
            {{ formatNumber(agg.with_attendance) }} with attendance evidence ·
            {{ formatNumber(agg.flagged) }} with days to punish
          </p>
          <!--
            A generated cutlist for a month nobody has attendance for is not a review with nothing to
            review; it is a cutlist computed from an empty month. Saying "0 of 0" hid that.
          -->
          <p v-else class="mt-1 text-xs text-muted-foreground">
            No attendance evidence in this month — the cutlist was generated before any was recorded,
            so every row is zero for absence of data, not for good conduct.
          </p>
        </div>
        <div class="rounded-xl border bg-card p-5 shadow-sm">
          <span class="grid size-11 place-items-center rounded-xl bg-sky-500/10 text-sky-600 dark:text-sky-400"><Pencil class="size-5" /></span>
          <p class="mt-3 text-2xl font-bold tabular-nums tracking-tight">{{ formatNumber(agg.adjustments) }}</p>
          <p class="text-[13px] font-medium text-muted-foreground">Reviewer adjustments</p>
          <p class="mt-1 text-xs text-muted-foreground">each one carries a reason and a before/after</p>
        </div>
      </div>

      <!-- Segments -->
      <div class="flex flex-wrap items-center gap-2">
        <span class="flex items-center gap-1.5 text-[11px] font-medium uppercase tracking-wider text-muted-foreground"><Filter class="size-3.5" />Show</span>
        <button
          v-for="seg in context.segments"
          :key="seg.key"
          type="button"
          class="group flex items-center gap-2 rounded-lg border px-3 py-1.5 text-[13px] transition"
          :class="segment === seg.key ? 'border-primary/40 bg-primary/5 font-medium' : 'bg-card hover:bg-muted/40'"
          :title="seg.hint"
          @click="goSegment(seg.key)"
        >
          {{ seg.label }}
          <span class="rounded-full px-1.5 py-px text-[10px] font-semibold tabular-nums" :class="segment === seg.key ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground'">
            {{ formatNumber(seg.count) }}
          </span>
        </button>
      </div>

      <!-- Sheet state -->
      <div v-if="context.sheet" class="flex flex-wrap items-center justify-between gap-3 rounded-xl border bg-muted/30 p-3.5">
        <p class="text-[13px]">
          <strong>This cutlist already fed salary sheet #{{ context.sheet.id }}</strong> ({{ context.sheet.status }}), so its
          rows are closed for editing — changing them now would give the review and the paid figures two different truths.
          <span class="text-muted-foreground">The figures beside each employee on the sheet are what was actually paid.</span>
        </p>
        <Link :href="route('payroll.sheets.show', context.sheet.id)" class="text-xs font-medium text-primary hover:underline">Open the sheet →</Link>
      </div>

      <!-- Rows -->
      <DataTable
        :route-name="'payroll.cutlists.show'"
        :route-params="{ cutlist: cutlist.id, segment }"
        title="Proposal rows"
        :columns="columns"
        :rows="items.data"
        :meta="items.meta"
        :default-sort="{ key: 'employee_name', direction: 'asc' }"
        empty-title="No row in this segment"
        empty-description="That filter found nothing — which is itself an answer: nothing in this month moves money in that way."
      >
        <template #cell-employee_name="{ row }">
          <button type="button" class="text-left" @click="toggle(row.id)">
            <strong class="font-medium">{{ row.employee_name }}</strong>
            <span class="block text-xs text-muted-foreground">{{ row.employee_id }}</span>
          </button>

          <!--
            The explanation, in the row it explains. Every figure on a cutlist has a reason — a day
            rate, a policy flag, a set of dates — and this is where a reviewer needs it: beside the
            number, at the moment they are deciding whether it is right.
          -->
          <div v-if="expanded === row.id" class="mt-2 max-w-2xl space-y-1.5 rounded-lg border bg-muted/20 p-2.5">
            <p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Why these numbers</p>
            <ul class="space-y-1">
              <li v-for="(line, i) in row.explanation.lines" :key="i" class="flex items-start gap-1.5 text-[11px] leading-snug">
                <span class="mt-1 size-1 shrink-0 rounded-full" :class="line.tone === 'danger' ? 'bg-danger' : line.tone === 'ok' ? 'bg-success' : line.tone === 'warn' ? 'bg-warning' : 'bg-muted-foreground/50'" />
                <span :class="toneClass(line.tone)">{{ line.text }}</span>
              </li>
            </ul>
            <div v-if="row.explanation.dates.late.length" class="pt-0.5">
              <span class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Late dates</span>
              <span class="ml-1 font-mono text-[10px]">{{ row.explanation.dates.late.join(' · ') }}</span>
            </div>
            <div v-if="row.explanation.dates.early_out.length">
              <span class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Early-out dates</span>
              <span class="ml-1 font-mono text-[10px]">{{ row.explanation.dates.early_out.join(' · ') }}</span>
            </div>
            <div v-if="row.explanation.dates.missing_punch.length">
              <span class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Missing punches</span>
              <span class="ml-1 font-mono text-[10px]">{{ row.explanation.dates.missing_punch.join(' · ') }}</span>
            </div>
            <p class="text-[10px] text-muted-foreground">
              {{ row.explanation.attendance_rows }} attendance row(s) · {{ row.explanation.duty_hours }}h duty day
            </p>
          </div>
        </template>

        <template #cell-deductions="{ row }">
          <span class="tabular-nums" :class="anyDeduction(row) > 0 ? 'font-semibold text-foreground' : 'text-muted-foreground'">
            {{ money(anyDeduction(row)) }}
          </span>
        </template>

        <template #cell-earnings="{ row }">
          <span class="tabular-nums" :class="anyEarning(row) > 0 ? 'font-semibold text-foreground' : 'text-muted-foreground'">
            {{ money(anyEarning(row)) }}
          </span>
        </template>

        <template #cell-evidence="{ row }">
          <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-muted-foreground">
            <span v-if="Number(row.punishment_days) > 0">{{ row.punishment_days }} late/early day(s)</span>
            <span v-if="Number(row.missing_punch_count) > 0">{{ row.missing_punch_count }} missing punch</span>
            <span v-if="Number(row.unpaid_leave_days) > 0">{{ row.unpaid_leave_days }} unpaid day(s)</span>
            <span v-if="Number(row.approved_overtime_minutes) > 0">{{ hours(row.approved_overtime_minutes) }} h OT</span>
            <span v-if="Number(row.approved_extra_duty_minutes) > 0">{{ hours(row.approved_extra_duty_minutes) }} h extra</span>
            <span v-if="!row.punishment_days && !row.missing_punch_count && !row.unpaid_leave_days && !row.approved_overtime_minutes && !row.approved_extra_duty_minutes">
              clean month
            </span>
          </div>
        </template>

        <template #cell-punishable="{ row }">
          <span
            class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-semibold"
            :class="row.punishable ? 'bg-rose-500/10 text-rose-600 dark:text-rose-400' : 'bg-muted text-muted-foreground'"
          >
            <component :is="row.punishable ? Check : Minus" class="size-2.5" />
            {{ row.punishable ? 'Charged' : 'Not charged' }}
          </span>
        </template>

        <template #cell-actions="{ row }">
          <div class="flex items-center justify-end gap-3">
            <button type="button" class="text-xs font-medium text-muted-foreground hover:text-foreground" @click="toggle(row.id)">
              {{ expanded === row.id ? 'Hide' : 'Why' }}
            </button>
            <Button v-if="editable" size="sm" variant="outline" class="h-7 px-2 text-xs" @click="openAdjust(row)">
              Adjust
            </Button>
          </div>
        </template>

      </DataTable>

      <!-- Adjustment trail -->
      <section v-if="context.adjustments.length" class="rounded-xl border bg-card shadow-sm">
        <div class="border-b px-5 py-4">
          <h2 class="flex items-center gap-2 text-sm font-semibold"><Pencil class="size-4 text-muted-foreground" />Every change made to this month</h2>
          <p class="mt-0.5 text-xs text-muted-foreground">Newest first — the whole trail, not just the rows currently on screen.</p>
        </div>
        <ul class="divide-y">
          <li v-for="(entry, i) in context.adjustments" :key="i" class="px-5 py-2.5">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
              <p class="text-[13px] font-medium">{{ entry.employee_name }} <span class="font-mono text-[11px] text-muted-foreground">{{ entry.employee_id }}</span></p>
              <p class="text-[11px] text-muted-foreground">{{ entry.name || 'system' }} · {{ formatDateTime(entry.at) }}</p>
            </div>
            <p class="mt-0.5 text-xs text-muted-foreground">{{ entry.reason }}</p>
            <p class="mt-0.5 font-mono text-[11px]">
              <span v-for="(change, j) in entry.changes" :key="j" class="mr-3 inline-block">
                {{ change.field }}: {{ money(change.from) }} → <strong>{{ money(change.to) }}</strong>
              </span>
            </p>
          </li>
        </ul>
      </section>

      <p class="text-[11px] text-muted-foreground">
        Totals above cover the whole month ({{ formatNumber(totals.rows) }} rows), not the segment on screen:
        {{ money(totals.deductions) }} in deductions and {{ money(totals.earnings) }} in extra pay.
      </p>
    </div>

    <!-- Adjust dialog -->
    <div v-if="editing" class="fixed inset-0 z-50 grid place-items-center bg-black/40 p-4" @click.self="editing = null">
      <div class="w-full max-w-lg rounded-xl border bg-card p-5 shadow-xl">
        <div class="flex items-start justify-between">
          <div>
            <h3 class="text-sm font-semibold">Adjust {{ editing.employee_name }}</h3>
            <p class="mt-0.5 text-xs text-muted-foreground">{{ context.period }} · {{ editing.employee_id }}</p>
          </div>
          <button type="button" class="text-muted-foreground hover:text-foreground" @click="editing = null"><X class="size-4" /></button>
        </div>

        <p class="mt-2 rounded-lg bg-muted/40 p-2.5 text-[11px] leading-relaxed text-muted-foreground">
          These five amounts are exactly what the salary sheet will deduct or pay. Leave a field alone to keep what HRM
          produced; every change is stored on the row with your reason.
        </p>

        <div class="mt-3 grid grid-cols-2 gap-3">
          <div v-for="field in moneyFields" :key="field.key">
            <Label class="text-xs">{{ field.label }}</Label>
            <Input v-model.number="(adjust as any)[field.key]" type="number" min="0" step="0.01" class="mt-1 h-9" />
            <p class="mt-0.5 text-[10px] text-muted-foreground">was {{ money((editing as any)[field.key]) }}</p>
          </div>
        </div>

        <div class="mt-3 rounded-lg border p-2.5 text-xs" :class="delta === 0 ? 'bg-muted/30' : delta > 0 ? 'border-success/30 bg-success/5' : 'border-warning/30 bg-warning/5'">
          <span class="font-medium">Net effect on this payslip:</span>
          <span class="ml-1 font-mono font-semibold tabular-nums" :class="delta === 0 ? '' : delta > 0 ? 'text-success' : 'text-warning'">
            {{ delta >= 0 ? '+' : '' }}{{ money(delta) }}
          </span>
          <span class="ml-1 text-muted-foreground">{{ delta === 0 ? 'unchanged' : 'compared with what HRM produced' }}</span>
        </div>

        <div class="mt-3">
          <Label class="text-xs">Reason (required, kept on the row and shown to auditors)</Label>
          <Textarea v-model="adjust.reason" class="mt-1" rows="2" maxlength="1000" placeholder="e.g. Two unapproved late marks waived — shift log SL-88 confirms the line changeover." />
          <p v-for="(message, key) in adjust.errors" :key="key" class="mt-1 text-xs text-danger">{{ message }}</p>
        </div>

        <div class="mt-4 flex justify-end gap-2">
          <Button size="sm" variant="outline" @click="editing = null">Cancel</Button>
          <Button size="sm" :disabled="adjust.processing" @click="submitAdjust">Save adjustment</Button>
        </div>
      </div>
    </div>
  </AdminLayout>
</template>
