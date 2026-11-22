<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { FileDown, TrendingUp, Building2, Wallet, Calendar, Printer, ArrowRight, Droplets } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import { printDocument } from '@core/Composables/usePrint';
import { useReportPdf } from '@core/Composables/useReportPdf';
import PageHeader from '@core/Components/PageHeader.vue';
import { formatCurrency, formatDate } from '@core/Utils/format';
import AcctKpi from '../../Components/AcctKpi.vue';
import AcctSection from '../../Components/AcctSection.vue';
import { computed, ref } from 'vue';
import StaticTable from '@core/Components/StaticTable.vue';
const props = defineProps<{ report:any; from:string; to:string }>();
const from = ref(new URLSearchParams(window.location.search).get('from') || props.from || new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().slice(0,10));
const to = ref(new URLSearchParams(window.location.search).get('to') || props.to || new Date().toISOString().slice(0,10));
function apply(){ router.get(route('accounts.reports.cash-flow'), { from: from.value, to: to.value }, { preserveState:true }); }

/** The statement is only worth reading if it reconciles; say so rather than imply it. */
const reconciles = computed(() =>
    Math.abs(Number(props.report.opening_cash) + Number(props.report.net_change) - Number(props.report.closing_cash)) < 0.01,
);

function bucketTone(bucket: string): string {
    if (bucket === 'operating') return 'bg-emerald-100 text-emerald-800';
    if (bucket === 'investing') return 'bg-sky-100 text-sky-800';
    if (bucket === 'financing') return 'bg-amber-100 text-amber-800';

    return 'bg-slate-100 text-slate-700';
}


