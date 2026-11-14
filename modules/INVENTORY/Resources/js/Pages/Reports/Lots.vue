<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import StaticTable from '@core/Components/StaticTable.vue';
defineProps<{ lots:any; products:any[]; filters:any }>();
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Inventory' }, { title: 'Lots & Expiry' }]">
    <Head title="Lots & Expiry Tracking" />
    <div class="space-y-4 max-w-6xl mx-auto">
      <div><PageHeader title="Lot / Batch Tracking + Expiry" /><p class="text-sm text-muted-foreground">Batch-tracked products generate <code>LOT-XXXXX</code> on receipt. Track <code>qty_remaining</code> per FIFO layer + expiry warnings.</p></div>
      <div class="bg-card border rounded-xl p-4 flex gap-2">
        <select :value="filters.product_id" @change="(e:any)=>router.get(route('inventory.reports.lots'), {...filters, product_id: e.target.value})" class="h-9 rounded border bg-background px-3 text-sm flex-1"><option value="">All batch-tracked products</option><option v-for="p in products" :key="p.id" :value="p.id">{{ p.name }} — {{ p.sku }}</option></select>
        <label class="flex items-center gap-1 text-sm"><input type="checkbox" :checked="!!filters.expired" @change="(e:any)=>router.get(route('inventory.reports.lots'), {...filters, expired: e.target.checked?1:''})" /> Expired only</label>
      </div>
      <div class="bg-card border rounded-xl p-4">
        <StaticTable
          :empty="!lots?.data?.length"
          empty-title="No lots found"
          empty-description="Mark a product as batch-tracked, then receive stock to create its lots automatically."
        >
          <thead class="bg-muted/40 text-xs uppercase"><tr><th class="px-3 py-2 text-left">Lot No</th><th class="px-3 py-2 text-left">Product</th><th class="px-3 py-2 text-left">Warehouse</th><th class="px-3 py-2 text-left">Mfg → Expiry</th><th class="px-3 py-2 text-right">Received</th><th class="px-3 py-2 text-right">Remaining</th><th class="px-3 py-2 text-right">Unit Cost</th><th class="px-3 py-2 text-center">Expired?</th></tr></thead>
          <tbody class="divide-y"><tr v-for="l in lots.data" :key="l.id" :class="l.is_expired?'bg-red-50':''"><td class="px-3 py-2 font-mono">{{ l.lot_no }}</td><td class="px-3 py-2">{{ l.product?.name }} <span class="text-xs text-muted-foreground">{{ l.product?.sku }}</span></td><td class="px-3 py-2">{{ l.warehouse || '—' }}</td><td class="px-3 py-2 text-xs">{{ l.manufacture_date || '—' }} → {{ l.expiry_date || '—' }}</td><td class="px-3 py-2 text-right">{{ l.qty_received }}</td><td class="px-3 py-2 text-right font-bold">{{ l.qty_remaining }}</td><td class="px-3 py-2 text-right">{{ l.unit_cost }}</td><td class="px-3 py-2 text-center"><span v-if="l.is_expired" class="px-2 py-1 rounded bg-red-100 text-red-700 text-xs">EXPIRED</span><span v-else class="text-xs text-muted-foreground">ok</span></td></tr></tbody>
        </StaticTable>
      </div>
    </div>
  </AdminLayout>
</template>
