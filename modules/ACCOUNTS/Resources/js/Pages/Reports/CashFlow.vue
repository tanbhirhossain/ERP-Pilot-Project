<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { TrendingUp, Building2, Wallet, Calendar, Printer, ArrowRight, Droplets } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import { printDocument } from '@core/Composables/usePrint';
import PageHeader from '@core/Components/PageHeader.vue';
import { formatCurrency } from '@core/Utils/format';
import AcctKpi from '../../Components/AcctKpi.vue';
import AcctSection from '../../Components/AcctSection.vue';
import { ref } from 'vue';
import StaticTable from '@core/Components/StaticTable.vue';
const props = defineProps<{ report:any; from:string; to:string }>();
const from = ref(new URLSearchParams(window.location.search).get('from') || props.from || new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().slice(0,10));
const to = ref(new URLSearchParams(window.location.search).get('to') || props.to || new Date().toISOString().slice(0,10));
function apply(){ router.get(route('accounts.reports.cash-flow'), { from: from.value, to: to.value }, { preserveState:true }); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'Accounts'},{title:'Cash Flow'}]">
    <Head title="Cash Flow" />
    <PageHeader
      title="Cash Flow Statement"
      description="Indirect method — net profit adjusted by non-cash buckets (investing/financing derived from code prefixes 15/16 & 21/31). Wire to bank statements for direct method."
      :icon="Droplets"
      :actions="[{ label:'Print', icon: Printer, variant:'outline', onClick: () => printDocument() }]"
      :stats="[
        { label:'Operating', value: formatCurrency(report.operating), sub: 'P&L to date' },
        { label:'Investing', value: formatCurrency(report.investing), sub: 'FA proxy' },
        { label:'Financing', value: formatCurrency(report.financing), sub: 'Loans/equity proxy' },
        { label:'Net change', value: formatCurrency(report.net_change), sub: `${from} → ${to}` },
      ]"
    >
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
        <AcctKpi label="Operating" :value="formatCurrency(report.operating)" sub="Net profit (income − expense)" :icon="TrendingUp" :tone="report.operating>=0?'success':'danger'" />
        <AcctKpi label="Investing" :value="formatCurrency(report.investing)" sub="Assets 15xx/16xx" :icon="Building2" tone="info" />
        <AcctKpi label="Financing" :value="formatCurrency(report.financing)" sub="Liab/Eq 21xx/31xx" :icon="Wallet" tone="warning" />
      </div>

      <div class="bg-card border rounded-xl p-4 shadow-sm">
        <div class="flex items-center justify-between gap-3 flex-wrap">
          <div class="flex items-center gap-2 text-sm font-mono border rounded-full px-3 py-1.5 bg-muted/40">
            <span class="text-emerald-600 font-bold">{{ formatCurrency(report.operating) }}</span> <span class="text-muted-foreground">+</span> <span class="text-sky-600 font-bold">{{ formatCurrency(report.investing) }}</span> <span class="text-muted-foreground">+</span> <span class="text-amber-600 font-bold">{{ formatCurrency(report.financing) }}</span> <ArrowRight class="size-3.5" /> <span class="font-bold">{{ formatCurrency(report.net_change) }}</span>
          </div>
          <span class="text-xs text-muted-foreground flex items-center gap-1.5"><Calendar class="size-3.5" /> {{ from }} → {{ to }}</span>
        </div>
        <div class="mt-3 h-3 rounded-full bg-muted overflow-hidden flex">
          <div class="h-full bg-emerald-500" :style="{ width: Math.abs(report.operating)/(Math.abs(report.operating)+Math.abs(report.investing)+Math.abs(report.financing)||1)*100 + '%' }"></div>
          <div class="h-full bg-sky-500" :style="{ width: Math.abs(report.investing)/(Math.abs(report.operating)+Math.abs(report.investing)+Math.abs(report.financing)||1)*100 + '%' }"></div>
          <div class="h-full bg-amber-500" :style="{ width: Math.abs(report.financing)/(Math.abs(report.operating)+Math.abs(report.investing)+Math.abs(report.financing)||1)*100 + '%' }"></div>
        </div>
      </div>

      <div class="bg-card border rounded-xl p-3 shadow-sm flex flex-wrap items-end gap-3">
        <div class="space-y-1"><label class="text-xs font-medium">From</label><Input v-model="from" type="date" class="w-40 h-9" /></div>
        <div class="space-y-1"><label class="text-xs font-medium">To</label><Input v-model="to" type="date" class="w-40 h-9" /></div>
        <Button size="sm" @click="apply" class="h-9">Apply</Button>
        <span class="text-xs text-muted-foreground ml-auto hidden sm:inline">Upgrade buckets when FA register & loan schedule are filled.</span>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-3 gap-3">
        <AcctSection title="Operating" description="Starts from P&L net">
          <div class="p-4 space-y-3 text-sm">
            <div class="flex justify-between"><span class="text-muted-foreground">Net profit (P&L)</span><span class="font-mono font-bold" :class="report.operating>=0?'text-emerald-600':'text-rose-600'">{{ formatCurrency(report.operating) }}</span></div>
            <div class="border-t pt-3 flex justify-between font-bold"><span>Operating cash</span><span class="font-mono">{{ formatCurrency(report.operating) }}</span></div>
          </div>
        </AcctSection>
        <AcctSection title="Investing" description="Proxy via asset codes 15/16">
          <div class="p-4 space-y-3 text-sm">
            <div class="flex justify-between"><span class="text-muted-foreground">FA movements</span><span class="font-mono font-bold text-sky-600">{{ formatCurrency(report.investing) }}</span></div>
            <p class="text-xs text-muted-foreground">Replace with Fixed Asset register (additions/disposals) for exact figure.</p>
            <div class="border-t pt-3 flex justify-between font-bold"><span>Investing cash</span><span class="font-mono">{{ formatCurrency(report.investing) }}</span></div>
          </div>
        </AcctSection>
        <AcctSection title="Financing" description="Proxy via liability 21/31">
          <div class="p-4 space-y-3 text-sm">
            <div class="flex justify-between"><span class="text-muted-foreground">Loans / equity</span><span class="font-mono font-bold text-amber-600">{{ formatCurrency(report.financing) }}</span></div>
            <p class="text-xs text-muted-foreground">Wire loan disbursements/repayments + capital injections here.</p>
            <div class="border-t pt-3 flex justify-between font-bold"><span>Financing cash</span><span class="font-mono">{{ formatCurrency(report.financing) }}</span></div>
          </div>
        </AcctSection>
      </div>

      <AcctSection title="Underlying trial (as of to)" description="Use to audit which heads contribute">
        <StaticTable
            flush
            sticky
            :empty="!report?.details?.length"
            empty-title="No cash movement in this period"
            empty-description="Try a wider date range."
        >
            <thead class="sticky top-0 bg-muted/90 backdrop-blur text-xs uppercase tracking-widest"><tr><th class="px-3 py-2 text-left">Account</th><th class="px-3 py-2 text-right">Balance</th></tr></thead>
            <tbody class="divide-y"><tr v-for="r in report.details" :key="r.account_id" class="hover:bg-muted/30"><td class="px-3 py-1.5"><span class="font-mono text-xs text-muted-foreground">{{ r.code }}</span> {{ r.name }} <span class="text-xs text-muted-foreground">({{ r.account_type }})</span></td><td class="px-3 py-1.5 text-right font-mono font-semibold">{{ formatCurrency(Math.abs(r.balance)) }}</td></tr></tbody>
          </StaticTable>
      </AcctSection>
    </PageHeader>
  </AdminLayout>
</template>
