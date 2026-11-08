<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Scale, ShieldCheck, ArrowUpRight, ArrowDownRight, Calendar, Printer, Search } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import { printDocument } from '../../../../../CORE/Resources/js/Composables/usePrint';
import PageHeader from '../../../../../CORE/Resources/js/Components/PageHeader.vue';
import { formatCurrency } from '../../../../../CORE/Resources/js/Utils/format';
import AcctKpi from '../../Components/AcctKpi.vue';
import AcctSection from '../../Components/AcctSection.vue';
import { computed, ref } from 'vue';
import StaticTable from '../../../../../CORE/Resources/js/Components/StaticTable.vue';

const props = defineProps<{ asOf:string; rows:any[]; totals:any }>();
const asOfDate=ref(new URLSearchParams(window.location.search).get('as_of') || props.asOf || new Date().toISOString().slice(0,10));
const q = ref('');
function apply(){ router.get(route('accounts.reports.trial'), {as_of: asOfDate.value}, {preserveState:true}); }
const filtered = computed(()=> {
  if(!q.value.trim()) return props.rows;
  const s = q.value.toLowerCase();
  return props.rows.filter((r:any)=> `${r.code} ${r.name} ${r.account_type}`.toLowerCase().includes(s));
});
const balanced = computed(()=> Math.abs((props.totals.debit ?? 0) - (props.totals.credit ?? 0)) < 0.01);
const byType = computed(()=> {
  const g:any = {};
  for(const r of props.rows){ g[r.account_type] = (g[r.account_type]||0) + Math.abs(r.balance); }
  return g;
});
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'Accounts'},{title:'Trial Balance'}]">
    <Head title="Trial Balance" />
    <PageHeader
      title="Trial Balance"
      description="All ledgers aggregated to as_of — Σ Debit must equal Σ Credit. Basis for every other report."
      :icon="Scale"
      :actions="[{ label:'Print', icon: Printer, variant:'outline', onClick: () => printDocument() }]"
      :stats="[
        { label:'Total Debit', value: formatCurrency(totals.debit), sub: rows.length + ' accounts' },
        { label:'Total Credit', value: formatCurrency(totals.credit), sub: 'Mirror of debit' },
        { label: balanced ? 'Balanced' : 'Out by', value: balanced ? '✓ In balance' : formatCurrency(Math.abs(totals.debit - totals.credit)), sub: balanced ? 'Dr = Cr' : 'Review postings' },
        { label:'As of', value: asOf, sub: filtered.length + ' visible' },
      ]"
    >
      <!-- KPI strip -->
      <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
        <AcctKpi label="Total Debits" :value="formatCurrency(totals.debit)" sub="Σ debit postings" :icon="ArrowUpRight" tone="info" />
        <AcctKpi label="Total Credits" :value="formatCurrency(totals.credit)" sub="Σ credit postings" :icon="ArrowDownRight" tone="warning" />
        <div class="rounded-xl border p-4 shadow-sm" :class="balanced ? 'bg-emerald-500/[0.06] border-emerald-500/20' : 'bg-rose-500/[0.06] border-rose-500/20'">
          <p class="text-[11px] font-semibold tracking-widest uppercase" :class="balanced?'text-emerald-600':'text-rose-600'">{{ balanced ? 'Balanced' : 'Imbalance' }}</p>
          <p class="text-lg font-bold font-mono mt-1 flex items-center gap-2"><ShieldCheck v-if="balanced" class="size-4 text-emerald-600" /> {{ balanced ? 'Dr = Cr ✓' : formatCurrency(Math.abs(totals.debit - totals.credit)) }}</p>
          <p class="text-xs text-muted-foreground mt-1">Double-entry check</p>
        </div>
        <AcctKpi label="Asset concentration" :value="formatCurrency(byType.asset ?? 0)" :sub="Object.entries(byType).map(([k,v]:any)=>`${k}: ${formatCurrency(v)}`).slice(0,2).join(' · ')" :icon="Scale" tone="default" />
      </div>

      <!-- Controls -->
      <div class="bg-card border rounded-xl p-3 shadow-sm flex flex-wrap items-center gap-3">
        <div class="space-y-1"><label class="text-xs font-medium">As of</label><Input v-model="asOfDate" type="date" class="w-40 h-9" /></div>
        <Button @click="apply" size="sm" class="h-9">Apply</Button>
        <div class="relative flex-1 min-w-[12rem] max-w-sm">
          <Search class="absolute left-2.5 top-1/2 -translate-y-1/2 size-4 text-muted-foreground" />
          <Input v-model="q" placeholder="Filter code / account / type…" class="pl-8 h-9" />
        </div>
        <span class="ml-auto text-xs text-muted-foreground hidden sm:inline">BDT · posting_date ≤ as_of</span>
      </div>

      <AcctSection title="Accounts" :description="`${filtered.length} of ${rows.length} accounts · ordered by code`">
        <StaticTable flush sticky>
            <thead class="sticky top-0 z-10 bg-muted/90 backdrop-blur text-xs uppercase tracking-widest"><tr>
              <th class="px-3 py-2.5 text-left font-semibold">Account</th>
              <th class="px-3 py-2.5 text-center font-semibold">Type</th>
              <th class="px-3 py-2.5 text-right font-semibold">Debit</th>
              <th class="px-3 py-2.5 text-right font-semibold">Credit</th>
              <th class="px-3 py-2.5 text-right font-semibold">Balance</th>
            </tr></thead>
            <tbody class="divide-y">
              <tr v-for="r in filtered" :key="r.account_id" class="hover:bg-muted/40 transition">
                <td class="px-3 py-2.5"><div class="flex flex-col"><span class="font-medium flex items-center gap-1.5"><span class="font-mono text-xs px-1.5 py-0.5 rounded bg-muted border">{{ r.code }}</span> {{ r.name }}</span><span class="text-xs text-muted-foreground">{{ r.account_type }} · {{ r.nature }}</span></div></td>
                <td class="px-3 py-2.5 text-center"><span class="text-xs px-2 py-0.5 rounded-full border font-medium"
                  :class="r.account_type==='asset'?'bg-sky-500/10 text-sky-700 border-sky-500/20':r.account_type==='liability'?'bg-amber-500/10 text-amber-700 border-amber-500/20':r.account_type==='equity'?'bg-violet-500/10 text-violet-700 border-violet-500/20':r.account_type==='income'?'bg-emerald-500/10 text-emerald-700 border-emerald-500/20':'bg-rose-500/10 text-rose-700 border-rose-500/20'">{{ r.account_type }}</span></td>
                <td class="px-3 py-2.5 text-right font-mono">{{ r.total_debit>0.005 ? formatCurrency(r.total_debit) : '—' }}</td>
                <td class="px-3 py-2.5 text-right font-mono">{{ r.total_credit>0.005 ? formatCurrency(r.total_credit) : '—' }}</td>
                <td class="px-3 py-2.5 text-right"><span class="font-mono font-bold" :class="r.balance>=0?'text-emerald-600':'text-rose-600'">{{ formatCurrency(Math.abs(r.balance)) }} <span class="text-xs font-semibold">{{ r.balance_side.toUpperCase() }}</span></span></td>
              </tr>
              <tr v-if="!filtered.length"><td colspan="5" class="px-3 py-12 text-center text-muted-foreground">No accounts match “{{ q }}”.</td></tr>
            </tbody>
            <tfoot class="sticky bottom-0 bg-primary text-primary-foreground font-bold"><tr><td colspan="2" class="px-3 py-3 text-right">TOTAL</td><td class="px-3 py-3 text-right font-mono">{{ formatCurrency(totals.debit) }}</td><td class="px-3 py-3 text-right font-mono">{{ formatCurrency(totals.credit) }}</td><td class="px-3 py-3 text-right text-xs opacity-80">Dr = Cr {{ balanced ? '✓' : '✗' }}</td></tr></tfoot>
          </StaticTable>
      </AcctSection>
      <p class="text-xs text-muted-foreground flex items-center gap-2"><Calendar class="size-3.5" /> Snapshot at {{ asOf }} · Ledger table only (posted journals). Void reverses stay visible for audit.</p>
    </PageHeader>
  </AdminLayout>
</template>
