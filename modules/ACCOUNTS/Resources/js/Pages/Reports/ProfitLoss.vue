<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { FileDown, TrendingUp, TrendingDown, Sparkles, Wallet, Receipt, Calendar, Printer, ArrowRight } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import { printDocument } from '@core/Composables/usePrint';
import { useReportPdf } from '@core/Composables/useReportPdf';
import PageHeader from '@core/Components/PageHeader.vue';
import { formatCurrency } from '@core/Utils/format';
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


const { downloadPdf } = useReportPdf();
const pdfPayload = computed(() => {
    const rows = [
        ...incomeRows.value.map((r: any) => [`${r.code} ${r.name}`, 'Income', Number(Math.abs(r.balance)) || 0]),
        ...expenseRows.value.map((r: any) => [`${r.code} ${r.name}`, 'Expense', Number(Math.abs(r.balance)) || 0]),
    ];
    return {
        title: 'Profit & Loss',
        subtitle: `Period ${props.report.from} → ${props.report.to} · Accrual basis`,
        permission: 'accounts.reports.view',
        orientation: 'landscape',
        meta: {
            'From': props.report.from,
            'To': props.report.to,
            'Income': formatCurrency(props.report.income),
            'Expense': formatCurrency(props.report.expense),
            'Net': formatCurrency(Math.abs(net.value)),
            'Result': props.report.is_profit ? 'Net profit' : 'Net loss',
        },
        headings: ['Account', 'Section', 'Amount'],
        rows,
        footer: 'Profit & Loss · Accounts',
    };
});
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'Accounts'},{title:'Profit & Loss'}]">
    <Head title="Profit & Loss" />
    <PageHeader
      title="Profit & Loss"
      :description="`Period ${report.from} → ${report.to} · Accrual basis from posted ledgers. BDT`"
      :icon="TrendingUp"
      :actions="[{ label:'Download PDF', icon: FileDown, variant:'default', onClick: () => downloadPdf(pdfPayload) }, { label:'Print', icon: Printer, variant:'outline', onClick: () => printDocument() }]"
      :stats="[
        { label: 'Income', value: formatCurrency(report.income), sub: `${incomeRows.length} ${incomeRows.length === 1 ? 'account' : 'accounts'}` },
        { label: 'Expense', value: formatCurrency(report.expense), sub: `${expenseRows.length} ${expenseRows.length === 1 ? 'account' : 'accounts'}` },
        { label: report.is_profit ? 'Net profit' : 'Net loss', value: formatCurrency(Math.abs(net)), sub: `${Math.abs(margin).toFixed(1)}% of income` },
        { label: 'Margin', value: `${margin > 0 ? '+' : margin < 0 ? '−' : ''}${Math.abs(margin).toFixed(1)}%`, sub: 'net ÷ income' },
      ]"
    >
      <!--
        There used to be a row of three oversized tiles here — Total Income, Total Expense, Net —
        printing the exact three figures the header strip above already printed, in a second,
        larger typeface. A report that states its headline twice does not look richer; it looks
        like two people assembled it. The strip is the summary. What follows is the statement.
      -->

      <!--
        Income against expense, on one scale, as tokens rather than raw emerald/rose.
        The bar is the only place the two figures are compared proportionally, so it earns its space.
      -->
      <div class="bg-card rounded-xl border p-4">
        <div class="text-muted-foreground mb-2 flex items-center justify-between text-[11px] font-medium tracking-[0.03em]">
          <span>Income</span>
          <span>Expense</span>
        </div>
        <div class="bg-muted flex h-2.5 overflow-hidden rounded-full">
          <div class="bg-success h-full" :style="{ width: report.income > 0 ? (report.income / (report.income + report.expense || 1)) * 100 + '%' : '0%' }" />
          <div class="bg-danger h-full" :style="{ width: report.expense > 0 ? (report.expense / (report.income + report.expense || 1)) * 100 + '%' : '0%' }" />
        </div>
        <div class="mt-2 flex items-center justify-between font-mono text-xs tabular-nums">
          <span class="text-foreground font-semibold">{{ formatCurrency(report.income) }}</span>
          <span class="text-muted-foreground">
            net <span class="font-semibold" :class="report.is_profit ? 'text-success' : 'text-danger'">{{ report.is_profit ? '+' : '−' }}{{ formatCurrency(Math.abs(net)) }}</span>
          </span>
          <span class="text-foreground font-semibold">{{ formatCurrency(report.expense) }}</span>
        </div>
      </div>

      <!-- Controls -->
      <div class="bg-card border rounded-xl p-3 shadow-sm flex flex-wrap items-end gap-3">
        <div class="space-y-1"><label class="text-xs font-medium">From</label><Input v-model="from" type="date" class="w-40 h-9" /></div>
        <div class="space-y-1"><label class="text-xs font-medium">To</label><Input v-model="to" type="date" class="w-40 h-9" /></div>
        <Button size="sm" @click="apply" class="h-9">Apply</Button>
        <span class="text-xs text-muted-foreground ml-auto hidden sm:inline">Trial balance filtered to income/expense · {{ report.details?.length ?? 0 }} lines</span>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
        <AcctSection title="Income" :description="`${incomeRows.length} ${incomeRows.length === 1 ? 'account' : 'accounts'} · credit nature`" :icon="Wallet">
          <div v-if="!incomeRows.length" class="p-4"><AcctEmpty title="No income in period" hint="Income appears when a sales/income posting exists between those dates. Check Journal type SV/RV." :icon="Wallet" /></div>
          <div v-else class="divide-y">
            <div v-for="r in incomeRows" :key="r.account_id" class="flex items-center justify-between gap-3 px-4 py-2.5 hover:bg-muted/30 transition">
              <div class="min-w-0"><p class="text-sm font-medium truncate"><span class="font-mono text-xs text-muted-foreground">{{ r.code }}</span> {{ r.name }}</p><p class="text-xs text-muted-foreground">{{ r.account_type }} · {{ r.balance_side }}</p></div>
              <span class="font-mono text-sm font-bold text-emerald-600 shrink-0">{{ formatCurrency(Math.abs(r.balance)) }}</span>
            </div>
          </div>
          <div v-if="incomeRows.length" class="px-4 py-3 bg-emerald-500/[0.06] border-t-2 border-emerald-500/25 flex justify-between text-sm font-bold">
            <span>Total Income</span><span class="font-mono text-emerald-600">{{ formatCurrency(report.income) }}</span>
          </div>
        </AcctSection>

        <AcctSection title="Expenses" :description="`${expenseRows.length} ${expenseRows.length === 1 ? 'account' : 'accounts'} · debit nature`" :icon="Receipt">
          <div v-if="!expenseRows.length" class="p-4"><AcctEmpty title="No expense in period" hint="Expenses appear once JV/PV postings hit expense heads in the window." :icon="Receipt" /></div>
          <div v-else class="divide-y">
            <div v-for="r in expenseRows" :key="r.account_id" class="flex items-center justify-between gap-3 px-4 py-2.5 hover:bg-muted/30 transition">
              <div class="min-w-0"><p class="text-sm font-medium truncate"><span class="font-mono text-xs text-muted-foreground">{{ r.code }}</span> {{ r.name }}</p><p class="text-xs text-muted-foreground">{{ r.account_type }} · {{ r.balance_side }}</p></div>
              <span class="font-mono text-sm font-bold text-rose-600 shrink-0">{{ formatCurrency(Math.abs(r.balance)) }}</span>
            </div>
          </div>
          <div v-if="expenseRows.length" class="px-4 py-3 bg-rose-500/[0.06] border-t-2 border-rose-500/25 flex justify-between text-sm font-bold">
            <span>Total Expense</span><span class="font-mono text-rose-600">{{ formatCurrency(report.expense) }}</span>
          </div>
        </AcctSection>
      </div>

      <div class="bg-card border rounded-xl p-4 shadow-sm flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-3 text-sm">
          <span class="text-muted-foreground">Formula</span>
          <span class="inline-flex items-center gap-1.5 font-mono text-xs border rounded-full px-3 py-1 bg-muted/50">Income <span class="text-muted-foreground">−</span> Expense <ArrowRight class="size-3" /> <span :class="report.is_profit?'text-emerald-600 font-bold':'text-rose-600 font-bold'">{{ formatCurrency(Math.abs(net)) }} {{ report.is_profit?'profit':'loss' }}</span></span>
        </div>
        <span class="text-xs text-muted-foreground flex items-center gap-1.5"><Calendar class="size-3.5" /> {{ report.from }} → {{ report.to }}</span>
      </div>
    </PageHeader>
  </AdminLayout>
</template>
