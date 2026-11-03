<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { RotateCcw, AlertTriangle, CheckCircle2, FileBarChart } from 'lucide-vue-next';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import { Button } from '@/components/ui/button';
const props = defineProps<{ pending:any[]; recent:any[] }>();
function requestReopen(sheetId:number){
  const reason = prompt('Reason for reopen (audit trail, min 10 chars):');
  if(!reason || reason.length<10) return alert('Reason required (≥10 chars).');
  router.post(route('payroll.reopens.request', sheetId), {reason}, {preserveScroll:true});
}
function approveReopen(reopen:any){
  if(!confirm(`Approve reopen for ${reopen.sheet?.pay_year}-${reopen.sheet?.pay_month}? Accrual JV will be voided (reversal posted) and sheet back to ready.`)) return;
  router.post(route('payroll.reopens.approve', reopen.id), {}, {preserveScroll:true});
}
</script>
<template>
 <AdminLayout :breadcrumbs="[{title:'Payroll', href: route('payroll.sheets.index')},{title:'Sheet Reopens'}]">
  <Head title="Payroll Reopens"/>
  <div class="overflow-hidden rounded-2xl border bg-gradient-to-br from-slate-900 via-indigo-950 to-slate-900 p-6 text-white">
    <h1 class="flex items-center gap-2 text-2xl font-bold"><RotateCcw class="size-6"/> Payroll Reopen — Finance-Controlled Reverse</h1>
    <p class="mt-1 text-sm text-indigo-100/80">Released → mistake? Finance approves reverse JV → sheet becomes <code>ready</code> again for re-calc. All reversals are audited.</p>
  </div>

  <section class="mt-6 rounded-2xl border bg-card p-6">
    <h2 class="flex items-center gap-2 font-bold"><AlertTriangle class="size-5 text-amber-600"/> Pending Approvals ({{pending.length}})</h2>
    <div v-if="pending.length===0" class="mt-3 rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">No pending reopens — request from Salary Sheet finance page.</div>
    <div v-else class="mt-3 grid gap-3">
      <div v-for="r in pending" :key="r.id" class="rounded-xl border p-4 flex flex-wrap items-center justify-between gap-3">
        <div><p class="font-semibold">{{r.sheet?.pay_year}}-{{String(r.sheet?.pay_month).padStart(2,'0')}} #{{r.sheet?.id}} — {{r.sheet?.status}}</p><p class="text-xs text-muted-foreground">Requested by {{r.requested_by}} — {{new Date(r.created_at).toLocaleString()}}</p><p class="mt-1 text-sm bg-amber-50 border border-amber-200 rounded p-2">{{r.reason}}</p></div>
        <Button @click="approveReopen(r)" class="bg-amber-600 hover:bg-amber-700"><CheckCircle2 class="size-4"/> Approve & Reverse JV</Button>
      </div>
    </div>
  </section>

  <section class="mt-6 rounded-2xl border bg-card p-6">
    <h2 class="flex items-center gap-2 font-bold"><FileBarChart class="size-5 text-primary"/> Recent (20)</h2>
    <div class="mt-3 overflow-x-auto rounded-lg border">
      <table class="w-full text-sm"><thead class="bg-muted/60 text-xs uppercase"><tr><th class="px-3 py-2">Sheet</th><th class="px-3 py-2">Reason</th><th class="px-3 py-2">Requested</th><th class="px-3 py-2">Approved</th><th class="px-3 py-2">Reversal JV</th></tr></thead>
        <tbody class="divide-y"><tr v-for="r in recent" :key="r.id" class="hover:bg-muted/20"><td class="px-3 py-2 font-mono text-xs">{{r.sheet?.pay_year}}-{{r.sheet?.pay_month}} #{{r.salary_sheet_id}} {{r.sheet?.status}}</td><td class="px-3 py-2 max-w-xs truncate text-xs">{{r.reason}}</td><td class="px-3 py-2 text-xs">{{new Date(r.created_at).toLocaleDateString()}}</td><td class="px-3 py-2 text-xs">{{r.approved_at ? new Date(r.approved_at).toLocaleString() : '—'}}</td><td class="px-3 py-2 font-mono text-xs">{{r.reversal_journal_id ?? '—'}}</td></tr>
        </tbody></table>
    </div>
    <p class="mt-2 text-xs text-muted-foreground">Reversal via <code>AccountsPostingProvider::voidJournal(journal_id, reason)</code> — creates opposite entry, never deletes.</p>
  </section>
 </AdminLayout>
</template>
