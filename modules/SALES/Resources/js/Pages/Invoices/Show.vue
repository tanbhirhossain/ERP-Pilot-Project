<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import type { Invoice } from '@sales/types';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import StatusBadge from '@core/Components/StatusBadge.vue';
import { formatCurrency, formatDate } from '@core/Utils/format';
import { ref } from 'vue';
import StaticTable from '@core/Components/StaticTable.vue';
import { useConfirm } from '@core/Composables/useConfirm';
import ActivityTimeline from '@core/Components/ActivityTimeline.vue';
import AttachmentPanel from '@core/Components/AttachmentPanel.vue';
const { confirm } = useConfirm();
defineProps<{ invoice:Invoice; activities?: any[]; attachments?: any[]; canManageAttachments?: boolean }>();
const payAmount=ref(0), payMethod=ref('cash'), payDate=ref(new Date().toISOString().slice(0,10));
async function post(id:number){ if(await confirm({ description: 'Post invoice? Stock will be deducted & journal created.', danger: false })) router.post(route('sales.invoices.post', id)); }
async function pay(id:number){ router.post(route('sales.invoices.pay', id), {amount: payAmount.value, payment_date: payDate.value, method: payMethod.value}); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Sales & POS' }, { title: 'Invoices', href: route('sales.invoices.index') }, { title: invoice.invoice_no }]">
    <Head :title="invoice.invoice_no" />    <PageHeader :title="invoice.invoice_no" :description="`${invoice.customer?.name} · ${invoice.warehouse?.name} · ${formatDate(invoice.invoice_date)} → ${invoice.due_date?formatDate(invoice.due_date):'No due'} · ${formatCurrency(Number(invoice.total_amount))}`">
      <template #badge>
        <StatusBadge :label="invoice.status" :tone="invoice.status==='paid'?'success':invoice.status==='posted'?'info':'warning'" /> <StatusBadge :label="invoice.payment_status" tone="neutral" />
      </template>
      <template #actions>
        <div class="flex flex-wrap items-center justify-end gap-2">
            <Button v-if="invoice.status==='draft'" @click="post(invoice.id)" size="sm" class="bg-emerald-600 hover:bg-emerald-700">Post (Deduct Stock + Ledger)</Button>
            <a v-if="invoice.status!=='draft'" :href="route('sales.invoices.mushak', invoice.id)" target="_blank"><Button variant="outline" size="sm">📄 Mushak 6.3 PDF</Button></a>
            <a :href="route('core.documents.pdf', { type: 'invoice', id: invoice.id })" target="_blank"><Button variant="outline" size="sm">⬇ Branded PDF</Button></a>
            <Link v-if="invoice.status!=='draft'" :href="route('sales.returns.create', { invoice_id: invoice.id })"><Button variant="outline" size="sm">↩️ Return / Credit Note</Button></Link>
            <Link :href="route('sales.invoices.edit', invoice.id)"><Button variant="outline" size="sm">Edit</Button></Link>
          </div>
      </template>

      <section class="rounded-xl border bg-card p-4" aria-label="Record payment">
        <div class="mb-3">
          <h2 class="text-sm font-semibold">Record payment</h2>
          <p class="mt-0.5 text-xs text-muted-foreground">Enter the amount, method and payment date.</p>
        </div>
        <div class="grid items-end gap-3 sm:grid-cols-2 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)_auto]">
          <div class="min-w-0 space-y-1"><Label for="invoice-pay-amount">Amount</Label><Input id="invoice-pay-amount" v-model.number="payAmount" type="number" step="0.01" class="w-full font-mono" /></div>
          <div class="min-w-0 space-y-1"><Label for="invoice-pay-method">Method</Label><select id="invoice-pay-method" v-model="payMethod" class="h-10 w-full rounded-md border bg-background px-3 text-sm"><option value="cash">Cash</option><option value="bank">Bank</option><option value="bkash">bKash</option></select></div>
          <div class="min-w-0 space-y-1"><Label for="invoice-pay-date">Date</Label><Input id="invoice-pay-date" v-model="payDate" type="date" class="w-full" /></div>
          <Button type="button" @click="pay(invoice.id)">Record Payment</Button>
        </div>
      </section>

      <div class="bg-card border rounded-xl p-4">
        <h3 class="font-semibold mb-2">Items — {{ invoice.items.length }} · Paid {{ formatCurrency(Number(invoice.paid_amount)) }} / {{ formatCurrency(Number(invoice.total_amount)) }}</h3>
        <StaticTable
            :empty="!invoice?.items?.length"
            empty-title="No lines on this invoice"
            empty-description="Items billed on this invoice appear here."
        ><thead class="bg-muted/40 text-xs uppercase"><tr><th class="px-3 py-2 text-left">Product</th><th class="px-3 py-2 text-center">Qty</th><th class="px-3 py-2 text-right">Price</th><th class="px-3 py-2 text-right">Total</th></tr></thead><tbody class="divide-y"><tr v-for="it in invoice.items" :key="it.id"><td class="px-3 py-2">{{ it.product_name }}</td><td class="px-3 py-2 text-center font-mono">{{ it.qty }}</td><td class="px-3 py-2 text-right font-mono">{{ formatCurrency(Number(it.unit_price)) }}</td><td class="px-3 py-2 text-right font-mono font-bold">{{ formatCurrency(Number(it.line_total)) }}</td></tr></tbody></StaticTable>
      </div>
      <div class="bg-card border rounded-xl p-4">
        <h3 class="font-semibold mb-2">Payments — {{ invoice.payments.length }}</h3>
        <div v-if="!invoice.payments.length" class="text-sm text-muted-foreground py-4 text-center">No payments yet.</div>
        <div v-for="p in invoice.payments" :key="p.id" class="flex justify-between text-sm border-b py-1"><span>{{ p.payment_no }} · {{ p.method }} · {{ formatDate(p.payment_date) }}</span><span class="font-mono font-bold">{{ formatCurrency(Number(p.amount)) }}</span></div>
      </div>
      <div class="mt-4 grid gap-4 lg:grid-cols-2">
        <AttachmentPanel type="invoice" :record-id="invoice.id" :attachments="attachments" :can-manage="canManageAttachments" />
        <ActivityTimeline :activities="activities" />
      </div>
    </PageHeader>
  </AdminLayout>
</template>
