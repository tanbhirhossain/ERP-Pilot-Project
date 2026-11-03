<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Timer, Building2, Calendar, Printer, Wallet, TrendingDown } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import PageHeader from '../../../../../CORE/Resources/js/Components/PageHeader.vue';
import { formatCurrency } from '../../../../../CORE/Resources/js/Utils/format';
import AcctKpi from '../../Components/AcctKpi.vue';
import AcctSection from '../../Components/AcctSection.vue';
import AcctEmpty from '../../Components/AcctEmpty.vue';
import { ref } from 'vue';
const props = defineProps<{ report:any; asOf:string }>();
const asOf = ref(new URLSearchParams(window.location.search).get('as_of') || props.asOf || new Date().toISOString().slice(0,10));
function apply(){ router.get(route('accounts.reports.depreciation'), { as_of: asOf.value }, { preserveState:true }); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'Accounts'},{title:'Depreciation'}]">
    <Head title="Depreciation Schedule" />
    <PageHeader
      title="Depreciation Schedule"
      description="Straight-line 10% p.a. fallback until Fixed Asset register is filled. Replace rate per asset with FA module."
      :icon="Timer"
      :actions="[{ label:'Print', icon: Printer, variant:'outline', onClick: ()=>window.print() }]"
      :stats="[
        { label:'Assets tracked', value: String(report.rows.length), sub: '15xx + Asset-tagged' },
        { label:'Total monthly charge', value: formatCurrency(report.total_monthly), sub: 'At 10% p.a.' },
        { label:'As of', value: asOf, sub: 'Book value snapshot' },
        { label:'Method', value: 'SLM 10%', sub: 'Falling-balance option later' },
      ]"
    >
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
        <AcctKpi label="Depreciable assets" :value="String(report.rows.length)" sub="Code 15xx · asset accounts with balance" :icon="Building2" tone="info" />
        <AcctKpi label="Monthly depreciation" :value="formatCurrency(report.total_monthly)" sub="At 10% per year (monthly = BV × 10%/12)" :icon="TrendingDown" tone="warning" />
        <AcctKpi label="Yearly (proj.)" :value="formatCurrency(report.total_monthly*12)" sub="If no additions/disposals" :icon="Wallet" tone="default" />
      </div>

      <div class="bg-card border rounded-2xl p-3 shadow-sm flex flex-wrap items-end gap-3">
        <div class="space-y-1"><label class="text-xs font-medium">As of</label><Input v-model="asOf" type="date" class="w-40 h-9" /></div>
        <Button size="sm" @click="apply" class="h-9">Apply</Button>
        <span class="ml-auto text-xs text-muted-foreground hidden sm:inline flex items-center gap-1.5"><Calendar class="size-3.5" /> Book value = ledger balance to as_of</span>
      </div>

      <AcctSection title="Schedule" :description="`As of ${asOf} · ${report.rows.length} asset(s)`">
        <div v-if="!report.rows.length" class="p-6"><AcctEmpty title="No depreciable assets with balance" hint="Post a JV/BV that debits an asset account in the 15xx range (e.g., Machinery, Vehicles). Balance must be > 0 as of the chosen date." :icon="Building2" /></div>
        <div v-else class="overflow-auto">
          <table class="w-full text-sm">
            <thead class="sticky top-0 bg-muted/90 backdrop-blur text-xs uppercase tracking-widest"><tr><th class="px-4 py-2.5 text-left">Code</th><th class="px-4 py-2.5 text-left">Asset</th><th class="px-4 py-2.5 text-center">Rate</th><th class="px-4 py-2.5 text-right">Book value</th><th class="px-4 py-2.5 text-right">Monthly charge</th><th class="px-4 py-2.5 text-right">Yearly</th></tr></thead>
            <tbody class="divide-y">
              <tr v-for="r in report.rows" :key="r.account_id" class="hover:bg-muted/30 transition">
                <td class="px-4 py-2.5"><span class="font-mono text-xs font-bold px-1.5 py-0.5 rounded bg-muted border">{{ r.code }}</span></td>
                <td class="px-4 py-2.5 font-medium">{{ r.name }}</td>
                <td class="px-4 py-2.5 text-center"><span class="text-xs px-2 py-0.5 rounded-full bg-amber-500/10 text-amber-700 border border-amber-500/20">{{ (r.annual_rate*100).toFixed(0) }}% p.a.</span></td>
                <td class="px-4 py-2.5 text-right font-mono font-semibold">{{ formatCurrency(r.book_value) }}</td>
                <td class="px-4 py-2.5 text-right font-mono font-bold text-amber-600">{{ formatCurrency(r.monthly_charge) }}</td>
                <td class="px-4 py-2.5 text-right font-mono text-muted-foreground">{{ formatCurrency(r.monthly_charge*12) }}</td>
              </tr>
            </tbody>
            <tfoot class="bg-muted/40 font-bold"><tr><td colspan="3" class="px-4 py-3 text-right">Total monthly</td><td class="px-4 py-3"></td><td class="px-4 py-3 text-right font-mono text-amber-700">{{ formatCurrency(report.total_monthly) }}</td><td class="px-4 py-3 text-right font-mono">{{ formatCurrency(report.total_monthly*12) }}</td></tr></tfoot>
          </table>
        </div>
      </AcctSection>

      <div class="bg-amber-500/[0.06] border border-amber-500/20 rounded-2xl p-4">
        <p class="text-sm font-semibold flex items-center gap-2"><Timer class="size-4 text-amber-600" /> Next step — Fixed Asset register</p>
        <p class="text-xs text-muted-foreground mt-1">Create FA master (asset, purchase date, life, residual, method) → this page will switch from the 10% heuristic to per-asset computed depreciation and generate the monthly JV automatically (Dr Depreciation, Cr Accumulated Depreciation).</p>
      </div>
    </PageHeader>
  </AdminLayout>
</template>
