<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import {
  AlertTriangle,
  ArrowLeft,
  CheckCircle2,
  ClipboardCheck,
  FileStack,
  Lock,
  Pencil,
  RotateCcw,
  ShieldCheck,
  Table2,
  X,
  XCircle,
} from 'lucide-vue-next';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import StatusBadge from '@core/Components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import ModuleNav from './ModuleNav.vue';
import { confirmDialog } from '@core/Composables/useConfirm';
import { useToast } from '@core/Composables/useToast';
import { formatCurrency, formatDate, formatDateTime, formatNumber } from '@core/Utils/format';

/**
 * The sign-off desk.
 *
 * Approving a cutlist is the moment a proposal becomes the thing payroll is allowed to pay, and in
 * the module this replaces it was a button in a table row, one column away from "Review" — on a page
 * with no totals, no checklist and no record of what had been changed. Signing something you have
 * not been shown is not a review, it is a click.
 *
 * So: the checklist, what the sign-off covers, what has already been overridden, and — when it cannot
 * be given — why not.
 */

interface Check { key: string; label: string; ok: boolean; blocking: boolean; detail: string }

interface Adjustment {
  employee_id: string;
  employee_name: string;
  at: string | null;
  name: string | null;
  reason: string | null;
  changes: Array<{ field: string; from: number; to: number }>;
}

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
  context: {
    period: string;
    aggregate: { items: number; deductions: number; earnings: number; penalty: number; chargeable: number; flagged: number; adjustments: number };
    sheet: { id: number; status: string } | null;
    consumed: boolean;
    review_required: boolean;
    editable: boolean;
  };
  readiness: { checks: Check[]; blocking_failures: number; status: string };
  adjustments: Adjustment[];
  canReview: boolean;
}>();

const toast = useToast();
const money = (value: number | string | null | undefined): string => formatCurrency(Number(value ?? 0));

const agg = computed(() => props.context.aggregate);
const blocking = computed(() => props.readiness.checks.filter((c) => c.blocking && !c.ok));
const advisory = computed(() => props.readiness.checks.filter((c) => !c.blocking && !c.ok));
const isApproved = computed(() => props.cutlist.status === 'approved');
const canApprove = computed(() => props.canReview && props.cutlist.status === 'generated' && blocking.value.length === 0);

const note = ref('');
const form = useForm({ note: '' });
const mode = ref<'approve' | 'reopen' | null>(null);

const start = (which: 'approve' | 'reopen'): void => {
  mode.value = which;
  note.value = '';
  form.clearErrors();
};

const submit = async (): Promise<void> => {
  if (!mode.value) return;
  form.note = note.value;
  const approving = mode.value === 'approve';

  const proceed = await confirmDialog({
    title: approving ? `Sign off ${props.context.period}?` : `Reopen ${props.context.period}?`,
    description: approving
      ? `This states that you reviewed ${formatNumber(agg.value.items)} rows and that what payroll will deduct and pay is what you saw — ${money(agg.value.deductions)} in deductions and ${money(agg.value.earnings)} in extra pay. It is recorded against your user.`
      : 'Adjustments unlock again and the previous sign-off is withdrawn. The reason is kept.',
    confirmLabel: approving ? 'Sign off' : 'Reopen',
    danger: !approving,
  });
  if (!proceed) return;

  form.post(route(approving ? 'payroll.cutlists.approve' : 'payroll.cutlists.reopen', props.cutlist.id), {
    preserveScroll: true,
    onSuccess: () => {
      mode.value = null;
      toast.success(approving ? 'Cutlist signed off for payroll.' : 'Cutlist reopened for review.');
    },
  });
};

const tone = (check: Check): string => check.ok ? 'ok' : check.blocking ? 'error' : 'warn';
</script>

