<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { BadgeCheck, Banknote, XCircle } from 'lucide-vue-next';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import { Button } from '@/components/ui/button';
import DataTable, { type DataTableColumn } from '@core/Components/DataTable.vue';
import type { Paginator } from '@core/types';
import { useToast } from '@core/Composables/useToast';
import { confirmDialog, promptDialog } from '@core/Composables/useConfirm';
import { formatCurrency } from '@core/Utils/format';

/**
 * The withdrawal desk.
 *
 * A withdrawal is the only way money leaves the fund, so this is the screen where a mistake is
 * expensive. Three rules shape it, and each is visible rather than implied:
 *
 *   - approving writes nothing to the ledger; only "mark paid" posts the debit;
 *   - a partial withdrawal may not exceed the member's own share, a final settlement may take both
 *     and closes the account;
 *   - a rejection always carries a reason, because the member is entitled to one.
 */

interface Row {
  id: number;
  pf_account_id: number;
  employee_id: string;
  type: string;
  requested_amount: string | number;
  approved_amount: string | number | null;
  reason: string;
  status: string;
  created_at: string | null;
  decided_at: string | null;
  paid_at: string | null;
  decision_note: string | null;
  account?: { employee_id: string; employee_balance: string | number; employer_balance: string | number } | null;
}

const props = defineProps<{
  withdrawals: Paginator<Row>;
  canManage: boolean;
  filterDefs: Array<{ key: string; label: string; kind: string; options: Array<{ value: string; label: string }> }>;
}>();

const toast = useToast();
const deciding = ref<number | null>(null);
const money = (value: number | string | null | undefined): string => formatCurrency(Number(value ?? 0));

const plainDate = (value: string | null | undefined): string => {
  if (!value) return '—';
  const [datePart] = String(value).split('T');
  const [year, month, day] = datePart.split('-').map(Number);
  if (!year || !month || !day) return datePart;
  return new Date(year, month - 1, day).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
};

const typeLabel = (type: string): string => ({
  partial: 'Partial withdrawal',
  final_settlement: 'Final settlement',
  loan_adjustment: 'Loan adjustment',
}[type] ?? type.replaceAll('_', ' '));

const onPage = computed(() => props.withdrawals.data);
const pendingRows = computed(() => onPage.value.filter((row) => row.status === 'pending'));
const pendingValue = computed(() => pendingRows.value.reduce((sum, row) => sum + Number(row.requested_amount ?? 0), 0));
const approvedValue = computed(() => onPage.value
  .filter((row) => row.status === 'approved')
  .reduce((sum, row) => sum + Number(row.approved_amount ?? row.requested_amount ?? 0), 0));

async function decide(row: Row, decision: 'approve' | 'reject' | 'pay'): Promise<void> {
  let note: string | null = null;

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
    note = asked.trim();
  } else {
    const copy = decision === 'approve'
      ? { title: 'Approve request', text: `Authorise ${money(row.requested_amount)} for ${row.employee_id}. No money moves yet — the ledger is only touched when the payout is marked paid.`, label: 'Approve' }
      : { title: 'Mark paid', text: `Post ${money(row.approved_amount ?? row.requested_amount)} as a debit against ${row.employee_id}'s account. This reduces the member's balance${row.type === 'final_settlement' ? ' and closes the account' : ''}.`, label: 'Mark paid' };

    const proceed = await confirmDialog({ title: copy.title, description: copy.text, confirmLabel: copy.label, danger: decision === 'pay' });
    if (!proceed) return;
  }

  deciding.value = row.id;
  router.post(route('payroll.pf.withdrawals.decide'), { withdrawal_id: row.id, decision, note }, {
    preserveScroll: true,
    onError: (errors) => toast.error(Object.values(errors).join(' ')),
    onFinish: () => { deciding.value = null; },
  });
}

const columns: DataTableColumn[] = [
  { key: 'employee_id', label: 'Member' },
  { key: 'type', label: 'Type' },
  { key: 'requested_amount', label: 'Requested', align: 'right' },
  { key: 'approved_amount', label: 'Approved', align: 'right' },
  { key: 'reason', label: 'Reason' },
  { key: 'status', label: 'Status' },
  { key: 'actions', label: '', align: 'right', exportable: false },
];
</script>

