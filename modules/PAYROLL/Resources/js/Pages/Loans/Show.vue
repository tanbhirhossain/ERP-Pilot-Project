<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import {
  ArrowLeft,
  BadgeCheck,
  Check,
  CheckCircle2,
  CircleAlert,
  Clock,
  FileStack,
  HandCoins,
  Printer,
  Wallet,
  X,
} from 'lucide-vue-next';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import { Button } from '@/components/ui/button';
import ModuleNav from './ModuleNav.vue';
import { confirmDialog, promptDialog } from '@core/Composables/useConfirm';
import { useToast } from '@core/Composables/useToast';
import { printDocument } from '@core/Composables/usePrint';
import { formatCurrency } from '@core/Utils/format';

/**
 * One advance, end to end.
 *
 * The schedule is the point of this page: the register can say "4 of 10 taken", but only this page
 * answers *which* four, when each was due, and when payroll actually took it. That distinction
 * matters because an installment can be taken late — the due date and the paid date are two facts.
 *
 * The approval trail is shown even when it is a single stage, because "who approved this and when"
 * is the question an auditor asks, and a blank area is not an answer.
 */

interface InstallmentRow {
  id: number;
  installment_number: number;
  due_date: string | null;
  amount: number;
  status: string;
  paid_at: string | null;
}

interface ApprovalRow {
  step_number: number;
  step_name: string;
  decision: string;
  actor: string | null;
  comments: string | null;
  decided_at: string | null;
}

interface Loan {
  id: number;
  employee_id: string;
  employee_name: string | null;
  designation: string | null;
  department: string | null;
  principal_amount: number;
  interest_rate: number;
  installment_count: number;
  installment_amount: number;
  first_due_date: string | null;
  status: string;
  purpose: string | null;
  requested_by: number | null;
  approved_by: number | null;
  approved_at: string | null;
  created_at: string | null;
  current_approval_step: number;
  approval_steps: { label: string; role: string }[] | null;
  recovered: number;
  remaining: number;
  progress_pct: number;
  installments: InstallmentRow[];
  approvals: ApprovalRow[];
}

const props = defineProps<{
  loan: Loan;
  currentUserId: number;
  canApproveLoans: boolean;
  canManage: boolean;
}>();

const toast = useToast();
const money = (value: number | null | undefined): string => formatCurrency(Number(value ?? 0));

const progress = computed(() => Math.max(0, Math.min(100, Number(props.loan.progress_pct ?? 0))));
const nextStep = computed(() => props.loan.approval_steps?.[props.loan.current_approval_step] ?? null);
const isPending = computed(() => props.loan.status === 'pending');
const isOwnRequest = computed(() => Number(props.loan.requested_by) === props.currentUserId);

const busy = ref(false);
const error = ref('');

async function approve(): Promise<void> {
  const proceed = await confirmDialog({
    title: 'Approve this advance?',
    description: nextStep.value
      ? `This completes the ${nextStep.value.label} stage (${nextStep.value.role}). ${props.loan.current_approval_step + 1 >= (props.loan.approval_steps?.length ?? 1) ? 'It is the final stage, so the repayment schedule will be created and taken from the next payroll month.' : 'It then moves to the next approval stage.'}`
      : 'The repayment schedule will be created and taken from the next payroll month.',
    confirmLabel: 'Approve advance',
    danger: false,
  });
  if (!proceed) return;
  busy.value = true;
  error.value = '';
  router.post(route('payroll.loans.approve', props.loan.id), {}, {
    preserveScroll: true,
    onError: (errors) => { error.value = Object.values(errors).join(' '); },
    onFinish: () => { busy.value = false; },
  });
}

async function reject(): Promise<void> {
  const asked = await promptDialog({
    title: 'Reject this advance',
    description: 'The reason is written to the audit trail and stays on the advance.',
    inputLabel: 'Rejection reason',
    inputPlaceholder: 'Why is this request being rejected?',
    inputType: 'textarea',
    confirmLabel: 'Reject request',
  });
  if (asked === null) return;
  if (asked.trim().length < 3) {
    toast.warning('A rejection reason needs at least three characters.');
    return;
  }
  busy.value = true;
  error.value = '';
  router.post(route('payroll.loans.reject', props.loan.id), { comments: asked.trim() }, {
    preserveScroll: true,
    onError: (errors) => { error.value = Object.values(errors).join(' '); },
    onFinish: () => { busy.value = false; },
  });
}

