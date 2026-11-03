<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import { formatCurrency } from '../../../../../CORE/Resources/js/Utils/format';
defineProps<{ stocks:any[]; stats:any }>();
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'Inventory',href:route('inventory.products.index')},{title:'Reorder & ABC'}]">
    <Head title="Reorder & ABC Analysis" />
    <div class="space-y-4 max-w-7xl mx-auto">
      <div><h1 class="text-xl font-bold">📉 Reorder Alert + ABC Classification</h1><p class="text-sm text-muted-foreground">Reorder when <code>on_hand ≤ reorder_level</code>. ABC: A=80% value (focus), B=15%, C=5% (tail). Calculated from <code>inventory_stocks.total_value</code>.</p></div>
      <div class="grid grid-cols-2 md:grid-cols-6 gap-3">
        <div class="bg-card border rounded-xl p-3 text-center"><div class="text-xs text-muted-foreground">Total SKUs</div><div class="text-xl font-bold">{{ stats.total_products }}</div></div>
        <div class="bg-amber-100 border border-amber-300 rounded-xl p-3 text-center"><div class="text-xs text-amber-800">Low Stock</div><div class="text-xl font-bold text-amber-700">{{ stats.low_stock }}</div></div>
        <div class="bg-red-100 border border-red-300 rounded-xl p-3 text-center"><div class="text-xs text-red-800">Out of Stock</div><div class="text-xl font-bold text-red-700">{{ stats.out_stock }}</div></div>
        <div class="bg-card border rounded-xl p-3 text-center"><div class="text-xs text-muted-foreground">Inventory Value</div><div class="font-mono font-bold">{{ formatCurrency(stats.total_value) }}</div></div>
        <div class="bg-emerald-50 border rounded-xl p-3 text-center"><div class="text-xs">A: {{ stats.a_items }} · B: {{ stats.b_items }} · C: {{ stats.c_items }}</div><div class="text-xs text-muted-foreground">ABC split</div></div>
        <div class="bg-card border rounded-xl p-3 text-center"><Link :href="route('inventory.products.barcode-print')"><span class="text-sm text-primary hover:underline">→ Print Barcodes</span></Link></div>
      </div>
      <div class="bg-card border rounded-xl p-4">
        <h3 class="font-semibold mb-2">All SKUs — sort by value (ABC) + reorder needed</h3>
        <table class="w-full text-sm">
          <thead class="bg-muted/40 text-xs uppercase"><tr><th class="px-2 py-2 text-left">ABC</th><th class="px-2 py-2 text-left">SKU — Product</th><th class="px-2 py-2 text-left">Category</th><th class="px-2 py-2 text-center">Valuation</th><th class="px-2 py-2 text-right">On Hand</th><th class="px-2 py-2 text-right">Value</th><th class="px-2 py-2 text-right">Reorder Level</th><th class="px-2 py-2 text-right">Needed</th><th class="px-2 py-2 text-center">Status</th></tr></thead>
          <tbody class="divide-y"><tr v-for="r in stocks" :key="r.product_id" :class="r.status==='low' ? 'bg-amber-50' : r.status==='out'?'bg-red-50':''">
            <td class="px-2 py-2 text-center"><span :class="['px-2 py-1 rounded text-xs font-bold', r.abc==='A'?'bg-emerald-100 text-emerald-800':r.abc==='B'?'bg-blue-100 text-blue-800':'bg-gray-100']">{{ r.abc }}</span><div class="text-[10px] text-muted-foreground">{{ r.cumulative_pct }}%</div></td>
            <td class="px-2 py-2"><div class="font-medium">{{ r.name }}</div><div class="text-xs text-muted-foreground">{{ r.sku }}</div></td>
            <td class="px-2 py-2 text-xs">{{ r.category || '—' }}</td>
            <td class="px-2 py-2 text-center text-xs">{{ r.valuation_method }}</td>
            <td class="px-2 py-2 text-right font-mono">{{ r.on_hand }}</td>
            <td class="px-2 py-2 text-right font-mono">{{ formatCurrency(r.total_value) }}</td>
            <td class="px-2 py-2 text-right">{{ r.reorder_level }}</td>
            <td class="px-2 py-2 text-right font-bold">{{ r.needed || '—' }}</td>
            <td class="px-2 py-2 text-center"><span :class="['px-2 py-1 rounded text-xs', r.status==='out'?'bg-red-100 text-red-700':r.status==='low'?'bg-amber-100 text-amber-700':'bg-emerald-100 text-emerald-700']">{{ r.status }}</span></td>
          </tr></tbody>
        </table>
      </div>
    </div>
  </AdminLayout>
</template>
