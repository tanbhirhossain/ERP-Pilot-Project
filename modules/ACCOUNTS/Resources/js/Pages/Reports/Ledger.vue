<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import type { Account } from '@accounts/types';
import type { SelectOption } from '@core/types';
import { BookMarked, ArrowUpRight, ArrowDownRight, Scale, Search, Calendar, Printer } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import { printDocument } from '@core/Composables/usePrint';
import PageHeader from '@core/Components/PageHeader.vue';
import SearchableSelect from '@core/Components/SearchableSelect.vue';
import { formatCurrency } from '@core/Utils/format';
import AcctKpi from '../../Components/AcctKpi.vue';
import AcctSection from '../../Components/AcctSection.vue';
import AcctEmpty from '../../Components/AcctEmpty.vue';
import { ref, computed } from 'vue';
import StaticTable from '@core/Components/StaticTable.vue';
const props=defineProps<{ account:Account; from:string|null; to:string|null; entries:any[]; accountOptions:SelectOption[] }>();
const accId=ref(props.account?.id ?? null);
const from=ref(props.from ?? '');
const to=ref(props.to ?? '');
function apply(){ router.get(route('accounts.reports.ledger'), {account_id: accId.value, from: from.value || undefined, to: to.value || undefined}, {preserveState:true}); }
const totalDr = computed(()=> props.entries.reduce((s,e)=> s + Number(e.debit||0), 0));
const totalCr = computed(()=> props.entries.reduce((s,e)=> s + Number(e.credit||0), 0));
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'Accounts'},{title:'General Ledger'}]">
    <Head title="General Ledger" />
    <PageHeader
      title="General Ledger"
      description="Account-wise drill-down — posting_date, reference, debit / credit and running balance. Paginated 200."
      :icon="BookMarked"
      :actions="[{ label:'Print', icon: Printer, variant:'outline', onClick: () => printDocument() }]"
      :stats="[
        { label:'Filters', value: account ? `${account.code} · ${account.name}` : 'Pick an account', sub: `${entries.length} entries` },
        { label:'Total Debit', value: formatCurrency(totalDr), sub: 'In window' },
        { label:'Total Credit', value: formatCurrency(totalCr), sub: 'In window' },
        { label:'Net', value: formatCurrency(Math.abs(totalDr-totalCr)) + (totalDr>=totalCr?' Dr':' Cr'), sub: `${from||'—'} → ${to||'now'}` },
      ]"
    >
      <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
        <AcctKpi label="Entries in view" :value="String(entries.length)" :sub="account ? `${account.code} ${account.name}` : 'Select account to start'" :icon="BookMarked" tone="default" />
        <AcctKpi label="Period debits" :value="formatCurrency(totalDr)" sub="Sum of Dr" :icon="ArrowUpRight" tone="info" />
        <AcctKpi label="Period credits" :value="formatCurrency(totalCr)" sub="Sum of Cr" :icon="ArrowDownRight" tone="warning" />
        <AcctKpi label="Balance move" :value="formatCurrency(Math.abs(totalDr-totalCr))" :sub="totalDr>=totalCr?'Net debit':'Net credit'" :icon="Scale" tone="default" />
      </div>

      <div class="bg-card border rounded-xl p-4 shadow-sm flex flex-wrap gap-3 items-end">
        <div class="space-y-1 min-w-[18rem] flex-1 max-w-md">
          <label class="text-xs font-medium flex items-center gap-1.5"><Search class="size-3.5" /> Account</label>
          <SearchableSelect v-model="accId" :options="accountOptions" endpoint="/accounts/api/options/accounts" placeholder="Search COA — e.g., 1010 Cash" class="w-full" />
        </div>
        <div class="space-y-1"><label class="text-xs font-medium flex items-center gap-1"><Calendar class="size-3" /> From</label><Input v-model="from" type="date" class="w-36 h-9" /></div>
        <div class="space-y-1"><label class="text-xs font-medium">To</label><Input v-model="to" type="date" class="w-36 h-9" /></div>
        <Button @click="apply" class="h-9">View Ledger</Button>
        <span class="text-xs text-muted-foreground hidden sm:inline ml-auto">BDT · running balance_after from ledger table</span>
      </div>

      <div v-if="account" class="bg-card border rounded-xl p-4 shadow-sm flex flex-wrap items-center justify-between gap-3">
        <div><p class="text-sm font-bold flex items-center gap-2"><span class="font-mono text-xs px-1.5 py-0.5 rounded bg-muted border">{{ account.code }}</span> {{ account.name }}</p><p class="text-xs text-muted-foreground">{{ from || '—' }} → {{ to || 'now' }} · {{ entries.length }} rows</p></div>
        <div class="text-right"><p class="text-xs text-muted-foreground">Selected account</p><p class="text-sm font-mono font-bold">{{ account.code }}</p></div>
      </div>

      <AcctSection v-if="account" title="Postings" :description="`${entries.length} row(s) · ordered by posting_date`">
        <StaticTable flush sticky>
            <thead class="sticky top-0 z-10 bg-muted/90 backdrop-blur text-xs uppercase tracking-widest"><tr><th class="px-3 py-2.5 text-left">Date</th><th class="px-3 py-2.5 text-left">Reference</th><th class="px-3 py-2.5 text-left">Journal</th><th class="px-3 py-2.5 text-right">Debit</th><th class="px-3 py-2.5 text-right">Credit</th><th class="px-3 py-2.5 text-right">Balance after</th></tr></thead>
            <tbody class="divide-y">
              <tr v-for="e in entries" :key="e.id" class="hover:bg-muted/30 transition">
                <td class="px-3 py-2 whitespace-nowrap">{{ e.posting_date }}</td>
                <td class="px-3 py-2 font-mono text-xs">{{ e.reference_no || e.journal?.journal_no || '—' }}</td>
                <td class="px-3 py-2 text-xs text-muted-foreground truncate max-w-[14rem]">{{ e.journal?.narration || '—' }}</td>
                <td class="px-3 py-2 text-right font-mono" :class="Number(e.debit)>0?'font-bold text-emerald-600':''">{{ Number(e.debit)>0?formatCurrency(Number(e.debit)):'—' }}</td>
                <td class="px-3 py-2 text-right font-mono" :class="Number(e.credit)>0?'font-bold text-rose-600':''">{{ Number(e.credit)>0?formatCurrency(Number(e.credit)):'—' }}</td>
                <td class="px-3 py-2 text-right font-mono font-bold">{{ formatCurrency(Number(e.balance_after ?? 0)) }}</td>
              </tr>
              <tr v-if="!entries.length"><td colspan="6" class="px-3 py-10"><AcctEmpty title="No entries in this window" hint="Try widening From/To or pick a different account. Only posted journals appear here." :icon="BookMarked" /></td></tr>
            </tbody>
            <tfoot v-if="entries.length" class="bg-muted/40 font-semibold"><tr><td colspan="3" class="px-3 py-2 text-right">Window total</td><td class="px-3 py-2 text-right font-mono">{{ formatCurrency(totalDr) }}</td><td class="px-3 py-2 text-right font-mono">{{ formatCurrency(totalCr) }}</td><td class="px-3 py-2 text-right font-mono">{{ formatCurrency(Math.abs(totalDr-totalCr)) }} {{ totalDr>=totalCr?'Dr':'Cr' }}</td></tr></tfoot>
          </StaticTable>
      </AcctSection>

      <div v-else class="bg-card border rounded-xl p-8 shadow-sm"><AcctEmpty title="Choose an account to inspect" hint="Search the COA above — e.g., type 'Cash', 'VAT', or a code like 1010. Then set From/To and hit View Ledger." :icon="Search" /></div>
    </PageHeader>
  </AdminLayout>
</template>
