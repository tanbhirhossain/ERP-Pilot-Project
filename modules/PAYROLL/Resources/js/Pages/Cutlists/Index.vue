<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import {
  AlertTriangle,
  ArrowRight,
  Calculator,
  CalendarRange,
  CircleCheck,
  ClipboardCheck,
  Info,
  RefreshCw,
  ShieldAlert,
  Users,
  Wallet,
} from 'lucide-vue-next';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import StatusBadge from '@core/Components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import ModuleNav from './ModuleNav.vue';
import { confirmDialog } from '@core/Composables/useConfirm';
import { formatCurrency, formatNumber } from '@core/Utils/format';
import type { Paginator } from '@core/types';

/**
 * The cutlist periods desk.
 *
 * A cutlist is payroll's proposal for a month: every employee row the salary sheet will consume,
 * before it becomes a payslip. The desk answers the four things somebody opening it needs — what is
 * waiting for a reviewer, what this month is worth, whether the gate is on, and what generating does
 * — instead of presenting a generate form above a table of months and leaving the rest implied.
 */

interface PeriodRow {
  id: number;
  pay_year: number;
  pay_month: number;
  period: string;
  status: string;
  generated_at: string | null;
  approved_at: string | null;
  approval_note: string | null;
  items: number;
  deductions: number;
  earnings: number;
  at_stake: number;
  chargeable: number;
  flagged: number;
  adjustments: number;
  sheet: { id: number; status: string } | null;
  consumed: boolean;
  awaiting_signoff: boolean;
}

interface Workspace {
  rows: PeriodRow[];
  counts: { cutlists: number; generated: number; approved: number; locked: number };
  totals: { employees: number; deductions: number; earnings: number; at_stake: number; awaiting: number; adjustments: number; chargeable: number };
  latest: PeriodRow | null;
}

interface ListRow {
  id: number;
  pay_year: number;
  pay_month: number;
  status: string;
  generated_at: string | null;
  approved_at: string | null;
  employee_count: number;
  deduction_total: number | string;
  earning_total: number | string;
  sheet: { id: number; status: string } | null;
}

const props = defineProps<{
  cutlists: Paginator<ListRow>;
  workspace: Workspace;
  defaultYear: number;
  defaultMonth: number;
  reviewRequired: boolean;
  canGenerate: boolean;
}>();

const money = (value: number | string | null | undefined): string => formatCurrency(Number(value ?? 0));

/* ------------------------------- generating ------------------------------ */

const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
const years = Array.from({ length: 11 }, (_, i) => new Date().getFullYear() - 5 + i);
if (!years.includes(props.defaultYear)) years.push(props.defaultYear);

const gen = useForm({
  pay_year: props.defaultYear,
  pay_month: props.defaultMonth,
  overwrite_adjustments: false,
});

const targetPeriod = computed(() => `${monthNames[gen.pay_month - 1]} ${gen.pay_year}`);
const existingForTarget = computed(() => props.workspace.rows.find((row) => row.pay_year === gen.pay_year && row.pay_month === gen.pay_month) ?? null);

/** Generating is destructive when a cutlist already exists, so it asks — and says which case it is in. */
const generate = async (): Promise<void> => {
  const existing = existingForTarget.value;
  const proceed = await confirmDialog({
    title: existing ? `Rebuild the ${targetPeriod.value} cutlist?` : `Generate the ${targetPeriod.value} cutlist?`,
    description: existing
      ? `Every row is rebuilt from HRM attendance data and any reviewer adjustment on this cutlist is erased${existing.consumed ? '. This month already feeds a salary sheet, so the rebuild will be refused' : ''}.`
      : 'Every employee is read from HRM attendance data and turned into a proposal row. Nothing is paid until it is approved and the salary sheet runs.',
    confirmLabel: existing ? 'Rebuild from HRM' : 'Generate',
    danger: Boolean(existing),
  });
  if (!proceed) return;
  gen.post(route('payroll.cutlists.generate'), { preserveScroll: true, preserveState: true });
};

