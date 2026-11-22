<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import type { Product } from '@inventory/types';
import { Pencil, Tag, Warehouse, Layers, Boxes } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import StatusBadge from '@core/Components/StatusBadge.vue';
import { formatCurrency, formatDate } from '@core/Utils/format';
type ProductDetail = Omit<Product, 'stocks'> & {
  stocks?: Array<{ id: number; warehouse: string | null; location: string | null; qty_on_hand: string | number; avg_cost: string | number }>;
  moves?: Array<{ id: number; reference_no: string | null; reference_type: string | null; direction: string; qty: string | number; moved_at: string | null; created_at: string }>;
};
defineProps<{ product: ProductDetail; totalStock: number; totalValue: number }>();
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Inventory' }, { title: 'Products', href: route('inventory.products.index') }, { title: product.sku }]">
    <Head :title="`${product.sku} — ${product.name}`" />
    <PageHeader :title="product.name" :description="`${product.category?.name || 'No category'} · ${product.brand?.name || 'No brand'} · ${product.uom?.symbol || 'pcs'} · ${product.type}`" :icon="Boxes">
      <template #badge>
        <span class="font-mono text-sm px-2 py-0.5 rounded bg-primary/10 text-primary">{{ product.sku }}</span> <StatusBadge :label="product.status" :tone="product.is_active?'success':'neutral'" />
      </template>
      <template #actions>
        <Link :href="route('inventory.products.edit', product.id)"><Button variant="outline" size="sm"><Pencil class="size-4"/> Edit</Button></Link>
      </template>

      <div class="flex flex-col items-start gap-4 sm:flex-row sm:items-center">
        <div class="size-16 rounded-xl bg-primary/10 ring-1 ring-inset ring-primary/15 grid place-items-center text-primary font-bold text-xl shrink-0">{{ product.name.substring(0,2).toUpperCase() }}</div>
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 w-full min-w-0 flex-1">
          <div class="bg-muted/40 rounded-lg p-3 border"><p class="text-xs text-muted-foreground">Total Stock</p><p class="text-lg font-bold font-mono">{{ totalStock }}</p></div>
          <div class="bg-muted/40 rounded-lg p-3 border"><p class="text-xs text-muted-foreground">Total Value</p><p class="text-lg font-bold font-mono">{{ formatCurrency(totalValue) }}</p></div>
          <div class="bg-muted/40 rounded-lg p-3 border"><p class="text-xs text-muted-foreground">Selling Price</p><p class="text-lg font-bold font-mono text-emerald-600">{{ formatCurrency(Number(product.selling_price)) }}</p></div>
          <div class="bg-muted/40 rounded-lg p-3 border"><p class="text-xs text-muted-foreground">Reorder At</p><p class="text-lg font-bold">{{ product.reorder_level }}</p></div>
        </div>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div class="bg-card border rounded-xl p-4 space-y-2">
          <h3 class="font-semibold flex items-center gap-2"><Tag class="size-4"/> Pricing & Details</h3>
          <div class="space-y-1 text-sm">
            <div class="flex justify-between"><span class="text-muted-foreground">Cost</span><span class="font-mono">{{ formatCurrency(Number(product.cost_price)) }}</span></div>
            <div class="flex justify-between"><span class="text-muted-foreground">Price</span><span class="font-mono font-semibold">{{ formatCurrency(Number(product.selling_price)) }}</span></div>
            <div class="flex justify-between"><span class="text-muted-foreground">MRP</span><span class="font-mono">{{ formatCurrency(Number(product.mrp)) }}</span></div>
            <div class="flex justify-between"><span class="text-muted-foreground">VAT</span><span>{{ product.vat_rate }}%</span></div>
            <div class="flex justify-between"><span class="text-muted-foreground">Valuation</span><span class="capitalize">{{ product.valuation_method }}</span></div>
            <div class="flex justify-between"><span class="text-muted-foreground">Barcode</span><span class="font-mono">{{ product.barcode || '—' }}</span></div>
            <div class="flex justify-between"><span class="text-muted-foreground">HS Code</span><span>{{ product.hs_code || '—' }}</span></div>
          </div>
        </div>
        <div class="bg-card border rounded-xl p-4 space-y-2">
          <h3 class="font-semibold flex items-center gap-2"><Warehouse class="size-4"/> Stock by Warehouse</h3>
          <div v-if="!product.stocks?.length" class="text-sm text-muted-foreground py-4 text-center">No stock yet — receive opening stock via Adjustment.</div>
          <div v-for="s in product.stocks" :key="s.id" class="flex justify-between items-center border rounded-lg p-2">
            <div><p class="font-medium text-sm">{{ s.warehouse }}</p><p class="text-xs text-muted-foreground">{{ s.location || 'No location' }}</p></div>
            <div class="text-right"><p class="font-mono font-bold">{{ s.qty_on_hand }}</p><p class="text-xs text-muted-foreground">{{ formatCurrency(Number(s.avg_cost)) }} avg</p></div>
          </div>
        </div>
      </div>

      <div class="bg-card border rounded-xl p-4">
        <h3 class="font-semibold flex items-center gap-2 mb-2"><Layers class="size-4"/> Movements</h3>
        <div v-if="!product.moves?.length" class="text-sm text-muted-foreground py-4 text-center">No movements yet.</div>
        <div v-for="m in product.moves" :key="m.id" class="flex justify-between text-sm border-b py-1"><span>{{ m.reference_no || '—' }} · {{ m.reference_type || m.direction }} · {{ formatDate(m.moved_at || m.created_at) }}</span><span class="font-mono">{{ m.direction === 'in' ? '+' : '−' }}{{ m.qty }}</span></div>
      </div>
    </PageHeader>
  </AdminLayout>
</template>
