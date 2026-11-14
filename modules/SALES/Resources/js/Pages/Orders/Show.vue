<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import type { Order } from '@sales/types';
import { Button } from '@/components/ui/button';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import ActivityTimeline from '@core/Components/ActivityTimeline.vue';
import StatusBadge from '@core/Components/StatusBadge.vue';
import { formatCurrency, formatDate } from '@core/Utils/format';
import StaticTable from '@core/Components/StaticTable.vue';
defineProps<{ order:Order; activities?: any[] }>();
// Renamed from `confirm`, which shadowed window.confirm for the whole file.
function confirmOrder(id:number){ router.post(route('sales.orders.confirm', id)); }
function cancel(id:number){ router.post(route('sales.orders.cancel', id)); }
function createInvoice(id:number){ router.post(route('sales.orders.create-invoice', id)); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Sales & POS' }, { title: 'Sales Orders', href: route('sales.orders.index') }, { title: order.order_no }]">
    <Head :title="order.order_no" />
    <div class="space-y-4 ">
      <div class="bg-card border rounded-xl p-4">
        <div class="flex justify-between gap-4">
          <div><h1 class="text-xl font-bold flex items-center gap-2">{{ order.order_no }} <StatusBadge :label="order.status" :tone="order.status==='confirmed'?'info':order.status==='invoiced'?'success':'warning'" /></h1><p class="text-sm text-muted-foreground">{{ order.customer?.name }} · {{ order.warehouse?.name }} · {{ formatDate(order.order_date) }} · {{ formatCurrency(Number(order.total_amount)) }}</p></div>
          <div class="flex flex-col gap-2">
            <Button v-if="order.status==='draft'" @click="confirmOrder(order.id)" size="sm" class="bg-sky-600 hover:bg-sky-700">Confirm (Reserve Stock)</Button>
            <Button v-if="['confirmed','partially_invoiced'].includes(order.status)" @click="createInvoice(order.id)" size="sm" variant="outline">Create Invoice</Button>
            <Link :href="route('sales.deliveries.create', { order_id: order.id })"><Button size="sm" variant="outline">🚚 Create Delivery (Challan)</Button></Link>
            <div class="flex gap-2"><Link :href="route('sales.orders.edit', order.id)"><Button variant="outline" size="sm">Edit</Button></Link><Button v-if="order.status==='draft'" @click="cancel(order.id)" variant="destructive" size="sm">Cancel</Button></div>
          </div>
        </div>
      </div>
      <div class="bg-card border rounded-xl p-4">
        <h3 class="font-semibold mb-2">Items — {{ order.items.length }}</h3>
        <StaticTable
            :empty="!order?.items?.length"
            empty-title="No lines on this order"
            empty-description="Items ordered on this sales order appear here."
        ><thead class="bg-muted/40 text-xs uppercase"><tr><th class="px-3 py-2 text-left">Product</th><th class="px-3 py-2 text-center">Qty</th><th class="px-3 py-2 text-center">Invoiced</th><th class="px-3 py-2 text-right">Total</th></tr></thead><tbody class="divide-y"><tr v-for="it in order.items" :key="it.id"><td class="px-3 py-2">{{ it.product_name }}</td><td class="px-3 py-2 text-center font-mono">{{ it.qty }}</td><td class="px-3 py-2 text-center font-mono">{{ it.qty_invoiced }}</td><td class="px-3 py-2 text-right font-mono">{{ formatCurrency(Number(it.line_total)) }}</td></tr></tbody></StaticTable>
      </div>
      <div class="bg-card border rounded-xl p-4">
        <h3 class="font-semibold mb-2">Invoices — {{ order.invoices.length }}</h3>
        <div v-if="!order.invoices.length" class="text-sm text-muted-foreground py-4 text-center">No invoices yet.</div>
        <div v-for="inv in order.invoices" :key="inv.id" class="flex justify-between text-sm border-b py-1"><Link :href="route('sales.invoices.show', inv.id)" class="text-primary hover:underline">{{ inv.invoice_no }}</Link><span>{{ formatCurrency(Number(inv.total_amount)) }} · {{ inv.status }}</span></div>
      </div>
      <ActivityTimeline :activities="activities" class="mt-4" />
    </div>
  </AdminLayout>
</template>