<template>
  <AdminLayout
    :breadcrumbs="[
      { title: 'Payroll' },
      { title: 'Salary cutlists', href: route('payroll.cutlists.index') },
      { title: context.period, href: route('payroll.cutlists.show', cutlist.id) },
      { title: 'Sign off' },
    ]"
  >
    <Head :title="`Sign off — ${context.period}`" />

    <div class="space-y-4">
      <ModuleNav />

      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 class="flex items-center gap-2.5 text-xl font-bold tracking-tight">
            <span class="grid size-9 place-items-center rounded-xl bg-gradient-to-br from-primary to-primary/70 text-primary-foreground shadow-sm">
              <ClipboardCheck class="size-5" />
            </span>
            Sign-off desk
            <StatusBadge :label="cutlist.status" :tone="isApproved ? 'success' : cutlist.status === 'generated' ? 'warning' : 'neutral'" dot />
          </h1>
          <p class="mt-1 text-sm text-muted-foreground">
            What a reviewer is signing for in {{ context.period }} — and whether it can be signed at all.
          </p>
        </div>
        <div class="flex flex-wrap gap-2">
          <Link :href="route('payroll.cutlists.show', cutlist.id)">
            <Button size="sm" variant="outline"><Table2 class="size-4" />Rows</Button>
          </Link>
          <Button v-if="canReview && cutlist.status === 'generated'" size="sm" :disabled="!canApprove" :title="blocking.length ? blocking[0]?.detail : undefined" @click="start('approve')">
            <ShieldCheck class="size-4" />Sign off
          </Button>
          <Button v-if="canReview && isApproved" size="sm" variant="outline" @click="start('reopen')">
            <RotateCcw class="size-4" />Reopen
          </Button>
        </div>
      </div>

      <!-- Verdict -->
      <section
        class="rounded-xl border p-5 shadow-sm"
        :class="isApproved ? 'border-success/30 bg-success/5' : blocking.length ? 'border-danger/30 bg-danger/5' : advisory.length ? 'border-warning/30 bg-warning/5' : 'border-success/30 bg-success/5'"
      >
        <div class="flex flex-wrap items-start justify-between gap-4">
          <div class="flex items-start gap-3">
            <span
              class="grid size-11 shrink-0 place-items-center rounded-xl"
              :class="isApproved ? 'bg-success/15 text-success' : blocking.length ? 'bg-danger/15 text-danger' : advisory.length ? 'bg-warning/15 text-warning' : 'bg-success/15 text-success'"
            >
              <component :is="isApproved || (!blocking.length && !advisory.length) ? ShieldCheck : blocking.length ? XCircle : AlertTriangle" class="size-5" />
            </span>
            <div>
              <h2 class="text-sm font-semibold">
                <template v-if="isApproved">Signed off{{ cutlist.approved_at ? ` on ${formatDate(cutlist.approved_at)}` : '' }}</template>
                <template v-else-if="blocking.length">{{ blocking.length }} check(s) must pass before this can be signed</template>
                <template v-else-if="!canReview">You can read this month, but not sign for it</template>
                <template v-else-if="advisory.length">Ready to sign, with {{ advisory.length }} note(s) worth reading</template>
                <template v-else>Everything checks out — ready to sign</template>
              </h2>
              <p class="mt-1 max-w-2xl text-xs text-muted-foreground">
                <template v-if="isApproved">
                  {{ cutlist.approval_note ? `Note: ${cutlist.approval_note}` : 'No note was recorded with the sign-off.' }}
                </template>
                <template v-else-if="blocking.length">{{ blocking[0]?.detail }}</template>
                <template v-else-if="!canReview">
                  Signing needs the cutlist-review or payroll-sheet permission. The figures below are still yours to check.
                </template>
                <template v-else>
                  {{ context.review_required
                    ? 'Payroll settings require this sign-off before the salary sheet can be queued, so queueing is blocked until it is given.'
                    : 'Payroll settings do not require this sign-off, so the sheet can be queued without it.' }}
                </template>
              </p>
            </div>
          </div>
          <dl class="flex flex-wrap gap-6 text-sm">
            <div>
              <dt class="text-[11px] uppercase tracking-wider text-muted-foreground">Rows</dt>
              <dd class="font-mono font-semibold tabular-nums">{{ formatNumber(agg.items) }}</dd>
            </div>
            <div>
              <dt class="text-[11px] uppercase tracking-wider text-muted-foreground">Deductions</dt>
              <dd class="font-mono font-semibold tabular-nums">{{ money(agg.deductions) }}</dd>
            </div>
            <div>
              <dt class="text-[11px] uppercase tracking-wider text-muted-foreground">Extra pay</dt>
              <dd class="font-mono font-semibold tabular-nums">{{ money(agg.earnings) }}</dd>
            </div>
          </dl>
        </div>
      </section>

      <div class="grid items-start gap-4 xl:grid-cols-5">
        <!-- Checklist -->
        <section class="rounded-xl border bg-card shadow-sm xl:col-span-3">
          <div class="border-b px-5 py-4">
            <h2 class="flex items-center gap-2 text-sm font-semibold"><CheckCircle2 class="size-4 text-muted-foreground" />Checklist</h2>
            <p class="mt-0.5 text-xs text-muted-foreground">
              <strong>Blocking</strong> checks refuse the sign-off. <strong>Advisory</strong> checks are reported and never stop it.
            </p>
          </div>
          <ul class="divide-y">
            <li v-for="check in readiness.checks" :key="check.key" class="flex items-start gap-3 px-5 py-3">
              <span
                class="mt-0.5 grid size-6 shrink-0 place-items-center rounded-full"
                :class="tone(check) === 'ok' ? 'bg-success/15 text-success' : tone(check) === 'error' ? 'bg-danger/15 text-danger' : 'bg-warning/15 text-warning'"
              >
                <component :is="check.ok ? CheckCircle2 : check.blocking ? XCircle : AlertTriangle" class="size-3.5" />
              </span>
              <div class="min-w-0 flex-1">
                <p class="text-[13px] font-medium leading-snug">{{ check.label }}</p>
                <p class="mt-0.5 text-[11px] leading-snug text-muted-foreground">{{ check.detail }}</p>
              </div>
              <span class="shrink-0 rounded px-1.5 py-px text-[10px] font-semibold uppercase tracking-wide" :class="check.blocking ? 'bg-danger/10 text-danger' : 'bg-muted text-muted-foreground'">
                {{ check.blocking ? 'Blocking' : 'Advisory' }}
              </span>
            </li>
          </ul>
        </section>

        <!-- What the sign-off covers -->
        <section class="rounded-xl border bg-card shadow-sm xl:col-span-2">
          <div class="border-b px-5 py-4">
            <h2 class="flex items-center gap-2 text-sm font-semibold"><Lock class="size-4 text-muted-foreground" />What this signature covers</h2>
            <p class="mt-0.5 text-xs text-muted-foreground">Said before the button, not after it.</p>
          </div>
          <ol class="space-y-2.5 px-5 py-4 text-[13px] leading-snug">
            <li class="flex items-start gap-2.5">
              <span class="mt-0.5 grid size-5 shrink-0 place-items-center rounded-full bg-primary/10 text-[10px] font-semibold text-primary">1</span>
              <span>{{ formatNumber(agg.items) }} attendance-derived rows were reviewed — unpaid leave, punishment days, missing punches, approved overtime and extra duty.</span>
            </li>
            <li class="flex items-start gap-2.5">
              <span class="mt-0.5 grid size-5 shrink-0 place-items-center rounded-full bg-primary/10 text-[10px] font-semibold text-primary">2</span>
              <span>{{ money(agg.deductions) }} of deductions and {{ money(agg.earnings) }} of extra pay are what the salary sheet will consume for this month.</span>
            </li>
            <li class="flex items-start gap-2.5">
              <span class="mt-0.5 grid size-5 shrink-0 place-items-center rounded-full bg-primary/10 text-[10px] font-semibold text-primary">3</span>
              <span>No employee is charged a punishment day their HRM policy does not allow.</span>
            </li>
            <li class="flex items-start gap-2.5">
              <span class="mt-0.5 grid size-5 shrink-0 place-items-center rounded-full bg-primary/10 text-[10px] font-semibold text-primary">4</span>
              <span>The rows freeze: adjustments close, and only a payroll re-open can change what is later paid.</span>
            </li>
          </ol>
          <div class="border-t bg-muted/30 px-5 py-3 text-[11px] text-muted-foreground">
            Signing does not pay anybody. It makes the proposal final so the salary sheet may run.
          </div>
        </section>
      </div>

      <!-- Adjustment trail -->
      <section class="rounded-xl border bg-card shadow-sm">
        <div class="flex flex-wrap items-start justify-between gap-3 border-b px-5 py-4">
          <div>
            <h2 class="flex items-center gap-2 text-sm font-semibold"><Pencil class="size-4 text-muted-foreground" />What a reviewer changed</h2>
            <p class="mt-0.5 text-xs text-muted-foreground">
              Every override on this month, with its reason. A sign-off over silent edits is not a review.
            </p>
          </div>
          <span class="rounded-full bg-muted px-2.5 py-1 text-[11px] font-medium text-muted-foreground">{{ context.aggregate.adjustments }} change(s)</span>
        </div>

        <div v-if="adjustments.length === 0" class="px-5 py-8 text-center">
          <CheckCircle2 class="mx-auto size-7 text-success/50" />
          <p class="mt-2 text-sm font-medium">Nothing was overridden</p>
          <p class="mt-1 text-xs text-muted-foreground">The proposal is exactly what HRM attendance produced.</p>
        </div>

        <ul v-else class="divide-y">
          <li v-for="(entry, i) in adjustments" :key="i" class="px-5 py-3">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
              <p class="text-[13px] font-medium">
                {{ entry.employee_name }}
                <span class="font-mono text-[11px] text-muted-foreground">{{ entry.employee_id }}</span>
              </p>
              <p class="text-[11px] text-muted-foreground">{{ entry.name || 'system' }} · {{ formatDateTime(entry.at) }}</p>
            </div>
            <p class="mt-0.5 text-xs text-muted-foreground">{{ entry.reason }}</p>
            <p class="mt-1 font-mono text-[11px]">
              <span v-for="(change, j) in entry.changes" :key="j" class="mr-3 inline-block">
                {{ change.field }}: {{ money(change.from) }} → <strong>{{ money(change.to) }}</strong>
              </span>
            </p>
          </li>
        </ul>
      </section>

      <!-- Approved / reopened summary -->
      <section v-if="isApproved || context.sheet" class="grid gap-4 sm:grid-cols-2">
        <div v-if="isApproved" class="rounded-xl border border-success/30 bg-success/5 p-4">
          <h3 class="flex items-center gap-2 text-sm font-semibold text-success"><ShieldCheck class="size-4" />Signed off</h3>
          <p class="mt-1 text-xs text-muted-foreground">
            {{ cutlist.approved_at ? formatDateTime(cutlist.approved_at) : '' }}
            {{ cutlist.approval_note ? `— ${cutlist.approval_note}` : '' }}
          </p>
        </div>
        <div v-if="context.sheet" class="rounded-xl border bg-card p-4">
          <h3 class="flex items-center gap-2 text-sm font-semibold"><FileStack class="size-4 text-muted-foreground" />Consumed by a salary sheet</h3>
          <p class="mt-1 text-xs text-muted-foreground">Sheet #{{ context.sheet.id }} is {{ context.sheet.status }}.</p>
          <Link :href="route('payroll.sheets.show', context.sheet.id)" class="mt-1 inline-block text-xs font-medium text-primary hover:underline">Open the sheet →</Link>
        </div>
      </section>
    </div>

    <!-- Sign-off dialog -->
    <div v-if="mode" class="fixed inset-0 z-50 grid place-items-center bg-black/40 p-4" @click.self="mode = null">
      <div class="w-full max-w-lg rounded-xl border bg-card p-5 shadow-xl">
        <div class="flex items-start justify-between">
          <h3 class="text-sm font-semibold">{{ mode === 'approve' ? `Sign off ${context.period}` : `Reopen ${context.period}` }}</h3>
          <button type="button" class="text-muted-foreground hover:text-foreground" @click="mode = null"><X class="size-4" /></button>
        </div>

        <p class="mt-2 rounded-lg bg-muted/40 p-2.5 text-[11px] leading-relaxed text-muted-foreground">
          <template v-if="mode === 'approve'">
            You are stating that {{ formatNumber(agg.items) }} rows were reviewed and that {{ money(agg.deductions) }} of
            deductions plus {{ money(agg.earnings) }} of extra pay are correct. The note is stored on the cutlist and
            shown to anyone who opens it later.
          </template>
          <template v-else>
            The sign-off is withdrawn, adjustments unlock, and the reason is kept. If a salary sheet has already
            been computed from these rows, payroll has to be re-opened through Finance instead.
          </template>
        </p>

        <div class="mt-3">
          <Label class="text-xs">{{ mode === 'approve' ? 'Review note (kept on the cutlist)' : 'Why is the sign-off being withdrawn?' }}</Label>
          <Textarea v-model="note" class="mt-1" rows="3" maxlength="1000" placeholder="e.g. Verified all 981 rows against the attendance register; three adjustments applied per notes." />
          <p v-if="form.errors.note" class="mt-1 text-xs text-danger">{{ form.errors.note }}</p>
        </div>

        <div class="mt-4 flex justify-end gap-2">
          <Button size="sm" variant="outline" @click="mode = null">Cancel</Button>
          <Button size="sm" :variant="mode === 'reopen' ? 'outline' : 'default'" :disabled="form.processing || note.trim().length < 3" @click="submit">
            {{ mode === 'approve' ? 'Sign off' : 'Reopen for review' }}
          </Button>
        </div>
      </div>
    </div>
  </AdminLayout>
</template>
