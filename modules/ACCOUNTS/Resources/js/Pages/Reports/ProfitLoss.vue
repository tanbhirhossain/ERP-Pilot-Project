<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { TrendingUp, TrendingDown, Sparkles, Wallet, Receipt, Calendar, Printer, ArrowRight } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import PageHeader from '../../../../../CORE/Resources/js/Components/PageHeader.vue';
import { formatCurrency } from '../../../../../CORE/Resources/js/Utils/format';
import AcctKpi from '../../Components/AcctKpi.vue';
import AcctSection from '../../Components/AcctSection.vue';
import AcctEmpty from '../../Components/AcctEmpty.vue';
import { ref, computed } from 'vue';

const props = defineProps<{ report:any }>();
const from = ref(new URLSearchParams(window.location.search).get('from') || props.report.from || new Date().toISOString().slice(0,10));
const to = ref(new URLSearchParams(window.location.search).get('to') || props.report.to || new Date().toISOString().slice(0,10));
function apply(){ router.get(route('accounts.reports.pl'), { from: from.value, to: to.value }, { preserveState:true }); }

const incomeRows = computed(()=> (props.report.details ?? []).filter((r:any)=> r.account_type==='income'));
const expenseRows = computed(()=> (props.report.details ?? []).filter((r:any)=> r.account_type==='expense'));
const net = computed(()=> props.report.net_profit ?? 0);
const margin = computed(()=> props.report.income > 0 ? (net.value/props.report.income)*100 : 0);
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'Accounts'},{title:'Profit & Loss'}]">
    <Head title="Profit & Loss" />
    <PageHeader
      title="Profit & Loss"
      :description="`Period ${report.from} → ${report.to} · Accrual basis from posted ledgers. BDT`"
      :icon="TrendingUp"
      :actions="[{ label:'Print', icon: Printer, variant:'outline', onClick: ()=>window.print() }]"
      :stats="[
        { label:'Income', value: formatCurrency(report.income), sub: incomeRows.length + ' heads' },
        { label:'Expense', value: formatCurrency(report.expense), sub: expenseRows.length + ' heads' },
        { label: report.is_profit ? 'Net Profit' : 'Net Loss', value: formatCurrency(Math.abs(net)), sub: (margin>=0?'+':'')+margin.toFixed(1)+'% margin' },
        { label:'Period', value: `${from} → ${to}`, sub: report.is_profit?'Profit':'Loss' },
      ]"
    >
      <!-- Hero KPI -->
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
        <AcctKpi label="Total Income" :value="formatCurrency(report.income)" :sub="`${incomeRows.length} income heads`" :icon="Wallet" tone="success" />
        <AcctKpi label="Total Expense" :value="formatCurrency(report.expense)" :sub="`${expenseRows.length} expense heads`" :icon="Receipt" tone="danger" />
        <div class="rounded-2xl border p-4 shadow-sm flex flex-col justify-center" :class="report.is_profit ? 'bg-emerald-500/[0.06] border-emerald-500/20' : 'bg-rose-500/[0.06] border-rose-500/20'">
          <p class="text-[11px] font-semibold tracking-widest uppercase flex items-center gap-1.5" :class="report.is_profit?'text-emerald-600':'text-rose-600'"><Sparkles class="size-3.5" /> {{ report.is_profit ? 'Net Profit' : 'Net Loss' }}</p>
          <p class="text-2xl font-bold font-mono mt-1" :class="report.is_profit?'text-emerald-600':'text-rose-600'">{{ formatCurrency(Math.abs(net)) }}</p>
          <p class="text-xs text-muted-foreground mt-1 flex items-center gap-2">
            <span class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs font-medium" :class="report.is_profit?'border-emerald-500/20 text-emerald-700 bg-emerald-500/10':'border-rose-500/20 text-rose-700 bg-rose-500/10'">
              <TrendingUp v-if="report.is_profit" class="size-3" /> <TrendingDown v-else class="size-3" /> {{ margin.toFixed(1) }}% margin
            </span>
            <span class="hidden sm:inline">Income − Expense</span>
          </p>
        </div>
      </div>

      <!-- Waterfall bar -->
      <div class="bg-card border rounded-2xl p-4 shadow-sm">
        <div class="flex items-center justify-between text-xs text-muted-foreground mb-2"><span>Income</span><span>Expense → Net</span></div>
        <div class="h-3 rounded-full bg-muted overflow-hidden flex">
          <div class="h-full bg-emerald-500 transition-all" :style="{ width: report.income>0 ? (report.income/(report.income+report.expense || 1)*100)+'%' : '0%' }"></div>
          <div class="h-full bg-rose-500 transition-all" :style="{ width: report.expense>0 ? (report.expense/(report.income+report.expense || 1)*100)+'%' : '0%' }"></div>
        </div>
        <div class="flex justify-between text-xs font-mono mt-2"><span class="text-emerald-600 font-semibold">{{ formatCurrency(report.income) }}</span><span class="font-bold" :class="report.is_profit?'text-emerald-600':'text-rose-600'">{{ report.is_profit?'+':'' }}{{ formatCurrency(net) }}</span><span class="text-rose-600 font-semibold">{{ formatCurrency(report.expense) }}</span></div>
      </div>

      <!-- Controls -->
      <div class="bg-card border rounded-2xl p-3 shadow-sm flex flex-wrap items-end gap-3">
        <div class="space-y-1"><label class="text-xs font-medium">From</label><Input v-model="from" type="date" class="w-40 h-9" /></div>
        <div class="space-y-1"><label class="text-xs font-medium">To</label><Input v-model="to" type="date" class="w-40 h-9" /></div>
        <Button size="sm" @click="apply" class="h-9">Apply</Button>
        <span class="text-xs text-muted-foreground ml-auto hidden sm:inline">Trial balance filtered to income/expense · {{ report.details?.length ?? 0 }} lines</span>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
        <AcctSection title="Income" :description="`${incomeRows.length} accounts · credit nature`" :icon="Wallet">
          <div v-if="!incomeRows.length" class="p-4"><AcctEmpty title="No income in period" hint="Income appears when a sales/income posting exists between those dates. Check Journal type SV/RV." :icon="Wallet" /></div>
          <div v-else class="divide-y">
            <div v-for="r in incomeRows" :key="r.account_id" class="flex items-center justify-between gap-3 px-4 py-2.5 hover:bg-muted/30 transition">
              <div class="min-w-0"><p class="text-sm font-medium truncate"><span class="font-mono text-xs text-muted-foreground">{{ r.code }}</span> {{ r.name }}</p><p class="text-xs text-muted-foreground">{{ r.account_type }} · {{ r.balance_side }}</p></div>
              <span class="font-mono text-sm font-bold text-emerald-600 shrink-0">{{ formatCurrency(Math.abs(r.balance)) }}</span>
            </div>
          </div>
          <div v-if="incomeRows.length" class="px-4 py-3 bg-emerald-500/[0.06] border-t flex justify-between text-sm font-bold">
            <span>Total Income</span><span class="font-mono text-emerald-600">{{ formatCurrency(report.income) }}</span>
          </div>
        </AcctSection>

        <AcctSection title="Expenses" :description="`${expenseRows.length} accounts · debit nature`" :icon="Receipt">
          <div v-if="!expenseRows.length" class="p-4"><AcctEmpty title="No expense in period" hint="Expenses appear once JV/PV postings hit expense heads in the window." :icon="Receipt" /></div>
          <div v-else class="divide-y">
            <div v-for="r in expenseRows" :key="r.account_id" class="flex items-center justify-between gap-3 px-4 py-2.5 hover:bg-muted/30 transition">
              <div class="min-w-0"><p class="text-sm font-medium truncate"><span class="font-mono text-xs text-muted-foreground">{{ r.code }}</span> {{ r.name }}</p><p class="text-xs text-muted-foreground">{{ r.account_type }} · {{ r.balance_side }}</p></div>
              <span class="font-mono text-sm font-bold text-rose-600 shrink-0">{{ formatCurrency(Math.abs(r.balance)) }}</span>
            </div>
          </div>
          <div v-if="expenseRows.length" class="px-4 py-3 bg-rose-500/[0.06] border-t flex justify-between text-sm font-bold">
            <span>Total Expense</span><span class="font-mono text-rose-600">{{ formatCurrency(report.expense) }}</span>
          </div>
        </AcctSection>
      </div>

      <div class="bg-card border rounded-2xl p-4 shadow-sm flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-3 text-sm">
          <span class="text-muted-foreground">Formula</span>
          <span class="inline-flex items-center gap-1.5 font-mono text-xs border rounded-full px-3 py-1 bg-muted/50">Income <span class="text-muted-foreground">−</span> Expense <ArrowRight class="size-3" /> <span :class="report.is_profit?'text-emerald-600 font-bold':'text-rose-600 font-bold'">{{ formatCurrency(Math.abs(net)) }} {{ report.is_profit?'profit':'loss' }}</span></span>
        </div>
        <span class="text-xs text-muted-foreground flex items-center gap-1.5"><Calendar class="size-3.5" /> {{ report.from }} → {{ report.to }}</span>
      </div>
    </PageHeader>
  </AdminLayout>
</template>
