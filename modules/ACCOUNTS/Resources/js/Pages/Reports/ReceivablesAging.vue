<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import { formatCurrency } from '../../../../../CORE/Resources/js/Utils/format';
defineProps<{ asOf: string; buckets: any; byCustomer: any[]; total: number }>();
function fmt(n:number){ return formatCurrency(n); }
const bucketsOrder = ['current','1_30','31_60','61_90','90_plus'];
const labels:Record<string,string> = { current:'Current', '1_30':'1-30 days', '31_60':'31-60 days', '61_90':'61-90 days', '90_plus':'90+ days' };
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'Accounts',href:route('accounts.reports.trial')},{title:'Receivables Aging'}]">
    <Head title="Receivables Aging (AR)" />
    <div class="flex items-center justify-between">
      <div><h1 class="text-xl font-bold">Receivables Aging (AR) — Sales Invoices</h1><p class="text-sm text-muted-foreground">As of {{ asOf }} · by customer, days overdue.</p></div>
      <input type="date" :value="asOf" @change="(e:any)=>router.get(route('accounts.reports.receivables-aging'), {as_of: e.target.value})" class="h-9 rounded-md border bg-background px-3 text-sm" />
    </div>
    <div class="grid grid-cols-2 sm:grid-cols-6 gap-3 mt-4">
      <div v-for="k in bucketsOrder" :key="k" class="bg-card border rounded-xl p-3 text-center"><div class="text-xs text-muted-foreground">{{ labels[k] }}</div><div class="font-mono font-bold">{{ fmt(buckets[k]) }}</div></div>
      <div class="bg-emerald-600 text-white border rounded-xl p-3 text-center"><div class="text-xs opacity-90">Total AR</div><div class="font-mono font-bold">{{ fmt(total) }} BDT</div></div>
    </div>
    <div v-for="c in byCustomer" :key="c.customer_id" class="bg-card border rounded-xl p-4 mt-4">
      <div class="flex justify-between"><h3 class="font-semibold">{{ c.customer_name }}</h3><span class="font-mono font-bold">{{ fmt(c.total) }}</span></div>
      <div class="grid grid-cols-5 gap-2 mt-2 text-xs">
        <div v-for="k in bucketsOrder" :key="k" class="text-center p-1 bg-muted/30 rounded"><div class="text-muted-foreground">{{ labels[k] }}</div><div class="font-mono">{{ fmt(c.buckets[k]) }}</div></div>
      </div>
      <table class="w-full text-xs mt-3">
        <thead><tr class="border-b text-muted-foreground"><th class="text-left py-1">Invoice No</th><th class="text-left">Due</th><th class="text-center">Overdue</th><th class="text-right">Amount</th><th class="text-right">Balance</th></tr></thead>
        <tbody><tr v-for="inv in c.invoices" :key="inv.invoice_no" class="border-b"><td class="font-mono">{{ inv.invoice_no }}</td><td>{{ inv.due_date }}</td><td class="text-center">{{ inv.days_overdue }}d</td><td class="text-right">{{ fmt(Number(inv.amount)) }}</td><td class="text-right font-bold">{{ fmt(Number(inv.balance)) }}</td></tr></tbody>
      </table>
    </div>
    <div v-if="!byCustomer.length" class="py-12 text-center text-muted-foreground">No open receivables.</div>
  </AdminLayout>
</template>