/* --------------------------------- signals ------------------------------- */

const awaiting = computed(() => props.workspace.rows.filter((row) => row.awaiting_signoff && !row.consumed));
const consumedAwaiting = computed(() => props.workspace.rows.filter((row) => row.awaiting_signoff && row.consumed));

type Tone = 'ok' | 'warn' | 'error' | 'info';

const toneClass = (tone: Tone): string => ({
  ok: 'bg-success/10 text-success',
  warn: 'bg-warning/10 text-warning',
  error: 'bg-danger/10 text-danger',
  info: 'bg-info/10 text-info',
}[tone]);

interface Signal { tone: Tone; text: string; to?: string; label?: string }

const signals = computed<Signal[]>(() => {
  const list: Signal[] = [];

  for (const row of consumedAwaiting.value) {
    list.push({
      tone: 'error',
      text: `${row.period} is still marked "generated" but its salary sheet is already ${row.sheet?.status}. The sign-off can never be given — the row is a leftover of a month that ran without one.`,
      to: route('payroll.cutlists.show', row.id),
      label: 'Open',
    });
  }

  if (awaiting.value.length > 0) {
    const atStake = awaiting.value.reduce((sum, row) => sum + row.at_stake, 0);
    list.push({
      tone: 'warn',
      text: atStake > 0
        ? `${awaiting.value.map((r) => r.period).join(', ')} ${awaiting.value.length === 1 ? 'is' : 'are'} waiting for a reviewer — ${money(atStake)} of deductions and earnings is still open to change.`
        : `${awaiting.value.map((r) => r.period).join(', ')} ${awaiting.value.length === 1 ? 'is' : 'are'} waiting for a reviewer. Nothing on ${awaiting.value.length === 1 ? 'it' : 'them'} moves money yet, so the review is a check rather than a correction.`,
      to: route('payroll.cutlists.show', awaiting.value[0]!.id),
      label: 'Review',
    });
  }

  if (!props.reviewRequired) {
    list.push({
      tone: 'info',
      text: 'The payroll gate is off: a salary sheet can be queued for a month whose cutlist was never approved. Turn it on in Payroll → Settings if that sign-off should be mandatory.',
    });
  }

  if (list.length === 0) {
    list.push({ tone: 'ok', text: 'Nothing is waiting: every cutlist for a month still to be paid has been signed off.' });
  }

  return list;
});

const statusTone = (status: string): 'neutral' | 'info' | 'success' | 'warning' => ({
  generated: 'warning',
  approved: 'success',
  locked: 'info',
  draft: 'neutral',
}[status] as 'neutral' | 'info' | 'success' | 'warning' ?? 'neutral');

const adjust = (value: number): void => {
  const next = gen.pay_month + value;
  if (next < 1) {
    gen.pay_month = 12;
    gen.pay_year = Math.max(2000, gen.pay_year - 1);
  } else if (next > 12) {
    gen.pay_month = 1;
    gen.pay_year = Math.min(2100, gen.pay_year + 1);
  } else {
    gen.pay_month = next;
  }
};
</script>

