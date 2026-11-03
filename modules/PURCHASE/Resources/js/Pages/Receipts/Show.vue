<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import PageHeader from '../../../../../CORE/Resources/js/Components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import StatusBadge from '../../../../../CORE/Resources/js/Components/StatusBadge.vue';
import { formatCurrency, formatDate } from '../../../../../CORE/Resources/js/Utils/format';
defineProps<{ receipt: any }>();
function tone(s:string){ if(s==='posted') return 'success'; if(s==='draft') return 'warning'; return 'neutral'; }
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'Purchase',href:route('purchase.receipts.index')},{title:receipt.receipt_no}]">
    <Head :title="receipt.receipt_no" />
    <PageHeader title="Pages Detail" description="Vendor: {{ receipt.vendor?.name }} · WH: {{ receipt.warehouse?.name }} · {{ formatDate(receipt.recei" :icon="FileText">
    <div class="max-w-5xl mx-auto space-y-4">
      <div class="bg-card border rounded-2xl shadow-sm p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h1 class="text-xl font-bold font-mono">{{ receipt.receipt_no }} <StatusBadge :label="receipt.status" :tone="tone(receipt.status)" class="ml-2"/></h1>
            <p class="text-sm text-muted-foreground">Vendor: {{ receipt.vendor?.name }} · WH: {{ receipt.warehouse?.name }} · {{ formatDate(receipt.receipt_date) }} <span v-if="receipt.order">· PO: {{ receipt.order.order_no }}</span></p>
            <p class="text-sm">Total: <span class="font-mono font-bold">{{ formatCurrency(Number(receipt.total_amount)) }}</span></p>
          </div>
          <div class="flex gap-2">
            <Link :href="route('purchase.receipts.edit', receipt.id)"><Button variant="outline" size="sm">Edit</Button></Link>
            <Button v-if="receipt.status==='draft'" size="sm" @click="router.post(route('purchase.receipts.post', receipt.id))">Post — Stock IN</Button>
            <Button v-if="receipt.status==='posted'" size="sm" variant="destructive" @click="router.post(route('purchase.receipts.void', receipt.id))">Void — Reverse Stock</Button>
            <Link v-if="receipt.status==='posted'" :href="route('purchase.bills.create', { receipt_id: receipt.id })"><Button size="sm" variant="outline">Create Bill</Button></Link>
            <Link v-if="receipt.status==='posted'" :href="route('purchase.returns.create', { receipt_id: receipt.id })"><Button size="sm" variant="outline">Return</Button></Link>
          </div>
        </div>
        <p v-if="receipt.notes" class="mt-2 text-sm bg-muted/30 rounded p-2">{{ receipt.notes }}</p>
      </div>
      <div class="bg-card border rounded-2xl shadow-sm p-4">
        <h3 class="font-semibold mb-2">Items</h3>
        <table class="w-full text-sm">
          <thead><tr class="border-b text-muted-foreground"><th class="text-left py-2">Product</th><th class="text-right">Qty</th><th class="text-right">Price</th><th class="text-right">Total</th></tr></thead>
          <tbody><tr v-for="it in receipt.items" :key="it.id" class="border-b"><td class="py-2"><div class="font-medium">{{ it.product_name }}</div><div class="text-xs text-muted-foreground font-mono">{{ it.sku }}</div></td><td class="text-right font-mono">{{ it.qty }}</td><td class="text-right font-mono">{{ formatCurrency(Number(it.unit_price)) }}</td><td class="text-right font-mono font-bold">{{ formatCurrency(Number(it.line_total)) }}</td></tr></tbody>
        </table>
      </div>
    </div>
      </PageHeader>
  </AdminLayout>
</template>
