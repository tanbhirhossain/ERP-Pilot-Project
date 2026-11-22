<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { FileDown, ArrowLeft, BadgeCheck, Banknote, Printer, XCircle } from 'lucide-vue-next';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useToast } from '@core/Composables/useToast';
import { confirmDialog, promptDialog } from '@core/Composables/useConfirm';
import { printDocument } from '@core/Composables/usePrint';
import { useReportPdf } from '@core/Composables/useReportPdf';
import { formatCurrency } from '@core/Utils/format';

/**
 * A member's passbook.
 *
 * Modelled on the document a provident-fund member is actually handed: a header with their identity
 * and the account's terms, a money table with credit and debit columns and a running balance, then a
 * history of what has been asked for and paid out. Everything is one page at ledger density — the
 * previous version spread four stat cards over a hero and pushed the ledger below the fold, which is
 * backwards for a document whose only job is to list entries.
 */

interface StatementLine {
  id: number;
  entry_date: string | null;
  contribution_month: string | null;
  entry_type: string;
  status: string;
  direction: 'credit' | 'debit';
  amount: number;
  signed_amount: number;
  balance: number;
  description: string | null;
  reference_type: string | null;
}

interface WithdrawalRow {
  id: number;
  type: string;
  requested_amount: string | number;
  approved_amount: string | number | null;
  reason: string;
  status: string;
  created_at: string | null;
  decided_at: string | null;
  paid_at: string | null;
  decision_note: string | null;
}

const props = defineProps<{
  account: {
    id: number;
    employee_id: string;
    employee_name: string | null;
    employee_balance: string | number;
    employer_balance: string | number;
    total_balance: number;
    status: string;
    eligible_from: string | null;
    policy?: { name: string; employee_contribution_percent: string | number; employer_contribution_percent: string | number; contribution_base: string } | null;
    employee?: { full_name?: string; designation?: string; joining_date?: string; status?: string } | null;
  };
  statement: { opening_balance: number; closing_balance: number; lines: StatementLine[] };
  withdrawals: WithdrawalRow[];
  canManage: boolean;
}>();

const toast = useToast();
const money = (value: number | string | null | undefined): string => formatCurrency(Number(value ?? 0));

const monthLabel = (period: string | null): string => {
  if (!period) return '—';
  const [year, month] = period.split('-').map(Number);
  if (!year || !month) return period;
  return new Date(year, month - 1, 1).toLocaleDateString('en-GB', { month: 'short', year: 'numeric' });
};

const plainDate = (value: string | null | undefined): string => {
  if (!value) return '—';
  const [datePart] = String(value).split('T');
  const [year, month, day] = datePart.split('-').map(Number);
  if (!year || !month || !day) return datePart;
  return new Date(year, month - 1, day).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
};

const typeLabel = (type: string): string => ({
  employee_contribution: 'Employee contribution',
  employer_contribution: 'Employer contribution',
  withdrawal: 'Withdrawal',
  settlement: 'Final settlement',
  adjustment: 'Adjustment',
}[type] ?? type.replaceAll('_', ' '));

/* --------------------------- statement window --------------------------- */

const range = ref({ from: '', to: '' });

function applyRange(): void {
  router.get(route('payroll.pf.show', props.account.id), {
    from: range.value.from || undefined,
    to: range.value.to || undefined,
  }, { preserveScroll: true, preserveState: true, replace: true });
}

function clearRange(): void {
  range.value = { from: '', to: '' };
  router.get(route('payroll.pf.show', props.account.id), {}, { preserveScroll: true, preserveState: true, replace: true });
}

/** The invariant this page exists to prove: the ledger's closing figure equals the cached balance. */
const drift = computed(() => {
  const variance = Number(props.statement.closing_balance ?? 0) - Number(props.account.total_balance ?? 0);
  return Math.abs(variance) > 0.009 ? variance : 0;
});

