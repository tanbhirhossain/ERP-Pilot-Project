<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import type { VendorBill } from '@purchase/types';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import StatusBadge from '@core/Components/StatusBadge.vue';
import { formatCurrency, formatDate } from '@core/Utils/format';
import { computed } from 'vue';
import SearchableSelect from '@core/Components/SearchableSelect.vue';
import { FileText } from 'lucide-vue-next';
import StaticTable from '@core/Components/StaticTable.vue';
const props = defineProps<{ bill: VendorBill; canPay: boolean; paymentAccounts: { value: number; label: string }[] }>();
const page = usePage() as any;
const paymentError = computed(() => page.props.flash?.error || Object.values(pay.errors)[0]);
const pay = useForm({ amount: '', payment_date: new Date().toISOString().slice(0,10), method: 'cash', bank_id: null as number | null, reference_no: '' });
function tone(s:string){ if(s==='posted') return 'success'; if(s==='draft') return 'warning'; if(s==='paid') return 'success'; return 'neutral'; }
function submitPay(){ pay.transform(data => ({ ...data, bank_id: data.method === 'cash' ? null : data.bank_id })).post(route('purchase.bills.pay', props.bill.id), { preserveScroll: true }); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Purchase' }, { title: 'Vendor Bills', href: route('purchase.bills.index') }, { title: bill.bill_no }]">
    <Head :title="bill.bill_no" />
    <PageHeader
      :title="`Vendor bill ${bill.bill_no}`"
      :description="`Vendor: ${bill.vendor?.name ?? '—'} · WH: ${bill.warehouse?.name ?? '—'} · ${formatDate(bill.bill_date)}`"
      :icon="FileText"
    >
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
        <div v-for="p in bill.payments" :key="p.id" class="text-sm border-b py-1 flex justify-between"><span>{{ p.payment_no }} · {{ formatDate(p.payment_date) }} · {{ p.method }}</span><span class="font-mono font-bold">{{ formatCurrency(Number(p.amount)) }}</span></div>
      </div>
      <div v-if="bill.status==='posted' && bill.payment_status!=='paid' && canPay" class="bg-card border rounded-xl shadow-sm p-4">
        <h3 class="font-semibold mb-2 text-sm">Record Payment</h3>
        <p v-if="paymentError" role="alert" class="mb-3 text-sm text-destructive">{{ paymentError }}</p>
        <form class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3" @submit.prevent="submitPay">
          <div><label for="payment-amount" class="text-xs">Amount *</label><Input id="payment-amount" v-model="pay.amount" type="number" min="0.01" step="0.01" placeholder="0.00" required/></div>
          <div><label for="payment-date" class="text-xs">Date *</label><Input id="payment-date" v-model="pay.payment_date" type="date" required/></div>
          <div><label for="payment-method" class="text-xs">Method *</label><select id="payment-method" v-model="pay.method" class="w-full h-9 rounded-md border bg-background px-3 text-sm"><option value="cash">Cash</option><option value="bank">Bank</option><option value="bkash">bKash</option><option value="card">Card</option></select></div>
          <div v-if="pay.method !== 'cash'"><label for="payment-bank" class="text-xs">Bank / wallet account *</label><SearchableSelect id="payment-bank" v-model="pay.bank_id" :options="paymentAccounts" placeholder="Select account" /></div>
          <div><label for="payment-ref" class="text-xs">Reference</label><Input id="payment-ref" v-model="pay.reference_no" placeholder="Transaction reference" /></div>
          <div class="flex items-end"><Button type="submit" size="sm" :disabled="pay.processing || (pay.method !== 'cash' && !pay.bank_id)">{{ pay.processing ? 'Recording…' : 'Record Payment' }}</Button></div>
        </form>
      </div>
    </div>
      </PageHeader>
  </AdminLayout>
</template>
