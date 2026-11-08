<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatCurrency} from '../../../../../CORE/Resources/js/Utils/format';
import { ref } from 'vue';
import StaticTable from '../../../../../CORE/Resources/js/Components/StaticTable.vue';
defineProps<{ banks:any[]; bank:any; statements:any[]; unreconciledTxns:any[] }>();
function changeBank(e:any){ router.get(route('accounts.banks.reconcile'), {bank_id: e.target.value}); }
const stmtId=ref(''), txnId=ref('');
function importCsv(e:any){
  const form = e.target as HTMLFormElement;
  const fd = new FormData(form);
  router.post(route('accounts.banks.reconcile.import'), fd, { forceFormData:true });
}
function match(){ router.post(route('accounts.banks.reconcile.match'), {statement_id: Number(stmtId.value), bank_txn_id: Number(txnId.value)}); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Accounts' }, { title: 'Banks', href: route('accounts.banks.index') }, { title: 'Reconciliation' }]">
    <Head title="Bank Reconciliation" />
    <div class="space-y-4 max-w-6xl mx-auto">
      <div class="flex items-center justify-between gap-3">
        <h1 class="text-xl font-bold">🏦 Bank Reconciliation — Statement vs Ledger</h1>
        <select @change="changeBank" :value="bank?.id" class="h-9 rounded border bg-background px-3 text-sm">
          <option v-for="b in banks" :key="b.id" :value="b.id">{{ b.name }} — {{ b.account_no }} ({{ b.bank_name }})</option>
        </select>
      </div>
      <p class="text-sm text-muted-foreground">Import bank CSV (Date, Description, Reference, Amount, Balance). Then match each statement line to a pending Bank Transaction. Amount must match ±0.01.</p>

      <div class="bg-card border rounded-xl p-4">
        <h3 class="font-semibold mb-2">Import Statement CSV</h3>
        <form @submit.prevent="importCsv" enctype="multipart/form-data" class="flex gap-2 items-end">
          <input type="hidden" name="bank_id" :value="bank?.id" />
          <div class="space-y-1"><Label>CSV File</Label><Input type="file" name="file" accept=".csv,.txt" required /></div>
          <Button type="submit" size="sm">Import CSV</Button>
          <span class="text-xs text-muted-foreground">Headers: date, description, reference, amount, balance — or credit/debit columns. Example row: 2025-11-01,Sale receipt,REF001,15000,85000</span>
        </form>
      </div>

      <div class="bg-card border rounded-xl p-4">
        <h3 class="font-semibold mb-2">Manual Match</h3>
        <div class="flex gap-2 items-end">
          <div class="space-y-1"><Label>Statement Line ID</Label><Input v-model="stmtId" placeholder="e.g. 1" class="w-24" /></div>
          <div class="space-y-1"><Label>Bank Txn ID</Label><Input v-model="txnId" placeholder="e.g. 5" class="w-24" /></div>
          <Button size="sm" @click="match">Reconcile ✓</Button>
        </div>
      </div>

      <div class="grid md:grid-cols-2 gap-4">
        <div class="bg-card border rounded-xl p-3">
          <h3 class="font-semibold mb-2">Bank Statements — {{ statements.length }} lines <span class="text-xs font-normal text-muted-foreground">(imported)</span></h3>
          <StaticTable
            dense
            sticky
            :empty="!statements?.length"
            empty-title="No statement lines"
            empty-description="Imported statement lines for the selected bank appear here."
          >
            <thead class="sticky top-0 bg-card"><tr class="border-b text-muted-foreground"><th class="text-left py-1">ID</th><th class="text-left">Date</th><th class="text-left">Description</th><th class="text-right">Amount</th><th class="text-center">✓</th><th></th></tr></thead>
            <tbody><tr v-for="s in statements" :key="s.id" :class="['border-b', s.is_reconciled?'bg-emerald-50':'']"><td class="font-mono">{{ s.id }}</td><td>{{ s.txn_date }}</td><td>{{ s.description }} <span class="text-muted-foreground">({{ s.reference_no }})</span></td><td class="text-right font-mono" :class="Number(s.amount)>=0?'text-emerald-700':'text-red-600'">{{ formatCurrency(Number(s.amount)) }}</td><td class="text-center">{{ s.is_reconciled?'✅':'—' }}</td><td><button v-if="s.is_reconciled" @click="router.post(route('accounts.banks.reconcile.unreconcile', s.id))" class="text-xs text-muted-foreground hover:underline">Undo</button></td></tr></tbody>
          </StaticTable>
        </div>
        <div class="bg-card border rounded-xl p-3">
          <h3 class="font-semibold mb-2">Pending Bank Transactions (Ledger) — {{ unreconciledTxns.length }}</h3>
          <StaticTable
            dense
            sticky
            :empty="!unreconciledTxns?.length"
            empty-title="No pending transactions"
            empty-description="Unreconciled transactions for the selected bank appear here."
          >
            <thead class="sticky top-0 bg-card"><tr class="border-b text-muted-foreground"><th class="text-left py-1">ID</th><th class="text-left">Date</th><th class="text-left">Type</th><th class="text-right">Amount</th><th class="text-left">Ref</th></tr></thead>
            <tbody><tr v-for="t in unreconciledTxns" :key="t.id" class="border-b"><td class="font-mono">{{ t.id }}</td><td>{{ t.transaction_date }}</td><td>{{ t.type }}</td><td class="text-right font-mono">{{ formatCurrency(Number(t.amount)) }}</td><td class="font-mono text-xs">{{ t.reference_no }}</td></tr></tbody>
          </StaticTable>
        </div>
      </div>
    </div>
  </AdminLayout>
</template>