const byType = computed(() => {
  const totals: Record<string, number> = {};
  for (const line of props.statement.lines) {
    totals[line.entry_type] = (totals[line.entry_type] ?? 0) + Number(line.amount);
  }
  return totals;
});

const hasRange = computed(() => Boolean(range.value.from || range.value.to));

/* ------------------------------ withdrawal ------------------------------ */

const requestForm = useForm({ pf_account_id: props.account.id, type: 'partial', requested_amount: '', reason: '' });

const ceilingFor = (type: string): number =>
  type === 'final_settlement' ? Number(props.account.total_balance) : Number(props.account.employee_balance);

async function requestWithdrawal(): Promise<void> {
  const amount = Number(requestForm.requested_amount);
  const ceiling = ceilingFor(requestForm.type);

  if (!(amount > 0)) { toast.warning('Enter an amount greater than zero.'); return; }
  if (amount > ceiling) {
    toast.warning(`${requestForm.type === 'final_settlement' ? 'A final settlement' : 'A partial withdrawal'} may not exceed ${money(ceiling)}.`);
    return;
  }
  if (requestForm.reason.trim().length < 5) {
    toast.warning('Give a reason of at least five characters — it is written to the audit trail.');
    return;
  }

  const proceed = await confirmDialog({
    title: requestForm.type === 'final_settlement' ? 'Request a final settlement' : 'Request a withdrawal',
    description: `${money(amount)} from ${props.account.employee_name || props.account.employee_id}'s account. This creates a request; money moves only when it is approved and paid, which posts the ledger entry.`,
    confirmLabel: 'Create request',
    danger: false,
  });
  if (!proceed) return;

  requestForm.post(route('payroll.pf.withdrawals.store'), {
    preserveScroll: true,
    onSuccess: () => requestForm.reset('requested_amount', 'reason'),
    onError: (errors) => toast.error(Object.values(errors).join(' ')),
  });
}

const deciding = ref<number | null>(null);

async function decide(id: number, decision: 'approve' | 'reject' | 'pay'): Promise<void> {
  if (decision === 'reject') {
    const asked = await promptDialog({
      title: 'Reject withdrawal request',
      description: 'The member is entitled to a reason, and it is written to the audit trail.',
      inputLabel: 'Rejection reason',
      inputPlaceholder: 'Why is this request being refused?',
      inputType: 'textarea',
      confirmLabel: 'Reject request',
    });
    if (asked === null) return;
    if (asked.trim().length < 3) { toast.warning('A rejection reason needs at least three characters.'); return; }

    deciding.value = id;
    router.post(route('payroll.pf.withdrawals.decide'), { withdrawal_id: id, decision, note: asked.trim() }, {
      preserveScroll: true,
      onError: (errors) => toast.error(Object.values(errors).join(' ')),
      onFinish: () => { deciding.value = null; },
    });
    return;
  }

  const copy = decision === 'approve'
    ? { title: 'Approve request', text: 'Approval authorises the payout but moves no money — the ledger is only touched when it is marked paid.', label: 'Approve' }
    : { title: 'Mark paid', text: 'Posts the debit to this member\'s ledger and reduces their balance.', label: 'Mark paid' };

  const proceed = await confirmDialog({ title: copy.title, description: copy.text, confirmLabel: copy.label, danger: decision === 'pay' });
  if (!proceed) return;

  deciding.value = id;
  router.post(route('payroll.pf.withdrawals.decide'), { withdrawal_id: id, decision }, {
    preserveScroll: true,
    onError: (errors) => toast.error(Object.values(errors).join(' ')),
    onFinish: () => { deciding.value = null; },
  });
}

/* --------------------------------- print -------------------------------- */

