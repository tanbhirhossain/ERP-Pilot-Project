<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { CreditCard, Printer } from 'lucide-vue-next';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import { printDocument } from '@core/Composables/usePrint';
import PageHeader from '@core/Components/PageHeader.vue';
import StatusBadge from '@core/Components/StatusBadge.vue';
import AcctSection from '../../Components/AcctSection.vue';
import AcctEmpty from '../../Components/AcctEmpty.vue';
import { formatCurrency, formatDate } from '@core/Utils/format';
import StaticTable from '@core/Components/StaticTable.vue';
defineProps<{ payables:any; filters:any }>();
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'Accounts'},{title:'Payables (AP)'}]">
    <Head title="Payables" />
    <PageHeader title="Payables (AP)" description="Vendor bills mirrored from payables table — unpaid PV/vendor bills. Use Aging for buckets." :icon="CreditCard" :actions="[{ label:'Print', icon: Printer, variant:'outline', onClick: () => printDocument() }]">
      <AcctSection title="Outstanding vendor bills" :description="`${payables.total ?? payables.data.length} record(s) · BDT`">
        <StaticTable flush>
            <thead class="bg-muted/40 text-xs uppercase tracking-widest"><tr><th class="px-3 py-2.5 text-left">Bill No</th><th class="px-3 py-2.5 text-left">Vendor</th><th class="px-3 py-2.5 text-right">Amount</th><th class="px-3 py-2.5 text-right">Balance</th><th class="px-3 py-2.5 text-center">Status</th><th class="px-3 py-2.5 text-left">Due</th></tr></thead>
            <tbody class="divide-y"><tr v-for="p in payables.data" :key="p.id" class="hover:bg-muted/30"><td class="px-3 py-2.5 font-mono text-xs font-bold">{{ p.bill_no || p.journal?.journal_no }}</td><td class="px-3 py-2.5">{{ p.partner_id ?? '—' }}</td><td class="px-3 py-2.5 text-right font-mono">{{ formatCurrency(Number(p.amount)) }}</td><td class="px-3 py-2.5 text-right font-mono font-bold">{{ formatCurrency(Number(p.amount)-Number(p.paid_amount || 0)) }}</td><td class="px-3 py-2.5 text-center"><StatusBadge :label="p.status" :tone="p.status==='paid'?'success':p.status==='overdue'?'danger':'warning'" /></td><td class="px-3 py-2.5 text-xs">{{ formatDate(p.due_date) }}</td></tr><tr v-if="!payables.data.length"><td colspan="6" class="px-3 py-6"><AcctEmpty title="No payables" hint="Create a PV journal with a vendor partner — amount lands here as outstanding." :icon="CreditCard" /></td></tr></tbody>
          </StaticTable>
      </AcctSection>
    </PageHeader>
  </AdminLayout>
</template>
