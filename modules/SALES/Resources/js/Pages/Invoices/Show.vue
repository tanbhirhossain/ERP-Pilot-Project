<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import StatusBadge from '../../../../../CORE/Resources/js/Components/StatusBadge.vue';
import { formatCurrency, formatDate } from '../../../../../CORE/Resources/js/Utils/format';
import { ref } from 'vue';
defineProps<{ invoice:any }>();
const payAmount=ref(0), payMethod=ref('cash'), payDate=ref(new Date().toISOString().slice(0,10));
function post(id:number){ if(confirm('Post invoice? Stock will be deducted & journal created.')) router.post(route('sales.invoices.post', id)); }
function pay(id:number){ router.post(route('sales.invoices.pay', id), {amount: payAmount.value, payment_date: payDate.value, method: payMethod.value}); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'Invoices',href:route('sales.invoices.index')},{title:invoice.invoice_no}]">
    <Head :title="invoice.invoice_no" />
    <div class="space-y-4 max-w-5xl mx-auto">
      <div class="bg-card border rounded-xl p-4">
        <div class="flex justify-between gap-4">
          <div><h1 class="text-xl font-bold flex items-center gap-2">{{ invoice.invoice_no }} <StatusBadge :label="invoice.status" :tone="invoice.status==='paid'?'success':invoice.status==='posted'?'info':'warning'" /> <StatusBadge :label="invoice.payment_status" tone="neutral" /></h1><p class="text-sm text-muted-foreground">{{ invoice.customer?.name }} · {{ invoice.warehouse?.name }} · {{ formatDate(invoice.invoice_date) }} → {{ invoice.due_date?formatDate(invoice.due_date):'No due' }} · {{ formatCurrency(Number(invoice.total_amount)) }}</p><p v-if="invoice.journal" class="text-xs mt-1">Journal: <Link :href="route('accounts.journals.show', invoice.journal.id)" class="text-primary hover:underline">{{ invoice.journal.journal_no }}</Link></p></div>
          <div class="flex flex-col gap-2">
            <Button v-if="invoice.status==='draft'" @click="post(invoice.id)" size="sm" class="bg-emerald-600 hover:bg-emerald-700">Post (Deduct Stock + Ledger)</Button>
            <a v-if="invoice.status!=='draft'" :href="route('sales.invoices.mushak', invoice.id)" target="_blank"><Button variant="outline" size="sm" class="w-full">📄 Mushak 6.3 PDF</Button></a>
            <Link v-if="invoice.status!=='draft'" :href="route('sales.returns.create', { invoice_id: invoice.id })"><Button variant="outline" size="sm" class="w-full">↩️ Return / Credit Note</Button></Link>
            <Link :href="route('sales.invoices.edit', invoice.id)"><Button variant="outline" size="sm" class="w-full">Edit</Button></Link>
          </div>
        </div>
        <div class="mt-3 p-3 bg-muted/20 border rounded flex gap-2 items-end">
          <div class="space-y-1"><Label>Pay Amount</Label><Input v-model.number="payAmount" type="number" step="0.01" class="w-32"/></div>
          <div class="space-y-1"><Label>Method</Label><select v-model="payMethod" class="h-9 rounded border bg-background px-2 text-sm"><option value="cash">Cash</option><option value="bank">Bank</option><option value="bkash">bKash</option></select></div>
          <div class="space-y-1"><Label>Date</Label><Input v-model="payDate" type="date" class="w-36"/></div>
          <Button size="sm" @click="pay(invoice.id)">Record Payment</Button>
        </div>
      </div>
      <div class="bg-card border rounded-xl p-4">
        <h3 class="font-semibold mb-2">Items — {{ invoice.items.length }} · Paid {{ formatCurrency(Number(invoice.paid_amount)) }} / {{ formatCurrency(Number(invoice.total_amount)) }}</h3>
        <table class="w-full text-sm"><thead class="bg-muted/40 text-xs uppercase"><tr><th class="px-3 py-2 text-left">Product</th><th class="px-3 py-2 text-center">Qty</th><th class="px-3 py-2 text-right">Price</th><th class="px-3 py-2 text-right">Total</th></tr></thead><tbody class="divide-y"><tr v-for="it in invoice.items" :key="it.id"><td class="px-3 py-2">{{ it.product_name }}</td><td class="px-3 py-2 text-center font-mono">{{ it.qty }}</td><td class="px-3 py-2 text-right font-mono">{{ formatCurrency(Number(it.unit_price)) }}</td><td class="px-3 py-2 text-right font-mono font-bold">{{ formatCurrency(Number(it.line_total)) }}</td></tr></tbody></table>
      </div>
      <div class="bg-card border rounded-xl p-4">
        <h3 class="font-semibold mb-2">Payments — {{ invoice.payments.length }}</h3>
        <div v-if="!invoice.payments.length" class="text-sm text-muted-foreground py-4 text-center">No payments yet.</div>
        <div v-for="p in invoice.payments" :key="p.id" class="flex justify-between text-sm border-b py-1"><span>{{ p.payment_no }} · {{ p.method }} · {{ formatDate(p.payment_date) }}</span><span class="font-mono font-bold">{{ formatCurrency(Number(p.amount)) }}</span></div>
      </div>
    </div>
  </AdminLayout>
</template>