const { downloadPdf } = useReportPdf();
const pdfPayload = computed(() => ({
    title: 'Cash Flow Statement',
    subtitle: `${from.value} → ${to.value} · all cash & bank movements`,
    permission: 'accounts.reports.view',
    orientation: 'landscape',
    meta: {
        'From': from.value,
        'To': to.value,
        'Operating': formatCurrency(props.report.operating),
        'Investing': formatCurrency(props.report.investing),
        'Financing': formatCurrency(props.report.financing),
        'Net change': formatCurrency(props.report.net_change),
        'Closing cash': formatCurrency(props.report.closing_cash),
    },
    headings: ['Journal', 'Date', 'Reference', 'Against', 'Bucket', 'Cash in / (out)'],
    rows: (props.report.details ?? []).map((r: any) => [r.journal_no, r.date, r.reference || '—', `${r.counter_code} ${r.counter_account}`, r.bucket, Number(r.amount) || 0]),
    footer: 'Cash Flow · Accounts',
}));
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'Accounts'},{title:'Cash Flow'}]">
    <Head title="Cash Flow" />
    <PageHeader
      title="Cash Flow Statement"
      description="Every posted journal that moved a cash or bank account, classified by what the money moved against."
      :icon="Droplets"
      :actions="[{ label:'Download PDF', icon: FileDown, variant:'default', onClick: () => downloadPdf(pdfPayload) }, { label:'Print', icon: Printer, variant:'outline', onClick: () => printDocument() }]"
      :stats="[
        { label:'Operating', value: formatCurrency(report.operating), sub: 'trading' },
        { label:'Investing', value: formatCurrency(report.investing), sub: 'fixed assets' },
        { label:'Financing', value: formatCurrency(report.financing), sub: 'loans & owners' },
        { label:'Net change', value: formatCurrency(report.net_change), sub: `${from} → ${to}` },
      ]"
    >
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
        <AcctKpi label="Operating" :value="formatCurrency(report.operating)" sub="trading — sales, stock, suppliers, wages, VAT" :icon="TrendingUp" :tone="report.operating>=0?'success':'danger'" />
        <AcctKpi label="Investing" :value="formatCurrency(report.investing)" sub="fixed assets bought and sold" :icon="Building2" tone="info" />
        <AcctKpi label="Financing" :value="formatCurrency(report.financing)" sub="loans and owners' capital" :icon="Wallet" tone="warning" />
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
        <span class="text-xs text-muted-foreground ml-auto hidden sm:inline">Classified from the journal itself — no proxies.</span>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-3 gap-3">
        <AcctSection title="Operating" description="Trading — what the shop did">
          <div class="p-4 space-y-2 text-sm">
            <div class="flex justify-between font-bold"><span>Operating cash</span><span class="font-mono" :class="report.operating>=0?'text-emerald-600':'text-rose-600'">{{ formatCurrency(report.operating) }}</span></div>
            <p class="text-xs text-muted-foreground">Sales collected, suppliers paid, wages and rent paid, VAT settled. Everything that touches the profit-and-loss accounts or the working-capital accounts.</p>
          </div>
        </AcctSection>
        <AcctSection title="Investing" description="Fixed assets">
          <div class="p-4 space-y-2 text-sm">
            <div class="flex justify-between font-bold"><span>Investing cash</span><span class="font-mono text-sky-600">{{ formatCurrency(report.investing) }}</span></div>
            <p class="text-xs text-muted-foreground">Money spent on long-lived assets and money received from selling them.</p>
          </div>
        </AcctSection>
        <AcctSection title="Financing" description="Loans and owners">
          <div class="p-4 space-y-2 text-sm">
            <div class="flex justify-between font-bold"><span>Financing cash</span><span class="font-mono text-amber-600">{{ formatCurrency(report.financing) }}</span></div>
            <p class="text-xs text-muted-foreground">Long-term borrowing and repayment, and capital put in or taken out by the owners.</p>
          </div>
        </AcctSection>
      </div>

      <AcctSection title="Reconciliation" description="Opening plus movement must equal closing, or the statement is wrong">
        <div class="p-4 grid grid-cols-1 sm:grid-cols-3 gap-3 text-sm">
          <div class="flex justify-between sm:block"><span class="text-muted-foreground">Opening cash</span><span class="block font-mono font-bold">{{ formatCurrency(report.opening_cash) }}</span></div>
          <div class="flex justify-between sm:block"><span class="text-muted-foreground">Movement</span><span class="block font-mono font-bold">{{ formatCurrency(report.net_change) }}</span></div>
          <div class="flex justify-between sm:block"><span class="text-muted-foreground">Closing cash</span><span class="block font-mono font-bold">{{ formatCurrency(report.closing_cash) }}</span></div>
        </div>
        <div class="px-4 pb-4">
          <div class="rounded-lg border px-3 py-2 text-xs" :class="reconciles ? 'border-emerald-300 bg-emerald-50 text-emerald-800' : 'border-rose-300 bg-rose-50 text-rose-800'">
            {{ reconciles ? 'Opening + movement agrees with closing cash in the ledger.' : 'Opening + movement does NOT agree with closing cash — the statement is incomplete.' }}
          </div>
        </div>
      </AcctSection>

      <AcctSection title="Every movement" description="Each posted journal that touched a cash or bank account, and what it moved against">
        <StaticTable
            flush
            sticky
            :empty="!report?.details?.length"
            empty-title="No cash movement in this period"
            empty-description="Try a wider date range."
        >
            <thead class="sticky top-0 bg-muted/90 backdrop-blur text-xs uppercase tracking-widest"><tr><th class="px-3 py-2 text-left">Journal</th><th class="px-3 py-2 text-left">Date</th><th class="px-3 py-2 text-left">Reference</th><th class="px-3 py-2 text-left">Against</th><th class="px-3 py-2 text-left">Bucket</th><th class="px-3 py-2 text-right">Cash in / (out)</th></tr></thead>
            <tbody class="divide-y">
              <tr v-for="(r,i) in report.details" :key="i" class="hover:bg-muted/30">
                <td class="px-3 py-1.5 font-mono text-xs">{{ r.journal_no }}</td>
                <td class="px-3 py-1.5 text-xs">{{ formatDate(r.date) }}</td>
                <td class="px-3 py-1.5 text-xs">{{ r.reference || '—' }}</td>
                <td class="px-3 py-1.5"><span class="font-mono text-xs text-muted-foreground">{{ r.counter_code }}</span> {{ r.counter_account }}</td>
                <td class="px-3 py-1.5"><span class="rounded px-1.5 py-0.5 text-[10px] uppercase tracking-wide" :class="bucketTone(r.bucket)">{{ r.bucket }}</span></td>
                <td class="px-3 py-1.5 text-right font-mono font-semibold" :class="r.amount>=0?'text-emerald-600':'text-rose-600'">{{ formatCurrency(r.amount) }}</td>
              </tr>
            </tbody>
          </StaticTable>
      </AcctSection>
    </PageHeader>
  </AdminLayout>
</template>
