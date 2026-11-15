<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import {
  AlertTriangle,
  ArrowLeft,
  ArrowRight,
  Banknote,
  CalendarDays,
  CheckCircle2,
  ClipboardCheck,
  FileStack,
  Landmark,
  ListChecks,
  Lock,
  ShieldCheck,
  Wallet,
  XCircle,
} from 'lucide-vue-next';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import StatusBadge from '@core/Components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import ModuleNav from './ModuleNav.vue';
import { confirmDialog } from '@core/Composables/useConfirm';
import { formatCurrency, formatDateTime, formatNumber } from '@core/Utils/format';

/**
 * The release desk.
 *
 * Release is the one action in the payroll module that changes what other people can do: the moment
 * it is done, 981 slips become payable and the accrual can be posted. The old screen put the button
 * in a toolbar next to a sentence asking the operator to "review the cutlist and slips before
 * release" — advice with nothing behind it, on a page that had no way to review either.
 *
 * This page is that review. It is not a gate: blocking checks stop the button, advisory ones only
 * colour it, because refusing to pay 981 people over a warning is its own kind of failure. What it
 * does demand is that the person releasing has been *told* — what the month costs, what happens on
 * the other side of the button, and which documents are already attached to it.
 */

interface Check {
  key: string;
  label: string;
  ok: boolean;
  detail: string;
  blocking: boolean;
}

const props = defineProps<{
  sheet: {
    id: number;
    pay_year: number;
    pay_month: number;
    status: string;
    employee_count: number;
    processed_count: number;
    failure_message: string | null;
  };
  context: {
    period: string;
    period_start: string;
    period_end: string;
    aggregate: { slips: number; gross: number; deductions: number; net: number; tax: number };
    payment: { paid: number; paid_amount: number; held: number; unpaid: number };
    cutlist: { id: number; status: string; items: number; approved_at: string | null } | null;
    journal: { id: number; journal_no: string; total_debit: number; status: string } | null;
    released_at: string | null;
    advice: { id: number; advice_no: string; status: string; total_amount: number } | null;
  };
  readiness: { checks: Check[]; blocking_failures: number; can_release_data: boolean; status: string };
  departments: { totals: { departments: number; slips: number; net: number; average_net: number } };
  canRelease: boolean;
  canPostAccrual: boolean;
  periodExclusions: Array<{ employee_id: string; employee_name: string | null; reason: string; created_by_name: string | null }>;
}>();

const money = (value: number | string | null | undefined): string => formatCurrency(Number(value ?? 0));

const agg = computed(() => props.context.aggregate);
const blocking = computed(() => props.readiness.checks.filter((check) => check.blocking && !check.ok));
const advisory = computed(() => props.readiness.checks.filter((check) => !check.blocking && !check.ok));
const passed = computed(() => props.readiness.checks.filter((check) => check.ok));

const isReady = computed(() => props.sheet.status === 'ready');
const isReleased = computed(() => props.sheet.status === 'released');
const blocked = computed(() => blocking.value.length > 0);

const statusTone = computed<'neutral' | 'info' | 'success' | 'warning' | 'danger'>(() => ({
  ready: 'warning',
  released: 'success',
  failed: 'danger',
  queued: 'info',
}[props.sheet.status] as 'neutral' | 'info' | 'success' | 'warning' | 'danger' ?? 'neutral'));

/**
 * What releasing will make possible, said before it happens.
 *
 * Not a permission table — the operator may not hold the finance permissions that gate the next
 * steps, and the sentence has to stay true for them too. So it says what the *month* can then do,
 * which is the same for everybody.
 */
const consequences = computed(() => [
  `${formatNumber(agg.value.slips)} payslip(s) become payable — individually, or as a bank advice file.`,
  `${money(agg.value.net)} of net pay stops being a draft and becomes a real liability.`,
  'The accrual can be posted to the ledger, which is the point at which the books carry the salary cost.',
  'Payslips can still be placed on hold afterwards; releasing does not pay anybody by itself.',
]);

