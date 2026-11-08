<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import { formatCurrency } from '../../../../../CORE/Resources/js/Utils/format';
import StaticTable from '../../../../../CORE/Resources/js/Components/StaticTable.vue';
defineProps<{ products:any[]; warehouses:any[]; filters:any; valuation:any; layers:any[] }>();
function change(e:any, key:string){ router.get(route('inventory.reports.fifo'), {...(route().params as any), [key]: e.target.value}); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Inventory' }, { title: 'FIFO Valuation' }]">
    <Head title="FIFO Valuation — Layers" />
    <div class="space-y-4 max-w-6xl mx-auto">
      <div><h1 class="text-xl font-bold">🥞 FIFO Valuation — Earliest Layers First</h1><p class="text-sm text-muted-foreground">For FIFO products, COGS is taken from oldest <code>inventory_fifo_layers</code> (qty_remaining). Weighted products use avg_cost. Filter by product + warehouse to see remaining layers.</p></div>
      <div class="bg-card border rounded-xl p-4 flex gap-3 items-end">
        <div class="space-y-1 flex-1"><label class="text-xs font-medium">Product (FIFO vs Weighted)</label><select :value="filters.product_id" @change="e=>change(e,'product_id')" class="h-9 w-full rounded border bg-background px-3 text-sm"><option value="">— Select product —</option><option v-for="p in products" :key="p.id" :value="p.id">{{ p.name }} — {{ p.sku }} [{{ p.valuation_method }}]</option></select></div>
        <div class="space-y-1"><label class="text-xs font-medium">Warehouse</label><select :value="filters.warehouse_id" @change="e=>change(e,'warehouse_id')" class="h-9 rounded border bg-background px-3 text-sm"><option value="">All WH</option><option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }}</option></select></div>
      </div>

      <div v-if="valuation" class="grid md:grid-cols-4 gap-3">
        <div class="bg-card border rounded-xl p-3 text-center"><div class="text-xs text-muted-foreground">On Hand</div><div class="text-xl font-bold">{{ valuation.qty_on_hand }}</div><div class="text-xs">{{ valuation.sku }} · {{ valuation.valuation_method }}</div></div>
        <div class="bg-card border rounded-xl p-3 text-center"><div class="text-xs text-muted-foreground">Avg / FIFO Unit</div><div class="text-xl font-bold font-mono">{{ formatCurrency(valuation.avg_cost) }}</div><div class="text-xs">avg_cost (FIFO recomputed)</div></div>
        <div class="bg-card border rounded-xl p-3 text-center"><div class="text-xs text-muted-foreground">Total Value</div><div class="text-xl font-bold font-mono">{{ formatCurrency(valuation.total_value) }}</div><div class="text-xs">FIFO remaining layers value</div></div>
        <div class="bg-card border rounded-xl p-3 text-center"><div class="text-xs text-muted-foreground">Remaining Layers</div><div class="text-xl font-bold">{{ layers.length }}</div><div class="text-xs">{{ valuation.valuation_method==='fifo' ? 'FIFO active' : 'Weighted — layers not used for COGS' }}</div></div>
      </div>
      <div v-if="!valuation" class="bg-card border rounded-xl p-8 text-center text-sm text-muted-foreground">Select a product above to view FIFO layers. Try a product with valuation_method = fifo.</div>

      <div v-if="layers.length" class="bg-card border rounded-xl p-4">
        <h3 class="font-semibold mb-2">Remaining FIFO Layers — oldest first (next to be consumed)</h3>
        <StaticTable
          :empty="!layers?.length"
          empty-title="No cost layers"
          empty-description="Choose a product and warehouse with stock on hand to see its FIFO layers."
        >
          <thead class="bg-muted/40 text-xs uppercase"><tr><th class="px-3 py-2 text-left">#</th><th class="px-3 py-2 text-left">Lot No</th><th class="px-3 py-2 text-left">Warehouse</th><th class="px-3 py-2 text-right">Qty In</th><th class="px-3 py-2 text-right">Qty Remain</th><th class="px-3 py-2 text-right">Unit Cost</th><th class="px-3 py-2 text-right">Remaining Value</th><th class="px-3 py-2 text-left">Received</th></tr></thead>
          <tbody class="divide-y"><tr v-for="(l, i) in layers" :key="l.id" :class="i===0?'bg-amber-50':''"><td class="px-3 py-2 font-mono">{{ i+1 }}</td><td class="px-3 py-2 font-mono">{{ l.lot_no || '—' }}</td><td class="px-3 py-2">{{ l.warehouse }}</td><td class="px-3 py-2 text-right">{{ l.qty_in }}</td><td class="px-3 py-2 text-right font-bold">{{ l.qty_remaining }}</td><td class="px-3 py-2 text-right">{{ formatCurrency(l.unit_cost) }}</td><td class="px-3 py-2 text-right font-mono">{{ formatCurrency(Number(l.qty_remaining)*Number(l.unit_cost)) }}</td><td class="px-3 py-2 text-xs">{{ l.received_at }}</td></tr></tbody>
        </StaticTable>
        <p class="text-xs text-muted-foreground mt-2">On next OUT move of <code>qty = N</code>, system will consume layer 1 first, then 2, etc., and COGS = Σ(take × unit_cost).</p>
      </div>
      <div v-else-if="valuation" class="bg-card border rounded-xl p-6 text-center text-sm text-muted-foreground">No remaining FIFO layers — either product is weighted or all stock was issued.</div>
    </div>
  </AdminLayout>
</template>