const print = (): void => {
  printDocument({
    title: `Advance statement — ${props.loan.employee_name || props.loan.employee_id}`,
    region: 'loan-statement',
    landscape: false,
    meta: [
      { label: 'Employee', value: props.loan.employee_name || props.loan.employee_id },
      { label: 'Advance', value: money(props.loan.principal_amount) },
      { label: 'Recovered', value: money(props.loan.recovered) },
      { label: 'Outstanding', value: money(props.loan.remaining) },
    ],
  });
};

const statusTone = (status: string): string => ({
  paid: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
  attached: 'bg-sky-500/10 text-sky-600 dark:text-sky-400',
  allocated: 'bg-violet-500/10 text-violet-600 dark:text-violet-400',
  scheduled: 'bg-muted text-muted-foreground',
}[status] ?? 'bg-muted text-muted-foreground');

const loanTone = (status: string): string => ({
  pending: 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
  active: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
  completed: 'bg-sky-500/10 text-sky-600 dark:text-sky-400',
  rejected: 'bg-rose-500/10 text-rose-600 dark:text-rose-400',
}[status] ?? 'bg-muted text-muted-foreground');
</script>

<template>
  <AdminLayout
    :breadcrumbs="[
      { title: 'Payroll' },
      { title: 'Staff advances', href: route('payroll.loans.index') },
      { title: 'Register', href: route('payroll.loans.register') },
      { title: loan.employee_name || loan.employee_id },
    ]"
  >
    <Head :title="`Advance — ${loan.employee_name || loan.employee_id}`" />

    <div class="space-y-4">
      <ModuleNav :can-manage="canManage" />

      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <button type="button" class="mb-1 flex items-center gap-1 text-[11px] text-muted-foreground hover:text-foreground" @click="router.visit(route('payroll.loans.register'))">
            <ArrowLeft class="size-3" />Register
          </button>
          <h1 class="flex items-center gap-2.5 text-xl font-bold tracking-tight">
            <span class="grid size-9 place-items-center rounded-xl bg-gradient-to-br from-primary to-primary/70 text-primary-foreground shadow-sm">
              <HandCoins class="size-5" />
            </span>
            {{ loan.employee_name || loan.employee_id }}
            <span class="rounded px-1.5 py-px text-[10px] font-semibold uppercase tracking-wide" :class="loanTone(loan.status)">{{ loan.status }}</span>
          </h1>
          <p class="mt-1 text-sm text-muted-foreground">
            {{ loan.employee_id }}<template v-if="loan.designation"> · {{ loan.designation }}</template><template v-if="loan.department"> · {{ loan.department }}</template>
            · advance #{{ loan.id }}, raised {{ loan.created_at }}
          </p>
        </div>
        <div class="flex flex-wrap gap-2">
          <Button size="sm" variant="outline" @click="print"><Printer class="size-4" />Print</Button>
          <template v-if="isPending && canApproveLoans">
            <Button size="sm" variant="outline" :disabled="busy || isOwnRequest" @click="reject"><X class="size-4" />Reject</Button>
            <Button size="sm" :disabled="busy || isOwnRequest" @click="approve">
              <Check class="size-4" />Approve {{ nextStep?.label || 'advance' }}
            </Button>
          </template>
        </div>
      </div>

      <p v-if="error" role="alert" class="rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive">{{ error }}</p>
      <p v-if="isPending && isOwnRequest" class="rounded-lg border bg-muted/40 px-3 py-2 text-sm text-muted-foreground">
        You raised this request, so you cannot decide it — a second approver has to.
      </p>

      <!-- Figures -->
      <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-xl border bg-card p-5 shadow-sm">
          <p class="text-xs font-medium uppercase tracking-wider text-muted-foreground">Advance</p>
          <p class="mt-1.5 text-2xl font-bold tabular-nums tracking-tight">{{ money(loan.principal_amount) }}</p>
          <p class="mt-1 text-xs text-muted-foreground">{{ loan.installment_count }} × {{ money(loan.installment_amount) }} · {{ loan.interest_rate }}% interest</p>
        </div>
        <div class="rounded-xl border bg-card p-5 shadow-sm">
          <p class="text-xs font-medium uppercase tracking-wider text-muted-foreground">Recovered</p>
          <p class="mt-1.5 text-2xl font-bold tabular-nums tracking-tight text-emerald-600 dark:text-emerald-400">{{ money(loan.recovered) }}</p>
          <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-muted">
            <div class="h-full rounded-full bg-emerald-500" :style="{ width: `${progress}%` }" />
          </div>
          <p class="mt-1 text-xs text-muted-foreground">{{ progress }}% of the advance is back</p>
        </div>
        <div class="rounded-xl border bg-card p-5 shadow-sm">
          <p class="text-xs font-medium uppercase tracking-wider text-muted-foreground">Outstanding</p>
          <p class="mt-1.5 text-2xl font-bold tabular-nums tracking-tight">{{ money(loan.remaining) }}</p>
          <p class="mt-1 text-xs text-muted-foreground">across {{ loan.installments.filter((r) => r.status !== 'paid').length }} unpaid installment(s)</p>
        </div>
        <div class="rounded-xl border bg-card p-5 shadow-sm">
          <p class="text-xs font-medium uppercase tracking-wider text-muted-foreground">First due</p>
          <p class="mt-1.5 text-2xl font-bold tabular-nums tracking-tight">{{ loan.first_due_date || '—' }}</p>
          <p class="mt-1 text-xs text-muted-foreground">{{ loan.approved_at ? `approved ${loan.approved_at}` : 'not approved yet' }}</p>
        </div>
      </div>

      <div class="grid items-start gap-4 xl:grid-cols-3">
        <!-- Schedule (print region) -->
        <section data-core-print="loan-statement" class="rounded-xl border bg-card shadow-sm xl:col-span-2">
          <div class="flex flex-wrap items-start justify-between gap-3 border-b px-5 py-4">
            <div>
              <h2 class="flex items-center gap-2 text-sm font-semibold"><FileStack class="size-4 text-muted-foreground" />Repayment schedule</h2>
              <p class="mt-0.5 text-xs text-muted-foreground">Due date and taken date are separate facts — an installment can be taken after its month.</p>
            </div>
            <span class="rounded-md bg-muted px-2.5 py-1 text-xs text-muted-foreground">{{ loan.installments.length }} installment(s)</span>
          </div>

          <div v-if="loan.installments.length === 0" class="px-5 py-14 text-center">
            <CircleAlert class="mx-auto size-7 text-muted-foreground/40" />
            <p class="mt-2 text-sm font-medium">No schedule yet</p>
            <p class="mt-1 text-xs text-muted-foreground">The schedule is created when the final approval stage completes.</p>
          </div>

          <div v-else class="overflow-x-auto">
            <table class="w-full text-[13px]">
              <thead>
                <tr class="border-b bg-muted/40 text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                  <th class="px-5 py-2.5 font-medium">#</th>
                  <th class="px-3 py-2.5 font-medium">Due</th>
                  <th class="px-3 py-2.5 text-right font-medium">Amount</th>
                  <th class="px-3 py-2.5 font-medium">State</th>
                  <th class="px-5 py-2.5 text-right font-medium">Taken</th>
                </tr>
              </thead>
              <tbody class="divide-y">
                <tr v-for="row in loan.installments" :key="row.id" class="transition hover:bg-muted/40">
                  <td class="px-5 py-2.5 tabular-nums text-muted-foreground">{{ row.installment_number }}</td>
                  <td class="px-3 py-2.5 tabular-nums">{{ row.due_date }}</td>
                  <td class="px-3 py-2.5 text-right tabular-nums font-medium">{{ money(row.amount) }}</td>
                  <td class="px-3 py-2.5">
                    <span class="rounded px-1.5 py-px text-[10px] font-semibold uppercase tracking-wide" :class="statusTone(row.status)">{{ row.status }}</span>
                  </td>
                  <td class="px-5 py-2.5 text-right tabular-nums text-muted-foreground">{{ row.paid_at || '—' }}</td>
                </tr>
              </tbody>
              <tfoot>
                <tr class="border-t bg-muted/30 text-[13px] font-semibold">
                  <td class="px-5 py-2.5" colspan="2">Total</td>
                  <td class="px-3 py-2.5 text-right tabular-nums">{{ money(loan.principal_amount) }}</td>
                  <td class="px-3 py-2.5" />
                  <td class="px-5 py-2.5 text-right tabular-nums">{{ money(loan.recovered) }} taken</td>
                </tr>
              </tfoot>
            </table>
          </div>
        </section>

        <!-- Terms + approvals -->
        <div class="space-y-4">
          <section class="rounded-xl border bg-card shadow-sm">
            <div class="border-b px-5 py-4">
              <h2 class="flex items-center gap-2 text-sm font-semibold"><Wallet class="size-4 text-muted-foreground" />Terms</h2>
            </div>
            <dl class="space-y-2.5 px-5 py-4 text-[13px]">
              <div class="flex items-start justify-between gap-3">
                <dt class="text-muted-foreground">Principal</dt>
                <dd class="tabular-nums font-medium">{{ money(loan.principal_amount) }}</dd>
              </div>
              <div class="flex items-start justify-between gap-3">
                <dt class="text-muted-foreground">Interest</dt>
                <dd class="tabular-nums font-medium">{{ loan.interest_rate }}% — strictly interest-free</dd>
              </div>
              <div class="flex items-start justify-between gap-3">
                <dt class="text-muted-foreground">Installments</dt>
                <dd class="tabular-nums font-medium">{{ loan.installment_count }} × {{ money(loan.installment_amount) }}</dd>
              </div>
              <div class="flex items-start justify-between gap-3">
                <dt class="text-muted-foreground">First due</dt>
                <dd class="tabular-nums font-medium">{{ loan.first_due_date || '—' }}</dd>
              </div>
              <div class="border-t pt-2.5">
                <dt class="text-muted-foreground">Purpose</dt>
                <dd class="mt-1 leading-snug">{{ loan.purpose || '—' }}</dd>
              </div>
            </dl>
          </section>

          <section class="rounded-xl border bg-card shadow-sm">
            <div class="border-b px-5 py-4">
              <h2 class="flex items-center gap-2 text-sm font-semibold"><BadgeCheck class="size-4 text-muted-foreground" />Approval trail</h2>
              <p class="mt-0.5 text-xs text-muted-foreground">
                {{ (loan.approval_steps?.length || 1) }} stage(s) configured; a different approver must complete each one.
              </p>
            </div>
            <ol class="divide-y">
              <li v-for="step in loan.approvals" :key="step.step_number" class="flex items-start gap-3 px-5 py-3">
                <span class="grid size-7 shrink-0 place-items-center rounded-lg" :class="step.decision === 'approved' ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' : 'bg-rose-500/10 text-rose-600 dark:text-rose-400'">
                  <component :is="step.decision === 'approved' ? CheckCircle2 : X" class="size-3.5" />
                </span>
                <span class="min-w-0 flex-1">
                  <span class="block text-[13px] font-medium leading-tight">{{ step.step_name }} · {{ step.decision }}</span>
                  <span class="block text-[11px] text-muted-foreground">{{ step.actor || 'unknown' }} · {{ step.decided_at }}</span>
                  <span v-if="step.comments" class="mt-1 block text-[11px] italic text-muted-foreground">“{{ step.comments }}”</span>
                </span>
              </li>

              <li v-if="isPending" class="flex items-start gap-3 px-5 py-3">
                <span class="grid size-7 shrink-0 place-items-center rounded-lg bg-amber-500/10 text-amber-600 dark:text-amber-400">
                  <Clock class="size-3.5" />
                </span>
                <span class="min-w-0 flex-1">
                  <span class="block text-[13px] font-medium leading-tight">{{ nextStep?.label || 'Approval' }} · waiting</span>
                  <span class="block text-[11px] text-muted-foreground">requires the {{ nextStep?.role || 'Administrator' }} role</span>
                </span>
              </li>

              <li v-if="loan.approvals.length === 0 && !isPending" class="px-5 py-6 text-center text-xs text-muted-foreground">
                No decision was recorded on this advance.
              </li>
            </ol>
          </section>
        </div>
      </div>
    </div>
  </AdminLayout>
</template>