<template>
  <AdminLayout :breadcrumbs="[{ title: 'Payroll' }, { title: 'Provident Fund', href: route('payroll.pf.index') }, { title: 'Withdrawals' }]">
    <Head title="PF withdrawals" />

    <header class="border-b pb-4">
      <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
          <h1 class="text-lg font-semibold tracking-tight">Withdrawals &amp; settlements</h1>
          <p class="mt-0.5 text-[13px] text-muted-foreground">
            A request, a decision, and a payment that posts the ledger debit — the only path money takes out of the fund.
          </p>
        </div>
        <Button size="sm" variant="outline" @click="router.visit(route('payroll.pf.index'))">Fund register</Button>
      </div>

      <dl class="mt-4 grid grid-cols-2 divide-y divide-border rounded-md border bg-card sm:grid-cols-4 sm:divide-x sm:divide-y-0">
        <div class="px-4 py-3">
          <dt class="text-[10px] font-semibold uppercase tracking-[0.12em] text-muted-foreground">Awaiting a decision</dt>
          <dd class="mt-1 text-[19px] font-semibold leading-none tabular-nums">{{ pendingRows.length.toLocaleString() }}</dd>
          <dd class="mt-1 text-[11px] text-muted-foreground">{{ pendingValue > 0 ? money(pendingValue) : 'nothing pending' }}</dd>
        </div>
        <div class="px-4 py-3">
          <dt class="text-[10px] font-semibold uppercase tracking-[0.12em] text-muted-foreground">Approved, unpaid</dt>
          <dd class="mt-1 text-[19px] font-semibold leading-none tabular-nums">{{ onPage.filter((r) => r.status === 'approved').length.toLocaleString() }}</dd>
          <dd class="mt-1 text-[11px] text-muted-foreground">{{ approvedValue > 0 ? money(approvedValue) : 'money not yet moved' }}</dd>
        </div>
        <div class="px-4 py-3">
          <dt class="text-[10px] font-semibold uppercase tracking-[0.12em] text-muted-foreground">Paid on this page</dt>
          <dd class="mt-1 text-[19px] font-semibold leading-none tabular-nums">{{ onPage.filter((r) => r.status === 'paid').length.toLocaleString() }}</dd>
          <dd class="mt-1 text-[11px] text-muted-foreground">posted to the ledger</dd>
        </div>
        <div class="px-4 py-3">
          <dt class="text-[10px] font-semibold uppercase tracking-[0.12em] text-muted-foreground">All requests</dt>
          <dd class="mt-1 text-[19px] font-semibold leading-none tabular-nums">{{ withdrawals.meta.total.toLocaleString() }}</dd>
          <dd class="mt-1 text-[11px] text-muted-foreground">in the fund's history</dd>
        </div>
      </dl>
    </header>

    <section class="mt-5 rounded-md border bg-card">
      <div class="border-b px-4 py-3">
        <h2 class="text-[13px] font-semibold">Request register</h2>
        <p class="mt-0.5 text-[11px] text-muted-foreground">
          Approving authorises a payout without moving money. Marking paid posts the debit and, for a final settlement, closes the account.
        </p>
      </div>

      <div class="px-4 pb-4 pt-3">
        <DataTable
          route-name="payroll.pf.withdrawals.index"
          title="PF withdrawal requests"
          :columns="columns"
          :rows="withdrawals.data"
          :meta="withdrawals.meta"
          :filter-defs="filterDefs"
          :default-sort="{ key: 'created_at', direction: 'desc' }"
          search-placeholder="Search by member or reason…"
          empty-title="No withdrawal requests"
          empty-description="Requests are raised from a member's statement."
        >
          <template #cell-employee_id="{ row }">
            <button type="button" class="text-left hover:underline" @click="router.visit(route('payroll.pf.show', row.pf_account_id))">
              <span class="block font-mono text-[12px] font-medium leading-tight">{{ row.employee_id }}</span>
              <span v-if="row.account" class="block text-[10.5px] leading-tight text-muted-foreground">
                holds {{ money(Number(row.account.employee_balance) + Number(row.account.employer_balance)) }}
              </span>
            </button>
          </template>
          <template #cell-type="{ row }">
            <span class="text-[12px]">{{ typeLabel(row.type) }}</span>
            <span v-if="row.type === 'final_settlement'" class="block text-[10px] text-muted-foreground">closes the account when paid</span>
          </template>
          <template #cell-requested_amount="{ row }">
            <span class="tabular-nums">{{ money(row.requested_amount) }}</span>
          </template>
          <template #cell-approved_amount="{ row }">
            <span class="tabular-nums">{{ row.approved_amount ? money(row.approved_amount) : '—' }}</span>
          </template>
          <template #cell-reason="{ row }">
            <span class="line-clamp-2 text-[11.5px] text-muted-foreground">{{ row.reason }}</span>
            <span v-if="row.decision_note" class="mt-0.5 block text-[10.5px] italic text-muted-foreground">“{{ row.decision_note }}”</span>
          </template>
          <template #cell-status="{ row }">
            <span class="text-[10px] font-semibold uppercase tracking-wide" :class="{
              'text-amber-700 dark:text-amber-400': row.status === 'pending',
              'text-sky-700 dark:text-sky-400': row.status === 'approved',
              'text-emerald-700 dark:text-emerald-400': row.status === 'paid',
              'text-rose-700 dark:text-rose-400': row.status === 'rejected',
            }">{{ row.status }}</span>
            <span class="mt-0.5 block text-[10px] text-muted-foreground">{{ plainDate(row.created_at) }}</span>
          </template>
          <template #cell-actions="{ row }">
            <div v-if="canManage" class="flex justify-end gap-1">
              <template v-if="row.status === 'pending'">
                <Button size="sm" variant="outline" class="h-7 px-2 text-[11px]" :disabled="deciding === Number(row.id)" @click="decide(row, 'approve')">
                  <BadgeCheck class="size-3" />Approve
                </Button>
                <Button size="sm" variant="ghost" class="h-7 px-2 text-[11px]" :disabled="deciding === Number(row.id)" @click="decide(row, 'reject')">
                  <XCircle class="size-3" />Reject
                </Button>
              </template>
              <Button v-else-if="row.status === 'approved'" size="sm" class="h-7 px-2 text-[11px]" :disabled="deciding === Number(row.id)" @click="decide(row, 'pay')">
                <Banknote class="size-3" />Mark paid
              </Button>
              <span v-else class="text-[11px] text-muted-foreground">—</span>
            </div>
            <span v-else class="text-[11px] text-muted-foreground">—</span>
          </template>
        </DataTable>
      </div>
    </section>
  </AdminLayout>
</template>
