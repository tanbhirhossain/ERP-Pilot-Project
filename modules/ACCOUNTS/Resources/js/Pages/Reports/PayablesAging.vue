<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import { formatCurrency} from '@core/Utils/format';
import StaticTable from '@core/Components/StaticTable.vue';
defineProps<{ asOf: string; buckets: any; byVendor: any[]; total: number }>();
function fmt(n:number){ return formatCurrency(n); }
const bucketsOrder = ['current','1_30','31_60','61_90','90_plus'];
const labels:Record<string,string> = { current:'Current', '1_30':'1-30 days', '31_60':'31-60 days', '61_90':'61-90 days', '90_plus':'90+ days' };
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Accounts' }, { title: 'Payables Aging' }]">
    <Head title="Payables Aging (AP)" />
    <div class="flex items-center justify-between">
      <div><PageHeader title="Payables Aging (AP)" description="Vendor Bills" /><p class="text-sm text-muted-foreground">As of {{ asOf }} · buckets by days overdue (due_date). Unpaid posted bills only.</p></div>
      <input type="date" :value="asOf" @change="(e:any)=>router.get(route('accounts.reports.payables-aging'), {as_of: e.target.value})" class="h-9 rounded-md border bg-background px-3 text-sm" />
    </div>
    <div class="grid grid-cols-2 sm:grid-cols-6 gap-3 mt-4">
      <div v-for="k in bucketsOrder" :key="k" class="bg-card border rounded-xl p-3 text-center"><div class="text-xs text-muted-foreground">{{ labels[k] }}</div><div class="font-mono font-bold">{{ fmt(buckets[k]) }}</div></div>
      <div class="bg-primary text-primary-foreground border rounded-xl p-3 text-center"><div class="text-xs opacity-80">Total AP</div><div class="font-mono font-bold">{{ fmt(total) }} BDT</div></div>
    </div>
    <div v-for="v in byVendor" :key="v.vendor_id" class="bg-card border rounded-xl p-4 mt-4">
      <div class="flex justify-between items-start"><div><h3 class="font-semibold">{{ v.vendor_name }} <span class="text-xs text-muted-foreground">#{{ v.vendor_id }}</span></h3><p class="text-xs text-muted-foreground">{{ v.bills.length }} bill(s)</p></div><span class="font-mono font-bold">{{ fmt(v.total) }}</span></div>
      <div class="grid grid-cols-5 gap-2 mt-2 text-xs">
        <div v-for="k in bucketsOrder" :key="k" class="text-center p-1 bg-muted/30 rounded"><div class="text-muted-foreground">{{ labels[k] }}</div><div class="font-mono">{{ fmt(v.buckets[k]) }}</div></div>
      </div>
      <StaticTable dense class="mt-3">
        <thead><tr class="border-b text-muted-foreground"><th class="text-left py-1">Bill No</th><th class="text-left">Bill Date</th><th class="text-left">Due</th><th class="text-center">Overdue</th><th class="text-right">Amount</th><th class="text-right">Balance</th></tr></thead>
        <tbody><tr v-for="b in v.bills" :key="b.bill_no" class="border-b"><td class="font-mono">{{ b.bill_no }}</td><td>{{ b.bill_date }}</td><td>{{ b.due_date }}</td><td class="text-center">{{ b.days_overdue }}d</td><td class="text-right">{{ fmt(Number(b.amount)) }}</td><td class="text-right font-bold">{{ fmt(Number(b.balance)) }}</td></tr></tbody>
      </StaticTable>
    </div>
    <div v-if="!byVendor.length" class="py-12 text-center text-muted-foreground">No open payables — all vendor bills paid.</div>
  </AdminLayout>
</template>
