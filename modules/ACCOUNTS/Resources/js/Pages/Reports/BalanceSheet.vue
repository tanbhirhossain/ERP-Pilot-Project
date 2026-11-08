<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Scale, ShieldCheck, AlertTriangle, Wallet, Building2, Landmark, PiggyBank, Calendar, Printer } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import { printDocument } from '../../../../../CORE/Resources/js/Composables/usePrint';
import PageHeader from '../../../../../CORE/Resources/js/Components/PageHeader.vue';
import { formatCurrency } from '../../../../../CORE/Resources/js/Utils/format';
import AcctKpi from '../../Components/AcctKpi.vue';
import AcctSection from '../../Components/AcctSection.vue';
import AcctEmpty from '../../Components/AcctEmpty.vue';
import { ref } from 'vue';

const props = defineProps<{ report:any }>();
const asOf = ref(new URLSearchParams(window.location.search).get('as_of') || props.report.as_of || new Date().toISOString().slice(0,10));
function apply(){ router.get(route('accounts.reports.bs'), { as_of: asOf.value }, { preserveState:true }); }

const assets = props.report.assets ?? 0;
const liabilities = props.report.liabilities ?? 0;
const equityCore = (props.report.equity ?? 0);
const retained = props.report.retained_earnings ?? 0;
const totalEquity = props.report.total_equity ?? (equityCore + retained);
const rightSide = liabilities + totalEquity;
const diff = Math.abs(assets - rightSide);
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'Accounts'},{title:'Balance Sheet'}]">
    <Head title="Balance Sheet" />
    <PageHeader
      title="Balance Sheet"
      description="Financial position — Assets = Liabilities + Equity (incl. retained earnings to date). Posting-date ledger."
      :icon="Scale"
      :actions="[{ label:'Print', icon: Printer, variant:'outline', onClick: () => printDocument() }]"
      :stats="[
        { label:'Assets', value: formatCurrency(assets), sub: 'Dr nature' },
        { label:'Liabilities', value: formatCurrency(liabilities), sub: 'Cr nature' },
        { label:'Equity (ex-RE)', value: formatCurrency(equityCore) },
        { label: report.balanced ? 'Balanced' : 'Imbalance', value: report.balanced ? '✓ A = L + E' : formatCurrency(diff), sub: report.balanced ? 'Ledger is in balance' : 'Needs review' },
      ]"
    >
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
        <AcctKpi label="Total Assets" :value="formatCurrency(assets)" sub="Debit-side accounts" :icon="Wallet" tone="info" />
        <AcctKpi label="Total Liabilities" :value="formatCurrency(liabilities)" sub="Credit-side" :icon="Building2" tone="warning" />
        <AcctKpi :label="`Equity (${formatCurrency(retained)} RE)`" :value="formatCurrency(totalEquity)" sub="Capital + retained profit" :icon="Landmark" tone="success" />
        <div class="rounded-xl border p-4 shadow-sm flex flex-col justify-center" :class="report.balanced ? 'bg-emerald-500/[0.06] border-emerald-500/20' : 'bg-rose-500/[0.06] border-rose-500/20'">
          <p class="text-[11px] font-semibold tracking-widest uppercase" :class="report.balanced?'text-emerald-600':'text-rose-600'">{{ report.balanced ? 'Equation holds' : 'Out of balance' }}</p>
          <p class="text-sm font-mono font-bold mt-1">A <span class="text-muted-foreground">=</span> L <span class="text-muted-foreground">+</span> E</p>
          <p class="text-xs text-muted-foreground mt-1 truncate">{{ formatCurrency(assets) }} = {{ formatCurrency(liabilities) }} + {{ formatCurrency(totalEquity) }}</p>
        </div>
      </div>

      <!-- Controls -->
      <div class="bg-card border rounded-xl p-3 shadow-sm flex flex-wrap items-end gap-3">
        <div class="space-y-1">
          <label class="text-xs font-medium">As of</label>
          <Input v-model="asOf" type="date" class="w-40 h-9" />
        </div>
        <Button size="sm" @click="apply" class="h-9">Apply</Button>
        <div class="ml-auto flex items-center gap-2 text-xs">
          <span class="px-2.5 py-1 rounded-full border text-xs font-medium flex items-center gap-1.5" :class="report.balanced ? 'bg-emerald-500/10 text-emerald-700 border-emerald-500/20' : 'bg-rose-500/10 text-rose-700 border-rose-500/20'">
            <ShieldCheck v-if="report.balanced" class="size-3.5" /><AlertTriangle v-else class="size-3.5" /> {{ report.balanced ? 'Audit: balanced' : 'Audit: imbalance ' + formatCurrency(diff) }}
          </span>
          <span class="text-muted-foreground hidden sm:inline">BDT · {{ report.details?.length ?? 0 }} accounts with movement</span>
        </div>
      </div>

      <!-- Equation visual -->
      <div class="grid grid-cols-1 lg:grid-cols-[1fr_auto_1fr] gap-3 items-stretch">
        <AcctSection title="Left — Assets" description="Debit nature · economic resources">
          <div class="divide-y">
            <div v-for="r in report.details.filter((x:any)=>x.account_type==='asset')" :key="r.account_id" class="flex items-center justify-between gap-3 px-4 py-2.5 hover:bg-muted/30 transition">
              <div class="min-w-0"><p class="text-sm font-medium truncate"><span class="font-mono text-xs text-muted-foreground">{{ r.code }}</span> {{ r.name }}</p><p class="text-xs text-muted-foreground">{{ r.account_type }} · {{ r.nature }}</p></div>
              <span class="font-mono text-sm font-semibold shrink-0">{{ formatCurrency(Math.abs(r.balance)) }}</span>
            </div>
            <div v-if="!report.details.filter((x:any)=>x.account_type==='asset').length" class="px-4 py-8"><AcctEmpty title="No asset movement" hint="Post a journal against an asset account — e.g., cash, bank, receivables — to see it here." :icon="Wallet" /></div>
          </div>
        </AcctSection>
        <div class="hidden lg:grid place-items-center"><div class="size-10 rounded-full bg-primary text-primary-foreground grid place-items-center font-bold">=</div></div>
        <AcctSection title="Right — Liabilities + Equity" :description="`Liabilities ${formatCurrency(liabilities)} · Equity ${formatCurrency(totalEquity)} (RE ${formatCurrency(retained)})`">
          <div class="divide-y">
            <div v-for="r in report.details.filter((x:any)=>['liability','equity'].includes(x.account_type))" :key="r.account_id" class="flex items-center justify-between gap-3 px-4 py-2.5 hover:bg-muted/30 transition">
              <div class="min-w-0"><p class="text-sm font-medium truncate"><span class="font-mono text-xs text-muted-foreground">{{ r.code }}</span> {{ r.name }}</p><p class="text-xs text-muted-foreground">{{ r.account_type }} · {{ r.nature }}</p></div>
              <span class="font-mono text-sm font-semibold shrink-0">{{ formatCurrency(Math.abs(r.balance)) }}</span>
            </div>
            <div class="bg-muted/20 px-4 py-3 flex justify-between text-sm font-semibold border-t">
              <span>+ Retained earnings (P&L to {{ report.as_of }})</span>
              <span class="font-mono" :class="retained>=0?'text-emerald-600':'text-rose-600'">{{ formatCurrency(retained) }}</span>
            </div>
            <div v-if="!report.details.filter((x:any)=>['liability','equity'].includes(x.account_type)).length && Math.abs(retained)<0.01" class="px-4 py-8"><AcctEmpty title="No liability / equity movement" hint="Equity appears once opening capital or retained profit is posted." :icon="PiggyBank" /></div>
          </div>
        </AcctSection>
      </div>

      <p class="text-xs text-muted-foreground flex items-center gap-2"><Calendar class="size-3.5" /> As of {{ report.as_of }} · Full ledger grouping by posting_date ≤ as_of. Use General Ledger to drill into any account.</p>
    </PageHeader>
  </AdminLayout>
</template>
