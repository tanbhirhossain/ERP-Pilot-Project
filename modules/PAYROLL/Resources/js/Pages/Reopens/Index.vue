<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { RotateCcw, AlertTriangle, CheckCircle2, FileBarChart, Plus } from 'lucide-vue-next';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import ConfirmDialog from '../../../../../CORE/Resources/js/Components/ConfirmDialog.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import StaticTable from '../../../../../CORE/Resources/js/Components/StaticTable.vue';

const props = defineProps<{
  pending: any[];
  recent: any[];
  /** Released sheets eligible for reopening, from PayrollReopenController::index(). */
  sheets?: Array<{ id: number; label: string; has_pending_request: boolean }>;
}>();

const eligible = (props.sheets ?? []).filter((s) => !s.has_pending_request);

// ── Request a reopen ────────────────────────────────────────────────────────
// Was previously a window.prompt() that nothing ever called, which left the
// whole request flow unreachable from the UI.
const showRequest = ref(false);
const requestForm = useForm({ salary_sheet_id: null as number | null, reason: '' });
function submitRequest() {
  if (!requestForm.salary_sheet_id) {
    requestForm.setError('salary_sheet_id', 'Choose a released salary sheet.');
    return;
  }
  requestForm.post(route('payroll.reopens.request', requestForm.salary_sheet_id), {
    preserveScroll: true,
    onSuccess: () => {
      requestForm.reset();
      showRequest.value = false;
    },
  });
}