<template>
  <AdminLayout :breadcrumbs="[{ title: 'Payroll' }, { title: 'Salary cutlists' }]">
    <Head title="Salary cutlists" />

    <div class="space-y-4">
      <ModuleNav />

      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 class="flex items-center gap-2.5 text-xl font-bold tracking-tight">
            <span class="grid size-9 place-items-center rounded-xl bg-gradient-to-br from-primary to-primary/70 text-primary-foreground shadow-sm">
              <ClipboardCheck class="size-5" />
            </span>
            Salary cutlists
          </h1>
          <p class="mt-1 max-w-3xl text-sm text-muted-foreground">
            The proposal payroll will pay from: every employee's attendance-derived deductions and approved extra pay,
            generated from HRM, adjusted with a reason, then signed off. Nothing here is paid — the salary sheet pays.
          </p>
        </div>
        <div class="flex items-center gap-2 rounded-xl border bg-card px-3 py-2 shadow-sm">
          <span class="text-[11px] uppercase tracking-wider text-muted-foreground">Gate</span>
          <span
            class="flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-medium"
            :class="reviewRequired ? 'bg-success/10 text-success' : 'bg-muted text-muted-foreground'"
          >
            <component :is="reviewRequired ? CircleCheck : Info" class="size-3" />
            {{ reviewRequired ? 'Approval required' : 'Optional' }}
          </span>
        </div>
      </div>

      <!-- Figures -->
      <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-xl border bg-card p-5 shadow-sm">
          <div class="flex items-start justify-between">
            <span class="grid size-11 place-items-center rounded-xl bg-warning/10 text-warning"><ClipboardCheck class="size-5" /></span>
            <span v-if="workspace.totals.awaiting > 0" class="text-[11px] text-muted-foreground">needs a reviewer</span>
          </div>
          <p class="mt-3 text-2xl font-bold tabular-nums tracking-tight">{{ formatNumber(workspace.totals.awaiting) }}</p>
          <p class="text-[13px] font-medium text-muted-foreground">Awaiting sign-off</p>
          <p class="mt-1 text-xs text-muted-foreground">of {{ workspace.counts.cutlists }} cutlist(s) in the system</p>
        </div>

        <div class="rounded-xl border bg-card p-5 shadow-sm">
          <div class="flex items-start justify-between">
            <span class="grid size-11 place-items-center rounded-xl bg-info/10 text-info"><Wallet class="size-5" /></span>
          </div>
          <p class="mt-3 text-2xl font-bold tabular-nums tracking-tight">{{ money(workspace.totals.at_stake) }}</p>
          <p class="text-[13px] font-medium text-muted-foreground">Open to change</p>
          <p class="mt-1 text-xs text-muted-foreground">deductions + earnings on cutlists still awaiting sign-off</p>
        </div>

        <div class="rounded-xl border bg-card p-5 shadow-sm">
          <div class="flex items-start justify-between">
            <span class="grid size-11 place-items-center rounded-xl bg-violet-500/10 text-violet-600 dark:text-violet-400"><Users class="size-5" /></span>
          </div>
          <p class="mt-3 text-2xl font-bold tabular-nums tracking-tight">{{ formatNumber(workspace.totals.employees) }}</p>
          <p class="text-[13px] font-medium text-muted-foreground">Employees per cutlist</p>
          <p class="mt-1 text-xs text-muted-foreground">one row each, generated from HRM attendance</p>
        </div>

        <div class="rounded-xl border bg-card p-5 shadow-sm">
          <div class="flex items-start justify-between">
            <span class="grid size-11 place-items-center rounded-xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-400"><Calculator class="size-5" /></span>
          </div>
          <p class="mt-3 text-2xl font-bold tabular-nums tracking-tight">{{ money(workspace.totals.deductions) }}</p>
          <p class="text-[13px] font-medium text-muted-foreground">Deductions proposed</p>
          <p class="mt-1 text-xs text-muted-foreground">{{ money(workspace.totals.earnings) }} of approved extra pay alongside</p>
        </div>
      </div>

      <!-- Signals -->
      <section class="rounded-xl border bg-card shadow-sm">
        <div class="flex items-start justify-between gap-3 border-b px-5 py-4">
          <div>
            <h2 class="flex items-center gap-2 text-sm font-semibold"><ShieldAlert class="size-4 text-muted-foreground" />Needs attention</h2>
            <p class="mt-0.5 text-xs text-muted-foreground">What the desk would raise before anybody signs a month off.</p>
          </div>
        </div>
        <ul class="divide-y">
          <li v-for="(signal, i) in signals" :key="i" class="flex items-start gap-3 px-5 py-3">
            <span class="mt-0.5 grid size-7 shrink-0 place-items-center rounded-lg" :class="toneClass(signal.tone)">
              <component :is="signal.tone === 'ok' ? CircleCheck : signal.tone === 'error' ? AlertTriangle : Info" class="size-3.5" />
            </span>
            <span class="min-w-0 flex-1 text-[13px] leading-snug">{{ signal.text }}</span>
            <button
              v-if="signal.to"
              type="button"
              class="mt-0.5 inline-flex shrink-0 items-center gap-0.5 text-[11px] font-medium text-primary hover:underline"
              @click="router.visit(signal.to!)"
            >
              {{ signal.label }} <ArrowRight class="size-3" />
            </button>
          </li>
        </ul>
      </section>

      <div class="grid items-start gap-4 xl:grid-cols-5">
        <!-- Periods -->
        <section class="rounded-xl border bg-card shadow-sm xl:col-span-3">
          <div class="border-b px-5 py-4">
            <h2 class="flex items-center gap-2 text-sm font-semibold"><CalendarRange class="size-4 text-muted-foreground" />Cutlists by period</h2>
            <p class="mt-0.5 text-xs text-muted-foreground">Newest first. A cutlist whose sheet already ran can no longer be edited.</p>
          </div>
          <div class="overflow-x-auto">
            <table class="w-full min-w-[640px] text-[13px]">
              <thead>
                <tr class="border-b bg-muted/40 text-left text-[11px] uppercase tracking-wider text-muted-foreground">
                  <th class="px-5 py-2.5 font-semibold">Period</th>
                  <th class="px-3 py-2.5 font-semibold">Status</th>
                  <th class="px-3 py-2.5 text-right font-semibold">Rows</th>
                  <th class="px-3 py-2.5 text-right font-semibold">Deductions</th>
                  <th class="px-3 py-2.5 text-right font-semibold">Extra pay</th>
                  <th class="px-3 py-2.5 font-semibold">Sheet</th>
                  <th class="px-5 py-2.5" />
                </tr>
              </thead>
              <tbody class="divide-y">
                <tr v-for="row in workspace.rows" :key="row.id" class="hover:bg-muted/30">
                  <td class="whitespace-nowrap px-5 py-2.5">
                    <span class="font-medium">{{ row.period }}</span>
                    <span v-if="row.adjustments > 0" class="ml-2 rounded-full bg-sky-500/10 px-1.5 py-px text-[10px] font-semibold text-sky-600 dark:text-sky-400">
                      {{ row.adjustments }} adjusted
                    </span>
                  </td>
                  <td class="px-3 py-2.5">
                    <StatusBadge :label="row.status" :tone="statusTone(row.status)" dot />
                  </td>
                  <td class="px-3 py-2.5 text-right tabular-nums">{{ formatNumber(row.items) }}</td>
                  <td class="px-3 py-2.5 whitespace-nowrap text-right tabular-nums">{{ money(row.deductions) }}</td>
                  <td class="px-3 py-2.5 whitespace-nowrap text-right tabular-nums text-muted-foreground">{{ money(row.earnings) }}</td>
                  <td class="px-3 py-2.5">
                    <button v-if="row.sheet" type="button" class="text-xs text-primary hover:underline" @click="router.visit(route('payroll.sheets.show', row.sheet!.id))">
                      {{ row.sheet.status }} →
                    </button>
                    <span v-else class="text-xs text-muted-foreground">not run</span>
                  </td>
                  <td class="px-5 py-2.5 text-right">
                    <div class="flex items-center justify-end gap-3">
                      <button type="button" class="text-xs font-medium text-muted-foreground hover:text-foreground" @click="router.visit(route('payroll.cutlists.show', row.id))">
                        Rows
                      </button>
                      <button type="button" class="whitespace-nowrap text-xs font-medium text-primary hover:underline" @click="router.visit(route('payroll.cutlists.sign-off', row.id))">
                        Sign off →
                      </button>
                    </div>
                  </td>
                </tr>
                <tr v-if="workspace.rows.length === 0">
                  <td colspan="7" class="px-5 py-10 text-center text-sm text-muted-foreground">
                    No cutlist exists yet. Generate one for a month on the right.
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <!-- Generate -->
        <section id="generate" class="rounded-xl border bg-card shadow-sm xl:col-span-2">
          <div class="border-b px-5 py-4">
            <h2 class="flex items-center gap-2 text-sm font-semibold"><RefreshCw class="size-4 text-muted-foreground" />Generate a month</h2>
            <p class="mt-0.5 text-xs text-muted-foreground">Reads HRM attendance and turns it into one proposal row per employee.</p>
          </div>

          <div class="space-y-3 p-5">
            <div>
              <Label class="mb-1.5 block text-xs">Pay month</Label>
              <div class="flex items-center gap-2">
                <Button size="sm" variant="outline" class="px-2" :disabled="gen.processing" @click="adjust(-1)">←</Button>
                <div class="flex-1 rounded-lg border bg-muted/30 px-3 py-2 text-center text-sm font-medium">{{ targetPeriod }}</div>
                <Button size="sm" variant="outline" class="px-2" :disabled="gen.processing" @click="adjust(1)">→</Button>
              </div>
              <div class="mt-2 grid grid-cols-2 gap-2">
                <select v-model.number="gen.pay_month" class="h-9 rounded-md border bg-background px-2 text-sm">
                  <option v-for="(name, i) in monthNames" :key="name" :value="i + 1">{{ name }}</option>
                </select>
                <select v-model.number="gen.pay_year" class="h-9 rounded-md border bg-background px-2 text-sm">
                  <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
                </select>
              </div>
            </div>

            <div v-if="existingForTarget" class="rounded-lg border border-warning/40 bg-warning/5 p-3 text-xs">
              <p class="flex items-center gap-1.5 font-medium text-warning">
                <AlertTriangle class="size-3.5" />
                {{ targetPeriod }} already has a {{ existingForTarget.status }} cutlist
              </p>
              <p class="mt-1 text-muted-foreground">
                <template v-if="existingForTarget.consumed">
                  Its salary sheet is already {{ existingForTarget.sheet?.status }}, so a rebuild would be refused — the
                  figures it produced are being paid.
                </template>
                <template v-else>
                  Rebuilding replaces all {{ formatNumber(existingForTarget.items) }} rows and erases any reviewer adjustment.
                </template>
              </p>
            </div>

            <label v-if="canGenerate" class="flex items-start gap-2 text-xs text-muted-foreground">
              <input v-model="gen.overwrite_adjustments" type="checkbox" class="mt-0.5 size-3.5 rounded border" />
              <span>Overwrite reviewer adjustments if any exist — without this, a rebuild is refused when a row has been changed.</span>
            </label>

            <div v-for="(message, key) in gen.errors" :key="key" class="rounded-lg border border-danger/30 bg-danger/5 p-2 text-xs text-danger">
              {{ message }}
            </div>

            <Button v-if="canGenerate" class="w-full" :disabled="gen.processing" @click="generate">
              <RefreshCw class="size-4" />{{ existingForTarget ? 'Rebuild from HRM' : 'Generate from HRM' }}
            </Button>
            <p v-else class="text-xs text-muted-foreground">Generating needs the payroll sheet permission.</p>

            <div class="rounded-lg bg-muted/40 p-3 text-[11px] leading-relaxed text-muted-foreground">
              <p class="font-medium text-foreground">What a row contains</p>
              <p class="mt-1">
                Unpaid leave and its deduction, late and early-out days with the punishment they attract, missing
                punches, and approved overtime or extra duty with its pay. Only the money columns reach a payslip —
                the day counts are evidence, not deductions.
              </p>
            </div>
          </div>
        </section>
      </div>

      <!-- Paginated list kept for older months -->
      <p class="text-[11px] text-muted-foreground">
        Showing the newest {{ workspace.rows.length }} cutlist(s) above; the table below pages through the full history
        ({{ cutlists.meta?.total ?? cutlists.data.length }} record(s)).
      </p>
    </div>
  </AdminLayout>
</template>
