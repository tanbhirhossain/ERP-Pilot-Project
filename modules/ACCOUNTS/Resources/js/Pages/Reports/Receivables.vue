<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { FileDown, Wallet, Printer } from 'lucide-vue-next';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import { printDocument } from '@core/Composables/usePrint';
import { useReportPdf } from '@core/Composables/useReportPdf';
import PageHeader from '@core/Components/PageHeader.vue';
import StatusBadge from '@core/Components/StatusBadge.vue';
import AcctSection from '../../Components/AcctSection.vue';
import AcctEmpty from '../../Components/AcctEmpty.vue';
import { formatCurrency, formatDate } from '@core/Utils/format';
import { computed } from 'vue';
import StaticTable from '@core/Components/StaticTable.vue';
defineProps<{ receivables:any; filters:any }>();


const { downloadPdf } = useReportPdf();
const pdfPayload = computed(() => ({
    title: 'Receivables (AR)',
    subtitle: 'Outstanding customer invoices · BDT',
    permission: 'accounts.receivables.view',
    orientation: 'landscape',
    meta: {
        'Records': String(props.receivables.total ?? props.receivables.data.length),
        'Currency': 'BDT',
    },
    headings: ['Invoice No', 'Customer', 'Amount', 'Balance', 'Status', 'Due'],
    rows: props.receivables.data.map((r: any) => [r.invoice_no || r.journal?.journal_no, r.partner_id ?? '—', Number(r.amount) || 0, Number(r.amount) - Number(r.received_amount || 0), r.status, r.due_date]),
    footer: 'Receivables · Accounts',
}));
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'Accounts'},{title:'Receivables (AR)'}]">
    <Head title="Receivables" />
    <PageHeader title="Receivables (AR)" description="Customer invoices mirrored from receivables — unpaid RV/SV. Use Aging for buckets." :icon="Wallet" :actions="[{ label:'Download PDF', icon: FileDown, variant:'default', onClick: () => downloadPdf(pdfPayload.value) }, { label:'Print', icon: Printer, variant:'outline', onClick: () => printDocument() }]">
      <AcctSection title="Outstanding customer invoices" :description="`${receivables.total ?? receivables.data.length} record(s) · BDT`">
        <StaticTable flush>
            <thead class="bg-muted/40 text-xs uppercase tracking-widest"><tr><th class="px-3 py-2.5 text-left">Invoice No</th><th class="px-3 py-2.5 text-left">Customer</th><th class="px-3 py-2.5 text-right">Amount</th><th class="px-3 py-2.5 text-right">Balance</th><th class="px-3 py-2.5 text-center">Status</th><th class="px-3 py-2.5 text-left">Due</th></tr></thead>
            <tbody class="divide-y"><tr v-for="r in receivables.data" :key="r.id" class="hover:bg-muted/30"><td class="px-3 py-2.5 font-mono text-xs font-bold">{{ r.invoice_no || r.journal?.journal_no }}</td><td class="px-3 py-2.5">{{ r.partner_id ?? '—' }}</td><td class="px-3 py-2.5 text-right font-mono">{{ formatCurrency(Number(r.amount)) }}</td><td class="px-3 py-2.5 text-right font-mono font-bold">{{ formatCurrency(Number(r.amount)-Number(r.received_amount || 0)) }}</td><td class="px-3 py-2.5 text-center"><StatusBadge :label="r.status" :tone="r.status==='paid'?'success':r.status==='overdue'?'danger':'warning'" /></td><td class="px-3 py-2.5 text-xs">{{ formatDate(r.due_date) }}</td></tr><tr v-if="!receivables.data.length"><td colspan="6" class="px-3 py-6"><AcctEmpty title="No receivables" hint="Create an RV/SV journal with a customer partner — it appears here as outstanding." :icon="Wallet" /></td></tr></tbody>
          </StaticTable>
      </AcctSection>
    </PageHeader>
  </AdminLayout>
</template>
