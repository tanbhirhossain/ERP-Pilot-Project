<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import PageHeader from '../../../../../CORE/Resources/js/Components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import StatusBadge from '../../../../../CORE/Resources/js/Components/StatusBadge.vue';
import { formatCurrency, formatDate } from '../../../../../CORE/Resources/js/Utils/format';
import StaticTable from '../../../../../CORE/Resources/js/Components/StaticTable.vue';
defineProps<{ order: any }>();
function tone(s:string){ if(s==='received') return 'success'; if(s==='confirmed') return 'info'; if(s==='draft') return 'warning'; if(s==='cancelled') return 'danger'; return 'neutral'; }
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Purchase' }, { title: order.order_no }]">
    <Head :title="order.order_no" />
    <PageHeader title="Pages Detail" description="Vendor: {{ order.vendor?.name }} · WH: {{ order.warehouse?.name }} · {{ formatDate(order.order_date)" :icon="FileText">
    <div class="space-y-4">
      <div class="bg-card border rounded-xl shadow-sm p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h1 class="text-xl font-bold font-mono">{{ order.order_no }} <StatusBadge :label="order.status" :tone="tone(order.status)" class="ml-2"/></h1>
            <p class="text-sm text-muted-foreground">Vendor: {{ order.vendor?.name }} · WH: {{ order.warehouse?.name }} · {{ formatDate(order.order_date) }}</p>
            <p class="text-sm">Total: <span class="font-mono font-bold">{{ formatCurrency(Number(order.total_amount)) }}</span> <span class="text-xs text-muted-foreground">VAT {{ formatCurrency(Number(order.vat_amount)) }}</span></p>
          </div>
          <div class="flex flex-wrap gap-2">
            <Link :href="route('purchase.orders.edit', order.id)"><Button variant="outline" size="sm">Edit</Button></Link>
            <Button v-if="order.status==='draft'" size="sm" @click="router.post(route('purchase.orders.confirm', order.id))">Confirm</Button>
            <Button v-if="['draft','sent'].includes(order.status)" size="sm" variant="outline" @click="router.post(route('purchase.orders.approve', order.id))">Approve (≥50k)</Button>
            <Button v-if="order.status==='draft'" size="sm" variant="outline" @click="router.post(route('purchase.orders.send', order.id))">Send to Vendor</Button>
            <Link v-if="['confirmed','partially_received'].includes(order.status)" :href="route('purchase.receipts.create', { order_id: order.id })"><Button size="sm">Receive (GRN)</Button></Link>
            <Link v-if="['received','partially_received'].includes(order.status)" :href="route('purchase.bills.create', { order_id: order.id })"><Button size="sm" variant="outline">Create Bill</Button></Link>
          </div>
        </div>
        <p v-if="order.notes" class="mt-2 text-sm bg-muted/30 rounded p-2">{{ order.notes }}</p>
      </div>
      <div class="bg-card border rounded-xl shadow-sm p-4">
        <h3 class="font-semibold mb-2">Items ({{ order.items?.length ?? 0 }})</h3>
        <StaticTable
            :empty="!order?.items?.length"
            empty-title="No lines on this order"
            empty-description="Items ordered on this purchase order appear here."
        >
            <thead><tr class="border-b text-muted-foreground"><th class="text-left py-2">Product</th><th class="text-right">Qty</th><th class="text-right">Recvd</th><th class="text-right">Price</th><th class="text-right">VAT%</th><th class="text-right">Total</th></tr></thead>
            <tbody>
              <tr v-for="it in order.items" :key="it.id" class="border-b">
                <td class="py-2"><div class="font-medium">{{ it.product_name }}</div><div class="text-xs text-muted-foreground font-mono">{{ it.sku }}</div></td>
                <td class="text-right font-mono">{{ it.qty }}</td>
                <td class="text-right font-mono">{{ it.qty_received }}</td>
                <td class="text-right font-mono">{{ formatCurrency(Number(it.unit_price)) }}</td>
                <td class="text-right">{{ it.vat_rate }}%</td>
                <td class="text-right font-mono font-bold">{{ formatCurrency(Number(it.line_total)) }}</td>
              </tr>
            </tbody>
          </StaticTable>
      </div>
      <div v-if="order.receipts?.length" class="bg-card border rounded-xl shadow-sm p-4">
        <h3 class="font-semibold mb-2">Receipts</h3>
        <div class="flex flex-wrap gap-2">
          <Link v-for="r in order.receipts" :key="r.id" :href="route('purchase.receipts.show', r.id)" class="text-sm font-mono px-2 py-1 bg-primary/10 rounded hover:underline">{{ r.receipt_no }} ({{ r.status }})</Link>
        </div>
      </div>
    </div>
      </PageHeader>
  </AdminLayout>
</template>
