<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import StatusBadge from '../../../../../CORE/Resources/js/Components/StatusBadge.vue';
import { formatDate } from '../../../../../CORE/Resources/js/Utils/format';
import StaticTable from '../../../../../CORE/Resources/js/Components/StaticTable.vue';
defineProps<{ delivery:any }>();
function ship(id:number){ if(confirm('Ship delivery? Stock will be deducted.')) router.post(route('sales.deliveries.ship', id)); }
function cancel(id:number){ if(confirm('Cancel delivery?')) router.post(route('sales.deliveries.cancel', id)); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Sales & POS' }, { title: 'Deliveries (Challan)', href: route('sales.deliveries.index') }, { title: delivery.delivery_no }]">
    <Head :title="delivery.delivery_no" />
    <div class="space-y-4 ">
      <div class="flex items-center justify-between"><h1 class="text-xl font-bold flex items-center gap-2">{{ delivery.delivery_no }} <StatusBadge :label="delivery.status" :tone="delivery.status==='shipped'?'success':delivery.status==='draft'?'warning':'neutral'" /></h1>
        <div class="flex gap-2">
          <Button v-if="delivery.status==='draft'" @click="ship(delivery.id)" size="sm">🚚 Ship — Deduct Stock</Button>
          <Button v-if="delivery.status==='draft'" variant="outline" @click="cancel(delivery.id)" size="sm">Cancel</Button>
          <Link :href="route('sales.deliveries.edit', delivery.id)"><Button variant="outline" size="sm">Edit</Button></Link>
        </div>
      </div>
      <div class="bg-card border rounded-xl p-4">
        <div class="grid md:grid-cols-3 gap-3 text-sm">
          <div><span class="text-muted-foreground">Customer</span><div class="font-medium">{{ delivery.customer?.name }}</div></div>
          <div><span class="text-muted-foreground">Warehouse</span><div class="font-medium">{{ delivery.warehouse?.name }}</div></div>
          <div><span class="text-muted-foreground">Delivery Date</span><div class="font-medium">{{ formatDate(delivery.delivery_date) }}</div></div>
          <div><span class="text-muted-foreground">Order</span><div class="font-mono">{{ delivery.order?.order_no || '— Direct' }}</div></div>
          <div><span class="text-muted-foreground">Challan</span><div class="font-mono">{{ delivery.challan_no || '—' }}</div></div>
          <div><span class="text-muted-foreground">Vehicle</span><div class="font-mono">{{ delivery.vehicle_no || '—' }}</div></div>
        </div>
        <div v-if="delivery.notes" class="mt-3 text-sm"><span class="text-muted-foreground">Notes:</span> {{ delivery.notes }}</div>
      </div>
      <div class="bg-card border rounded-xl p-4">
        <h3 class="font-semibold mb-2">Items — {{ delivery.items?.length || 0 }}</h3>
        <StaticTable
            :empty="!delivery?.items?.length"
            empty-title="No lines on this delivery"
            empty-description="Items shipped on this delivery appear here."
        ><thead class="bg-muted/40 text-xs uppercase"><tr><th class="px-3 py-2 text-left">Product</th><th class="px-3 py-2 text-center">Qty</th><th class="px-3 py-2 text-left">Lot/Serial</th></tr></thead>
        <tbody class="divide-y"><tr v-for="it in delivery.items" :key="it.id"><td class="px-3 py-2">{{ it.product_name }} <span class="text-xs text-muted-foreground">{{ it.sku }}</span></td><td class="px-3 py-2 text-center font-mono font-bold">{{ it.qty }}</td><td class="px-3 py-2 text-xs">{{ it.lot_no || '—' }} {{ it.serial_no }}</td></tr></tbody></StaticTable>
      </div>
    </div>
  </AdminLayout>
</template>