// ── Approve a reopen ────────────────────────────────────────────────────────
// Approving voids an accrual journal, so it goes through the shared confirm
// dialog rather than a blocking window.confirm().
const confirmOpen = ref(false);
const processing = ref(false);
const target = ref<any | null>(null);
function askApprove(reopen: any) {
  target.value = reopen;
  confirmOpen.value = true;
}
function approveReopen() {
  if (!target.value) return;
  processing.value = true;
  router.post(route('payroll.reopens.approve', target.value.id), {}, {
    preserveScroll: true,
    onFinish: () => {
      processing.value = false;
      confirmOpen.value = false;
      target.value = null;
    },
  });
}
function sheetLabel(reopen: any): string {
  const s = reopen?.sheet;
  return s ? `${s.pay_year}-${String(s.pay_month).padStart(2, '0')}` : `sheet #${reopen?.salary_sheet_id}`;
}
</script>
<template>
 <AdminLayout :breadcrumbs="[{ title: 'Payroll' }, { title: 'Sheet Reopens' }]">
  <Head title="Payroll Reopens"/>
  <div class="overflow-hidden rounded-xl border bg-gradient-to-br from-slate-900 via-indigo-950 to-slate-900 p-6 text-white">
    <h1 class="flex items-center gap-2 text-2xl font-bold"><RotateCcw class="size-6"/> Payroll Reopen — Finance-Controlled Reverse</h1>
    <p class="mt-1 text-sm text-indigo-100/80">Released → mistake? Finance approves reverse JV → sheet becomes <code>ready</code> again for re-calc. All reversals are audited.</p>
  </div>

  <section class="mt-6 rounded-xl border bg-card p-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <h2 class="flex items-center gap-2 font-bold"><RotateCcw class="size-5 text-primary"/> Request a Reopen</h2>
      <Button @click="showRequest = !showRequest" variant="outline" size="sm"><Plus class="size-4"/> {{ showRequest ? 'Close' : 'New Request' }}</Button>
    </div>
    <p class="mt-1 text-sm text-muted-foreground">
      Raises a request against a <strong>released</strong> sheet. Nothing is reversed until finance approves — approval
      voids the accrual journal by posting an opposite entry and returns the sheet to <code>ready</code>.
    </p>

    <div v-if="showRequest" class="mt-4 space-y-4 rounded-lg border bg-muted/20 p-4">
      <div v-if="!eligible.length" class="text-sm text-muted-foreground">
        No released sheet is available to reopen right now — either none are released, or every released sheet already
        has a request awaiting approval.
      </div>
      <template v-else>
        <div class="space-y-1">
          <Label for="reopen-sheet">Salary sheet *</Label>
          <select id="reopen-sheet" v-model="requestForm.salary_sheet_id" class="h-9 w-full rounded-md border bg-background px-3 text-sm">
            <option :value="null" disabled>Select a released sheet…</option>
            <option v-for="s in eligible" :key="s.id" :value="s.id">{{ s.label }}</option>
          </select>
          <p v-if="requestForm.errors.salary_sheet_id" class="text-xs text-destructive">{{ requestForm.errors.salary_sheet_id }}</p>
        </div>

        <div class="space-y-1">
          <Label for="reopen-reason">Reason * <span class="font-normal text-muted-foreground">(audit trail, min 10 characters)</span></Label>
          <Textarea id="reopen-reason" v-model="requestForm.reason" rows="3" placeholder="e.g. Overtime for 12–18 of the month was omitted before release; needs re-calc." />
          <p v-if="requestForm.errors.reason" class="text-xs text-destructive">{{ requestForm.errors.reason }}</p>
        </div>

        <div class="flex justify-end gap-2">
          <Button variant="ghost" @click="showRequest = false" :disabled="requestForm.processing">Cancel</Button>
          <Button @click="submitRequest" :disabled="requestForm.processing" class="bg-amber-600 hover:bg-amber-700">
            <RotateCcw class="size-4"/> {{ requestForm.processing ? 'Submitting…' : 'Submit Request' }}
          </Button>
        </div>
      </template>
    </div>
  </section>

  <section class="mt-6 rounded-xl border bg-card p-6">
    <h2 class="flex items-center gap-2 font-bold"><AlertTriangle class="size-5 text-amber-600"/> Pending Approvals ({{pending.length}})</h2>
    <div v-if="pending.length===0" class="mt-3 rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">No pending reopens — use “Request Reopen” below to raise one against a released sheet.</div>
    <div v-else class="mt-3 grid gap-3">
      <div v-for="r in pending" :key="r.id" class="rounded-lg border p-4 flex flex-wrap items-center justify-between gap-3">
        <div><p class="font-semibold">{{r.sheet?.pay_year}}-{{String(r.sheet?.pay_month).padStart(2,'0')}} #{{r.sheet?.id}} — {{r.sheet?.status}}</p><p class="text-xs text-muted-foreground">Requested by {{r.requested_by}} — {{new Date(r.created_at).toLocaleString()}}</p><p class="mt-1 text-sm bg-amber-50 border border-amber-200 rounded p-2">{{r.reason}}</p></div>
        <Button @click="askApprove(r)" class="bg-amber-600 hover:bg-amber-700"><CheckCircle2 class="size-4"/> Approve & Reverse JV</Button>
      </div>
    </div>
  </section>

  <section class="mt-6 rounded-xl border bg-card p-6">
    <h2 class="flex items-center gap-2 font-bold"><FileBarChart class="size-5 text-primary"/> Recent (20)</h2>
    <StaticTable
        class="mt-3"
        :empty="!recent?.length"
        empty-title="No sheets reopened yet"
        empty-description="Recent reopen requests appear here."
    ><thead class="bg-muted/60 text-xs uppercase"><tr><th class="px-3 py-2">Sheet</th><th class="px-3 py-2">Reason</th><th class="px-3 py-2">Requested</th><th class="px-3 py-2">Approved</th><th class="px-3 py-2">Reversal JV</th></tr></thead>
        <tbody class="divide-y"><tr v-for="r in recent" :key="r.id" class="hover:bg-muted/20"><td class="px-3 py-2 font-mono text-xs">{{r.sheet?.pay_year}}-{{r.sheet?.pay_month}} #{{r.salary_sheet_id}} {{r.sheet?.status}}</td><td class="px-3 py-2 max-w-xs truncate text-xs">{{r.reason}}</td><td class="px-3 py-2 text-xs">{{new Date(r.created_at).toLocaleDateString()}}</td><td class="px-3 py-2 text-xs">{{r.approved_at ? new Date(r.approved_at).toLocaleString() : '—'}}</td><td class="px-3 py-2 font-mono text-xs">{{r.reversal_journal_id ?? '—'}}</td></tr>
        </tbody></StaticTable>
    <p class="mt-2 text-xs text-muted-foreground">Reversal via <code>AccountsPostingProvider::voidJournal(journal_id, reason)</code> — creates opposite entry, never deletes.</p>
  </section>

  <ConfirmDialog
    v-model:open="confirmOpen"
    title="Approve reopen and reverse the accrual?"
    :description="`This voids the accrual journal for ${sheetLabel(target)} by posting an opposite entry, and returns the sheet to ready for re-calculation. The reversal is audited.`"
    confirm-label="Approve & Reverse JV"
    :processing="processing"
    @confirm="approveReopen"
  />
 </AdminLayout>
</template>
