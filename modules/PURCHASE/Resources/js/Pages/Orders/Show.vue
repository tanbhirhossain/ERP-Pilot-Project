<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import type { PurchaseOrder } from '@purchase/types';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import ActivityTimeline from '@core/Components/ActivityTimeline.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import { FileText } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import StatusBadge from '@core/Components/StatusBadge.vue';
import { formatCurrency, formatDate } from '@core/Utils/format';
import StaticTable from '@core/Components/StaticTable.vue';
import { computed } from 'vue';
const props = defineProps<{ order: PurchaseOrder; activities?: any[] }>();
function tone(s:string){ if(s==='received') return 'success'; if(s==='confirmed') return 'info'; if(s==='draft') return 'warning'; if(s==='cancelled') return 'danger'; return 'neutral'; }
/**
 * Assets skip the GRN entirely (PO → bill), so a pure-asset order offers no Receive button —
 * only the asset bill. A mixed order keeps both: its goods still arrive through a receipt.
 */
const hasAssetLines = computed(() => (props.order.items ?? []).some((it: any) => it.product?.type === 'asset'));
const hasGoodsLines = computed(() => (props.order.items ?? []).some((it: any) => (it.product?.type ?? 'stockable') !== 'asset'));
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Purchase' }, { title: order.order_no }]">
    <Head :title="order.order_no" />
    <PageHeader
      :title="`Purchase order ${order.order_no}`"
      :description="`Vendor: ${order.vendor?.name ?? '—'} · WH: ${order.warehouse?.name ?? '—'} · ${formatDate(order.order_date)}`"
      :icon="FileText"
    >
    <template #badge><StatusBadge :label="order.status" :tone="tone(order.status)" /></template>
    <div class="space-y-4">
      <div class="bg-card border rounded-xl shadow-sm p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Order total</p>
            <p class="mt-1 font-mono text-xl font-semibold tabular-nums">{{ formatCurrency(Number(order.total_amount)) }}</p>
            <p class="mt-1 text-xs text-muted-foreground">Includes VAT {{ formatCurrency(Number(order.vat_amount)) }}</p>
          </div>
          <div class="flex flex-wrap gap-2">
            <Link :href="route('purchase.orders.edit', order.id)"><Button variant="outline" size="sm">Edit</Button></Link>
            <a :href="route('core.documents.pdf', { type: 'purchase-order', id: order.id })" target="_blank"><Button variant="outline" size="sm">⬇ PDF</Button></a>
            <Button v-if="order.status==='draft'" size="sm" @click="router.post(route('purchase.orders.confirm', order.id))">Confirm</Button>
            <Button v-if="['draft','sent'].includes(order.status)" size="sm" variant="outline" @click="router.post(route('purchase.orders.approve', order.id))">Approve (≥50k)</Button>
            <Button v-if="order.status==='draft'" size="sm" variant="outline" @click="router.post(route('purchase.orders.send', order.id))">Send to Vendor</Button>
            <Link v-if="['confirmed','partially_received'].includes(order.status) && hasGoodsLines" :href="route('purchase.receipts.create', { order_id: order.id })"><Button size="sm">Receive (GRN)</Button></Link>
            <Link v-if="['received','partially_received'].includes(order.status)" :href="route('purchase.bills.create', { order_id: order.id })"><Button size="sm" variant="outline">Create Bill</Button></Link>
            <Link v-if="order.status==='confirmed' && hasAssetLines" :href="route('purchase.bills.create_asset', { order_id: order.id })"><Button size="sm">Create Asset Bill</Button></Link>
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
      <ActivityTimeline :activities="activities" class="mt-4" />
      </PageHeader>
  </AdminLayout>
</template>