const { downloadPdf } = useReportPdf();
const pdfPayload = computed(() => ({
    title: `PF statement — ${props.account.employee_name || props.account.employee_id}`,
    permission: 'payroll.pf.view',
    orientation: 'portrait',
    meta: {
        'Member': props.account.employee_name || props.account.employee_id,
        'Employee ID': props.account.employee_id,
        'Status': props.account.status,
        'Opening balance': money(props.statement.opening_balance),
        'Closing balance': money(props.statement.closing_balance),
    },
    headings: ['Date', 'Month', 'Type', 'Direction', 'Amount', 'Balance', 'Description'],
    rows: props.statement.lines.map((l: any) => [l.entry_date ?? '—', l.contribution_month ?? '—', l.entry_type, l.direction, l.amount, l.balance, l.description ?? '—']),
    footer: 'PF statement · Payroll',
}));

function print(): void {
  printDocument();
}
</script>

<template>
  <AdminLayout :breadcrumbs="[{ title: 'Payroll' }, { title: 'Provident Fund', href: route('payroll.pf.dashboard') }, { title: 'Members', href: route('payroll.pf.members') }, { title: account.employee_name || account.employee_id }]">
    <Head :title="`PF statement — ${account.employee_name || account.employee_id}`" />

    <PageHeader
      :title="account.employee_name || account.employee_id"
      :icon="Banknote"
      :description="`${account.employee_id}${account.employee?.designation ? ' · ' + account.employee.designation : ''}${account.policy ? ' · ' + account.policy.name : ''}`"
      :stats="[
        { label: 'Employee share', value: money(account.employee_balance) },
        { label: 'Employer share', value: money(account.employer_balance) },
        { label: 'Total balance', value: money(account.total_balance) },
        { label: 'Contribution', value: account.policy ? account.policy.employee_contribution_percent + '% / ' + account.policy.employer_contribution_percent + '%' : '—' },
      ]"
    >
      <template #badge>
        <span class="text-[10px] font-semibold uppercase tracking-[0.12em]" :class="{
          'text-emerald-700 dark:text-emerald-400': account.status === 'active',
          'text-amber-700 dark:text-amber-400': account.status === 'suspended',
          'text-muted-foreground': account.status === 'closed',
        }">{{ account.status }} account</span>
      </template>
      <template #actions>
        <Button size="sm" variant="ghost" @click="router.visit(route('payroll.pf.members'))"><ArrowLeft class="size-3" />Member register</Button>
        <Button size="sm" class="rounded-full" @click="downloadPdf(pdfPayload)"><FileDown class="size-3.5" />Download PDF</Button>
        <Button size="sm" variant="outline" @click="print"><Printer class="size-3.5" />Print statement</Button>
      </template>
    </PageHeader>

    <div v-if="drift" role="alert" class="mt-2 rounded-md border border-rose-500/30 bg-rose-500/[0.05] px-3 py-2 text-[12px] text-rose-700 dark:text-rose-400">
      <strong>Statement does not reconcile.</strong>
      Ledger closing balance is {{ money(statement.closing_balance) }} against a cached balance of
      {{ money(account.total_balance) }} — a variance of {{ money(drift) }}. Rebuild the account before relying on this figure.
    </div>

    <div class="mt-5 grid gap-5 xl:grid-cols-[minmax(0,1fr)_300px]">
      <div class="min-w-0 space-y-5">
        <!-- ===================== ledger ===================== -->
        <section class="rounded-md border bg-card" data-core-print>
          <div class="flex flex-wrap items-end justify-between gap-3 border-b px-4 py-3">
            <div>
              <h2 class="text-[13px] font-semibold">Statement of account</h2>
              <p class="mt-0.5 text-[11px] text-muted-foreground">Newest first, with the balance each entry produced.</p>
            </div>
            <form class="flex flex-wrap items-end gap-1.5" @submit.prevent="applyRange">
              <div class="space-y-1">
                <Label class="text-[10px] uppercase tracking-wider text-muted-foreground">From</Label>
                <Input v-model="range.from" type="date" class="h-7 w-[8.5rem] text-[11.5px]" />
              </div>
              <div class="space-y-1">
                <Label class="text-[10px] uppercase tracking-wider text-muted-foreground">To</Label>
                <Input v-model="range.to" type="date" class="h-7 w-[8.5rem] text-[11.5px]" />
              </div>
              <Button size="sm" type="submit" class="h-7 px-2 text-[11px]">Apply</Button>
              <Button v-if="hasRange" size="sm" type="button" variant="ghost" class="h-7 px-2 text-[11px]" @click="clearRange">Clear</Button>
            </form>
          </div>

          <div v-if="statement.lines.length === 0" class="px-4 py-14 text-center">
            <p class="text-[13px] font-medium">No entries in this account</p>
            <p class="mx-auto mt-1 max-w-sm text-[12px] text-muted-foreground">
              Contributions appear here once the payroll month that withheld them is accrued. Nothing has been recognised yet.
            </p>
          </div>

          <div v-else>
            <table class="w-full text-[12.5px]">
              <thead>
                <tr class="border-b bg-muted/40 text-[10px] uppercase tracking-[0.1em] text-muted-foreground">
                  <th class="px-4 py-2 text-left font-semibold">Date</th>
                  <th class="px-2 py-2 text-left font-semibold">Particulars</th>
                  <th class="px-2 py-2 text-left font-semibold">Period</th>
                  <th class="px-2 py-2 text-left font-semibold">Basis</th>
                  <th class="px-2 py-2 text-right font-semibold">Credit</th>
                  <th class="px-2 py-2 text-right font-semibold">Debit</th>
                  <th class="px-4 py-2 text-right font-semibold">Balance</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-border/70">
                <tr class="text-muted-foreground">
                  <td class="px-4 py-1.5" colspan="4">Opening balance</td>
                  <td class="px-2 py-1.5"></td>
                  <td class="px-2 py-1.5"></td>
                  <td class="px-4 py-1.5 text-right tabular-nums">{{ money(statement.opening_balance) }}</td>
                </tr>
                <tr v-for="line in statement.lines" :key="line.id" class="hover:bg-muted/30">
                  <td class="whitespace-nowrap px-4 py-2 tabular-nums">{{ plainDate(line.entry_date) }}</td>
                  <td class="px-2 py-2">
                    <span class="font-medium">{{ typeLabel(line.entry_type) }}</span>
                    <span v-if="line.description" class="block text-[11px] leading-tight text-muted-foreground">{{ line.description }}</span>
                  </td>
                  <td class="whitespace-nowrap px-2 py-2 text-muted-foreground">{{ monthLabel(line.contribution_month) }}</td>
                  <td class="px-2 py-2">
                    <span class="text-[10px] font-semibold uppercase tracking-wide" :class="{
                      'text-amber-700 dark:text-amber-400': line.status === 'accrued',
                      'text-emerald-700 dark:text-emerald-400': line.status === 'settled' || line.status === 'paid',
                    }">{{ line.status }}</span>
                  </td>
                  <td class="whitespace-nowrap px-2 py-2 text-right tabular-nums">
                    <span v-if="line.direction === 'credit'" class="text-emerald-700 dark:text-emerald-400">{{ money(line.amount) }}</span>
                  </td>
                  <td class="whitespace-nowrap px-2 py-2 text-right tabular-nums">
                    <span v-if="line.direction === 'debit'" class="text-rose-700 dark:text-rose-400">{{ money(line.amount) }}</span>
                  </td>
                  <td class="whitespace-nowrap px-4 py-2 text-right font-semibold tabular-nums">{{ money(line.balance) }}</td>
                </tr>
              </tbody>
              <tfoot class="border-t-2 bg-muted/40">
                <tr class="text-[12.5px] font-semibold">
                  <td class="px-4 py-2.5" colspan="4">Closing balance</td>
                  <td class="px-2 py-2.5 text-right tabular-nums">{{ money(byType.employee_contribution ?? 0) }}</td>
                  <td class="px-2 py-2.5 text-right tabular-nums">{{ money((byType.withdrawal ?? 0) + (byType.settlement ?? 0)) }}</td>
                  <td class="px-4 py-2.5 text-right text-[14px] font-bold tabular-nums">{{ money(statement.closing_balance) }}</td>
                </tr>
                <tr class="text-[10.5px] uppercase tracking-wider text-muted-foreground">
                  <td class="px-4 pb-2" colspan="4"></td>
                  <td class="px-2 pb-2 text-right">Employee</td>
                  <td class="px-2 pb-2 text-right">Debits</td>
                  <td class="px-4 pb-2 text-right">Ledger balance</td>
                </tr>
              </tfoot>
            </table>
          </div>
        </section>

        <!-- ==================== withdrawal history ==================== -->
        <section class="rounded-md border bg-card">
          <div class="border-b px-4 py-3">
            <h2 class="text-[13px] font-semibold">Withdrawal history</h2>
            <p class="mt-0.5 text-[11px] text-muted-foreground">
              Money leaves the fund only through the ledger, and only when a request is marked paid.
            </p>
          </div>

          <div v-if="withdrawals.length === 0" class="px-4 py-8 text-center text-[12px] text-muted-foreground">
            No withdrawal has ever been requested from this account.
          </div>

          <div v-else class="overflow-x-auto">
            <table class="w-full text-[12.5px]">
              <thead>
                <tr class="border-b bg-muted/40 text-[10px] uppercase tracking-[0.1em] text-muted-foreground">
                  <th class="px-4 py-2 text-left font-semibold">Date requested</th>
                  <th class="px-2 py-2 text-left font-semibold">Type</th>
                  <th class="px-2 py-2 text-right font-semibold">Requested</th>
                  <th class="px-2 py-2 text-right font-semibold">Approved</th>
                  <th class="px-2 py-2 text-left font-semibold">Reason</th>
                  <th class="px-2 py-2 text-left font-semibold">Status</th>
                  <th class="px-4 py-2 text-right font-semibold">Action</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-border/70">
                <tr v-for="row in withdrawals" :key="row.id">
                  <td class="whitespace-nowrap px-4 py-2 tabular-nums text-muted-foreground">{{ plainDate(row.created_at) }}</td>
                  <td class="whitespace-nowrap px-2 py-2 capitalize">{{ row.type.replaceAll('_', ' ') }}</td>
                  <td class="whitespace-nowrap px-2 py-2 text-right tabular-nums">{{ money(row.requested_amount) }}</td>
                  <td class="whitespace-nowrap px-2 py-2 text-right tabular-nums">{{ row.approved_amount ? money(row.approved_amount) : '—' }}</td>
                  <td class="max-w-[16rem] px-2 py-2">
                    <span class="line-clamp-2 text-[11.5px] text-muted-foreground">{{ row.reason }}</span>
                    <span v-if="row.decision_note" class="mt-0.5 block text-[10.5px] italic text-muted-foreground">“{{ row.decision_note }}”</span>
                  </td>
                  <td class="px-2 py-2">
                    <span class="text-[10px] font-semibold uppercase tracking-wide" :class="{
                      'text-amber-700 dark:text-amber-400': row.status === 'pending',
                      'text-sky-700 dark:text-sky-400': row.status === 'approved',
                      'text-emerald-700 dark:text-emerald-400': row.status === 'paid',
                      'text-rose-700 dark:text-rose-400': row.status === 'rejected',
                    }">{{ row.status }}</span>
                  </td>
                  <td class="whitespace-nowrap px-4 py-2 text-right">
                    <div v-if="canManage && row.status === 'pending'" class="flex justify-end gap-1">
                      <Button size="sm" variant="outline" class="h-7 px-2 text-[11px]" :disabled="deciding === Number(row.id)" @click="decide(Number(row.id), 'approve')">
                        <BadgeCheck class="size-3" />Approve
                      </Button>
                      <Button size="sm" variant="ghost" class="h-7 px-2 text-[11px]" :disabled="deciding === Number(row.id)" @click="decide(Number(row.id), 'reject')">
                        <XCircle class="size-3" />Reject
                      </Button>
                    </div>
                    <Button v-else-if="canManage && row.status === 'approved'" size="sm" class="h-7 px-2 text-[11px]" :disabled="deciding === Number(row.id)" @click="decide(Number(row.id), 'pay')">
                      <Banknote class="size-3" />Mark paid
                    </Button>
                    <span v-else class="text-[11px] text-muted-foreground">{{ row.paid_at ? plainDate(row.paid_at) : '—' }}</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>
      </div>

      <!-- ========================= side rail ========================= -->
      <aside class="space-y-4">
        <section v-if="canManage" class="rounded-md border bg-card">
          <div class="border-b px-4 py-2.5">
            <h2 class="text-[13px] font-semibold">New withdrawal request</h2>
          </div>
          <form class="space-y-2.5 px-4 py-3" @submit.prevent="requestWithdrawal">
            <p class="text-[11px] leading-snug text-muted-foreground">
              A partial withdrawal draws only on the member's own share. A final settlement may take both shares and closes the account when paid.
            </p>
            <div class="space-y-1">
              <Label class="text-[11px]">Type</Label>
              <select v-model="requestForm.type" class="h-8 w-full rounded border bg-background px-2 text-[12px]">
                <option value="partial">Partial withdrawal</option>
                <option value="final_settlement">Final settlement</option>
                <option value="loan_adjustment">Loan adjustment</option>
              </select>
              <p class="text-[10.5px] text-muted-foreground">Ceiling {{ money(ceilingFor(requestForm.type)) }}</p>
            </div>
            <div class="space-y-1">
              <Label class="text-[11px]">Amount</Label>
              <Input v-model="requestForm.requested_amount" type="number" min="0.01" :max="ceilingFor(requestForm.type)" step="0.01" class="h-8 text-[12px]" />
              <p v-if="requestForm.errors.requested_amount" class="text-[11px] text-destructive">{{ requestForm.errors.requested_amount }}</p>
            </div>
            <div class="space-y-1">
              <Label class="text-[11px]">Reason</Label>
              <textarea v-model="requestForm.reason" rows="3" class="w-full rounded border bg-background px-2 py-1.5 text-[12px]" placeholder="Why is the member withdrawing? Written to the audit trail." />
              <p v-if="requestForm.errors.reason" class="text-[11px] text-destructive">{{ requestForm.errors.reason }}</p>
            </div>
            <Button class="w-full" size="sm" :disabled="requestForm.processing">Create request</Button>
          </form>
        </section>

        <section class="rounded-md border bg-card px-4 py-3">
          <h2 class="text-[11px] font-semibold uppercase tracking-[0.12em] text-muted-foreground">Ledger summary</h2>
          <dl class="mt-2 space-y-1.5 text-[12px]">
            <div class="flex items-center justify-between gap-3">
              <dt class="text-muted-foreground">Entries</dt>
              <dd class="tabular-nums">{{ statement.lines.length.toLocaleString() }}</dd>
            </div>
            <div class="flex items-center justify-between gap-3">
              <dt class="text-muted-foreground">Employee contributions</dt>
              <dd class="tabular-nums">{{ money(byType.employee_contribution ?? 0) }}</dd>
            </div>
            <div class="flex items-center justify-between gap-3">
              <dt class="text-muted-foreground">Employer contributions</dt>
              <dd class="tabular-nums">{{ money(byType.employer_contribution ?? 0) }}</dd>
            </div>
            <div class="flex items-center justify-between gap-3">
              <dt class="text-muted-foreground">Withdrawn</dt>
              <dd class="tabular-nums text-rose-700 dark:text-rose-400">{{ money((byType.withdrawal ?? 0) + (byType.settlement ?? 0)) }}</dd>
            </div>
            <div class="flex items-center justify-between gap-3 border-t pt-1.5 font-semibold">
              <dt>Ledger balance</dt>
              <dd class="tabular-nums">{{ money(statement.closing_balance) }}</dd>
            </div>
          </dl>
        </section>
      </aside>
    </div>
  </AdminLayout>
</template>