const release = async (): Promise<void> => {
  const proceed = await confirmDialog({
    title: `Release ${props.context.period}?`,
    description: `${formatNumber(agg.value.slips)} payslip(s) worth ${money(agg.value.net)} become payable. Releasing is recorded against your user and cannot be undone from this screen — a released month has to be re-opened through Finance, which reverses the accrual.`,
    confirmLabel: 'Release the month',
    danger: false,
  });
  if (!proceed) return;
  router.post(route('payroll.sheets.release', props.sheet.id), {}, { preserveScroll: true });
};
</script>

<template>
  <AdminLayout
    :breadcrumbs="[
      { title: 'Payroll' },
      { title: 'Salary sheets', href: route('payroll.sheets.index') },
      { title: context.period, href: route('payroll.sheets.show', sheet.id) },
      { title: 'Release' },
    ]"
  >
    <Head :title="`Release — ${context.period}`" />

    <div class="space-y-4">
      <ModuleNav />

      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <button type="button" class="mb-1 flex items-center gap-1 text-[11px] text-muted-foreground hover:text-foreground" @click="router.visit(route('payroll.sheets.show', sheet.id))">
            <ArrowLeft class="size-3" />{{ context.period }}
          </button>
          <h1 class="flex items-center gap-2.5 text-xl font-bold tracking-tight">
            <span class="grid size-9 place-items-center rounded-xl bg-gradient-to-br from-primary to-primary/70 text-primary-foreground shadow-sm">
              <ListChecks class="size-5" />
            </span>
            Release desk
            <StatusBadge :label="sheet.status" :tone="statusTone" dot />
          </h1>
          <p class="mt-1 text-sm text-muted-foreground">
            Everything that has to be true before {{ context.period }} can be paid, in one place — and what turning it on will do.
          </p>
        </div>
        <div class="flex flex-wrap gap-2">
          <Button size="sm" variant="outline" @click="router.visit(route('payroll.sheets.show', sheet.id))">
            <FileStack class="size-4" />Open sheet
          </Button>
          <Button
            v-if="canRelease && isReady"
            size="sm"
            :disabled="blocked"
            @click="release"
          >
            <ClipboardCheck class="size-4" />Release {{ context.period }}
          </Button>
        </div>
      </div>

      <!-- Verdict -->
      <section
        class="rounded-xl border p-5 shadow-sm"
        :class="isReleased ? 'border-success/30 bg-success/5' : blocked ? 'border-danger/30 bg-danger/5' : advisory.length ? 'border-warning/30 bg-warning/5' : 'border-success/30 bg-success/5'"
      >
        <div class="flex flex-wrap items-start justify-between gap-4">
          <div class="flex items-start gap-3">
            <span
              class="grid size-11 shrink-0 place-items-center rounded-xl"
              :class="isReleased ? 'bg-success/15 text-success' : blocked ? 'bg-danger/15 text-danger' : advisory.length ? 'bg-warning/15 text-warning' : 'bg-success/15 text-success'"
            >
              <component :is="isReleased || (!blocked && !advisory.length) ? ShieldCheck : blocked ? XCircle : AlertTriangle" class="size-5" />
            </span>
            <div>
              <h2 class="text-sm font-semibold">
                <template v-if="isReleased">This month is released</template>
                <template v-else-if="blocked">{{ blocking.length }} blocking check(s) must pass first</template>
                <template v-else-if="advisory.length">{{ advisory.length === 1 ? 'One note worth reading' : `${advisory.length} notes worth reading` }} — releasing is allowed</template>
                <template v-else>Every check passed — ready to release</template>
              </h2>
              <p class="mt-1 max-w-2xl text-xs text-muted-foreground">
                <template v-if="isReleased">
                  Released{{ context.released_at ? ` ${formatDateTime(context.released_at)}` : '' }}. Slips can be paid individually or through a
                  bank advice; the accrual {{ context.journal ? `is posted as ${context.journal.journal_no}` : 'has not been posted yet' }}.
                </template>
                <template v-else-if="blocked">
                  {{ blocking[0]?.detail }}
                </template>
                <template v-else>
                  Nothing here refuses the release. {{ advisory.length ? 'The notes below are worth reading, not obeying.' : '' }}
                </template>
              </p>
            </div>
          </div>
          <dl class="flex flex-wrap gap-6 text-sm">
            <div>
              <dt class="text-[11px] uppercase tracking-wider text-muted-foreground">Payslips</dt>
              <dd class="font-mono font-semibold tabular-nums">{{ formatNumber(agg.slips) }}</dd>
            </div>
            <div>
              <dt class="text-[11px] uppercase tracking-wider text-muted-foreground">Net payable</dt>
              <dd class="font-mono font-semibold tabular-nums">{{ money(agg.net) }}</dd>
            </div>
            <div>
              <dt class="text-[11px] uppercase tracking-wider text-muted-foreground">Tax withheld</dt>
              <dd class="font-mono font-semibold tabular-nums">{{ money(agg.tax) }}</dd>
            </div>
          </dl>
        </div>
      </section>

      <div class="grid items-start gap-4 xl:grid-cols-5">
        <!-- Checks -->
        <section class="rounded-xl border bg-card shadow-sm xl:col-span-3">
          <div class="border-b px-5 py-4">
            <h2 class="flex items-center gap-2 text-sm font-semibold"><ListChecks class="size-4 text-muted-foreground" />Checklist</h2>
            <p class="mt-0.5 text-xs text-muted-foreground">
              <strong>Blocking</strong> checks refuse the release button. <strong>Advisory</strong> checks are reported and never stop a payment.
            </p>
          </div>
          <ul class="divide-y">
            <li v-for="check in readiness.checks" :key="check.key" class="flex items-start gap-3 px-5 py-3">
              <span
                class="mt-0.5 grid size-6 shrink-0 place-items-center rounded-full"
                :class="check.ok ? 'bg-success/15 text-success' : check.blocking ? 'bg-danger/15 text-danger' : 'bg-warning/15 text-warning'"
              >
                <component :is="check.ok ? CheckCircle2 : check.blocking ? XCircle : AlertTriangle" class="size-3.5" />
              </span>
              <div class="min-w-0 flex-1">
                <p class="text-[13px] font-medium leading-snug">{{ check.label }}</p>
                <p class="mt-0.5 text-[11px] leading-snug text-muted-foreground">{{ check.detail }}</p>
              </div>
              <span
                class="shrink-0 rounded px-1.5 py-px text-[10px] font-semibold uppercase tracking-wide"
                :class="check.blocking ? 'bg-danger/10 text-danger' : 'bg-muted text-muted-foreground'"
              >{{ check.blocking ? 'Blocking' : 'Advisory' }}</span>
            </li>
          </ul>
        </section>

        <!-- What release does -->
        <section class="rounded-xl border bg-card shadow-sm xl:col-span-2">
          <div class="border-b px-5 py-4">
            <h2 class="flex items-center gap-2 text-sm font-semibold"><Lock class="size-4 text-muted-foreground" />What releasing does</h2>
            <p class="mt-0.5 text-xs text-muted-foreground">Said before the button, not after it.</p>
          </div>
          <ol class="space-y-2.5 px-5 py-4">
            <li v-for="(line, i) in consequences" :key="i" class="flex items-start gap-2.5 text-[13px] leading-snug">
              <span class="mt-0.5 grid size-5 shrink-0 place-items-center rounded-full bg-primary/10 text-[10px] font-semibold text-primary">{{ i + 1 }}</span>
              <span>{{ line }}</span>
            </li>
          </ol>
          <div class="border-t bg-muted/30 px-5 py-3 text-[11px] text-muted-foreground">
            A released month cannot be quietly edited: Finance has to re-open it, which reverses the accrual first.
          </div>
        </section>
      </div>

      <!-- Attached documents -->
      <div class="grid items-start gap-4 xl:grid-cols-3">
        <section class="rounded-xl border bg-card p-4 shadow-sm">
          <h3 class="flex items-center gap-2 text-sm font-semibold"><FileStack class="size-4 text-muted-foreground" />Attendance cutlist</h3>
          <template v-if="context.cutlist">
            <p class="mt-1.5 text-xs text-muted-foreground">
              {{ formatNumber(context.cutlist.items) }} item(s) · <span class="capitalize">{{ context.cutlist.status }}</span>
              <template v-if="context.cutlist.approved_at"> · approved {{ formatDateTime(context.cutlist.approved_at) }}</template>
            </p>
            <Link :href="route('payroll.cutlists.show', context.cutlist.id)" class="mt-2 inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline">
              Open cutlist <ArrowRight class="size-3" />
            </Link>
          </template>
          <p v-else class="mt-1.5 text-xs text-muted-foreground">No cutlist is attached, so punishment and overtime have no approved source to point at.</p>
        </section>

        <section class="rounded-xl border bg-card p-4 shadow-sm">
          <h3 class="flex items-center gap-2 text-sm font-semibold"><Landmark class="size-4 text-muted-foreground" />Accrual</h3>
          <template v-if="context.journal">
            <p class="mt-1.5 text-xs text-muted-foreground">
              {{ context.journal.journal_no }} · {{ money(context.journal.total_debit) }} · {{ context.journal.status }}
            </p>
          </template>
          <template v-else>
            <p class="mt-1.5 text-xs text-muted-foreground">
              Not posted. The books do not carry {{ money(agg.net) }} of salary cost until the accrual is posted after release.
            </p>
            <Link :href="route('payroll.finance.sheets.show', sheet.id)" class="mt-2 inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline">
              Finance bridge <ArrowRight class="size-3" />
            </Link>
          </template>
        </section>

        <section class="rounded-xl border bg-card p-4 shadow-sm">
          <h3 class="flex items-center gap-2 text-sm font-semibold"><Banknote class="size-4 text-muted-foreground" />Payment file</h3>
          <template v-if="context.advice">
            <p class="mt-1.5 text-xs text-muted-foreground">
              {{ context.advice.advice_no }} · {{ money(context.advice.total_amount) }} · <span class="capitalize">{{ context.advice.status }}</span>
            </p>
          </template>
          <p v-else class="mt-1.5 text-xs text-muted-foreground">
            No bank advice yet. A BEFTN file can be generated once the month is released.
          </p>
        </section>
      </div>

      <!-- Shape of the month -->
      <section class="rounded-xl border bg-card p-4 shadow-sm">
        <h3 class="flex items-center gap-2 text-sm font-semibold"><Wallet class="size-4 text-muted-foreground" />The month in one line</h3>
        <div class="mt-2 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <div>
            <p class="text-[11px] uppercase tracking-wider text-muted-foreground">Cost centres</p>
            <p class="font-mono text-sm font-semibold tabular-nums">{{ formatNumber(departments.totals.departments) }}</p>
          </div>
          <div>
            <p class="text-[11px] uppercase tracking-wider text-muted-foreground">Average net per slip</p>
            <p class="font-mono text-sm font-semibold tabular-nums">{{ money(departments.totals.average_net) }}</p>
          </div>
          <div>
            <p class="text-[11px] uppercase tracking-wider text-muted-foreground">Documented exclusions</p>
            <p class="font-mono text-sm font-semibold tabular-nums">{{ formatNumber(periodExclusions.length) }}</p>
          </div>
          <div>
            <p class="text-[11px] uppercase tracking-wider text-muted-foreground">Period</p>
            <p class="text-sm font-semibold">{{ context.period_start }} → {{ context.period_end }}</p>
          </div>
        </div>
        <p class="mt-3 text-[11px] text-muted-foreground">
          {{ passed.length }} of {{ readiness.checks.length }} checks passed.
          <template v-if="periodExclusions.length">Exclusions: {{ periodExclusions.map((e) => e.employee_name || e.employee_id).join(', ') }}.</template>
        </p>
      </section>
    </div>
  </AdminLayout>
</template>
