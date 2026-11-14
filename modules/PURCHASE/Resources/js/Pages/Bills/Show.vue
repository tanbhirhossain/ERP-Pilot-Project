<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import type { VendorBill } from '@purchase/types';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import StatusBadge from '@core/Components/StatusBadge.vue';
import { formatCurrency, formatDate } from '@core/Utils/format';
import { ref } from 'vue';
import { FileText } from 'lucide-vue-next';
import StaticTable from '@core/Components/StaticTable.vue';
const props = defineProps<{ bill: VendorBill }>();
const pay = ref({ amount: '', payment_date: new Date().toISOString().slice(0,10), method: 'bank', reference_no: '' });
function tone(s:string){ if(s==='posted') return 'success'; if(s==='draft') return 'warning'; if(s==='paid') return 'success'; return 'neutral'; }
function submitPay(){ router.post(route('purchase.bills.pay', props.bill.id), pay.value); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Purchase' }, { title: 'Vendor Bills', href: route('purchase.bills.index') }, { title: bill.bill_no }]">
    <Head :title="bill.bill_no" />
    <PageHeader title="Pages Detail" description="Vendor: {{ bill.vendor?.name }} · WH: {{ bill.warehouse?.name }} · {{ formatDate(bill.bill_date) }} " :icon="FileText">
    <div class="space-y-4">
      <div class="bg-card border rounded-xl shadow-sm p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h1 class="text-xl font-bold font-mono">{{ bill.bill_no }} <StatusBadge :label="bill.status" :tone="tone(bill.status)" class="ml-2"/> <StatusBadge :label="bill.payment_status" :tone="bill.payment_status==='paid'?'success':'warning'" class="ml-1"/></h1>
            <p class="text-sm text-muted-foreground">Vendor: {{ bill.vendor?.name }} · WH: {{ bill.warehouse?.name }} · {{ formatDate(bill.bill_date) }} <span v-if="bill.receipt">· GRN: {{ bill.receipt.receipt_no }}</span> <span v-if="bill.order">· PO: {{ bill.order.order_no }}</span></p>
            <p class="text-sm">Total: <span class="font-mono font-bold">{{ formatCurrency(Number(bill.total_amount)) }}</span> · Paid: {{ formatCurrency(Number(bill.paid_amount)) }} <span v-if="bill.journal" class="text-xs text-muted-foreground">· Journal {{ bill.journal.journal_no }}</span></p>
          </div>
          <div class="flex gap-2">
            <Link :href="route('purchase.bills.edit', bill.id)"><Button variant="outline" size="sm">Edit</Button></Link>
            <a :href="route('core.documents.pdf', { type: 'bill', id: bill.id })" target="_blank"><Button variant="outline" size="sm">⬇ PDF</Button></a>
            <Button v-if="bill.status==='draft'" size="sm" @click="router.post(route('purchase.bills.post', bill.id))">Post — AP</Button>
            <Button v-if="bill.status==='posted'" size="sm" variant="destructive" @click="router.post(route('purchase.bills.void', bill.id))">Void — Reverse AP</Button>
            <Link v-if="bill.status==='posted'" :href="route('purchase.returns.create', { bill_id: bill.id })"><Button size="sm" variant="outline">Return / Debit Note</Button></Link>
          </div>
        </div>
        <p v-if="bill.vendor_reference" class="mt-1 text-xs text-muted-foreground">Vendor Ref: {{ bill.vendor_reference }}</p>
        <p v-if="bill.notes" class="mt-2 text-sm bg-muted/30 rounded p-2">{{ bill.notes }}</p>
      </div>
      <div class="bg-card border rounded-xl shadow-sm p-4">
        <h3 class="font-semibold mb-2">Items</h3>
        <StaticTable
          :empty="!bill?.items?.length"
          empty-title="No lines on this bill"
          empty-description="Items billed on this vendor bill appear here."
        >
          <thead><tr class="border-b text-muted-foreground"><th class="text-left py-2">Product</th><th class="text-right">Qty</th><th class="text-right">Price</th><th class="text-right">Total</th></tr></thead>
          <tbody><tr v-for="it in bill.items" :key="it.id" class="border-b"><td class="py-2"><div class="font-medium">{{ it.product_name }}</div><div class="text-xs text-muted-foreground font-mono">{{ it.sku }}</div></td><td class="text-right font-mono">{{ it.qty }}</td><td class="text-right font-mono">{{ formatCurrency(Number(it.unit_price)) }}</td><td class="text-right font-mono font-bold">{{ formatCurrency(Number(it.line_total)) }}</td></tr></tbody>
        </StaticTable>
      </div>
      <div v-if="bill.payments?.length" class="bg-card border rounded-xl shadow-sm p-4">
        <h3 class="font-semibold mb-2">Payments</h3>
        <div v-for="p in bill.payments" :key="p.id" class="text-sm border-b py-1 flex justify-between"><span>{{ p.payment_no }} · {{ p.payment_date }} · {{ p.method }}</span><span class="font-mono font-bold">{{ formatCurrency(Number(p.amount)) }}</span></div>
      </div>
      <div v-if="bill.status==='posted' && bill.payment_status!=='paid'" class="bg-card border rounded-xl shadow-sm p-4">
        <h3 class="font-semibold mb-2 text-sm">Record Payment</h3>
        <div class="grid grid-cols-1 sm:grid-cols-4 gap-3">
          <div><label class="text-xs">Amount *</label><Input v-model="pay.amount" type="number" step="0.01" placeholder="0.00"/></div>
          <div><label class="text-xs">Date *</label><Input v-model="pay.payment_date" type="date"/></div>
          <div><label class="text-xs">Method</label><select v-model="pay.method" class="w-full h-9 rounded-md border bg-background px-3 text-sm"><option value="cash">Cash</option><option value="bank">Bank</option><option value="bkash">bKash</option></select></div>
          <div class="flex items-end"><Button size="sm" @click="submitPay">Pay</Button></div>
        </div>
      </div>
    </div>
      </PageHeader>
  </AdminLayout>
</template>
