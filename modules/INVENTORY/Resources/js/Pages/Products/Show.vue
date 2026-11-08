<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Pencil, Tag, Warehouse, Layers } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import StatusBadge from '../../../../../CORE/Resources/js/Components/StatusBadge.vue';
import { formatCurrency, formatDate } from '../../../../../CORE/Resources/js/Utils/format';
defineProps<{ product:any; totalStock:number; totalValue:number }>();
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Inventory' }, { title: 'Products', href: route('inventory.products.index') }, { title: product.sku }]">
    <Head :title="`${product.sku} — ${product.name}`" />
    <div class="space-y-4">
      <div class="bg-card border rounded-xl p-4">
        <div class="flex gap-4">
          <div class="size-16 rounded-xl bg-gradient-to-br from-violet-500 to-indigo-500 grid place-items-center text-white font-bold text-xl">{{ product.name.substring(0,2).toUpperCase() }}</div>
          <div class="flex-1">
            <h1 class="text-xl font-bold flex items-center gap-2">{{ product.name }} <span class="font-mono text-sm px-2 py-0.5 rounded bg-violet-500/10 text-violet-600">{{ product.sku }}</span> <StatusBadge :label="product.status" :tone="product.is_active?'success':'neutral'" /></h1>
            <p class="text-sm text-muted-foreground">{{ product.category?.name || 'No category' }} · {{ product.brand?.name || 'No brand' }} · {{ product.uom?.symbol || 'pcs' }} · {{ product.type }}</p>
          </div>
          <Link :href="route('inventory.products.edit', product.id)"><Button variant="outline" size="sm"><Pencil class="size-4"/> Edit</Button></Link>
        </div>
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mt-4">
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
          <div v-if="!product.stocks.length" class="text-sm text-muted-foreground py-4 text-center">No stock yet — receive opening stock via Adjustment.</div>
          <div v-for="s in product.stocks" :key="s.id" class="flex justify-between items-center border rounded-lg p-2">
            <div><p class="font-medium text-sm">{{ s.warehouse }}</p><p class="text-xs text-muted-foreground">{{ s.location || 'No location' }}</p></div>
            <div class="text-right"><p class="font-mono font-bold">{{ s.qty_on_hand }}</p><p class="text-xs text-muted-foreground">{{ formatCurrency(Number(s.avg_cost)) }} avg</p></div>
          </div>
        </div>
      </div>

      <div class="bg-card border rounded-xl p-4">
        <h3 class="font-semibold flex items-center gap-2 mb-3"><Layers class="size-4"/> Variants — {{ product.variants.length }} & Suppliers — {{ product.suppliers.length }}</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <p class="text-sm font-medium mb-2">Variants</p>
            <div v-if="!product.variants.length" class="text-sm text-muted-foreground">No variants.</div>
            <div v-for="v in product.variants" :key="v.id" class="border rounded p-2 text-sm"><p class="font-medium">{{ v.name }} — {{ v.sku }}</p><p class="text-xs text-muted-foreground">{{ formatCurrency(Number(v.selling_price)) }}</p></div>
          </div>
          <div>
            <p class="text-sm font-medium mb-2">Suppliers (PARTNER)</p>
            <div v-if="!product.suppliers.length" class="text-sm text-muted-foreground">No suppliers linked.</div>
            <div v-for="s in product.suppliers" :key="s.id" class="border rounded p-2 text-sm"><p class="font-medium">{{ s.partner?.name || '—' }} ({{ s.partner?.partner_code }})</p><p class="text-xs text-muted-foreground">SKU: {{ s.supplier_sku || '—' }} · Primary: {{ s.is_primary ? 'Yes' : 'No' }}</p></div>
          </div>
        </div>
      </div>

      <div class="bg-card border rounded-xl p-4">
        <h3 class="font-semibold mb-2">Recent Movements</h3>
        <div v-if="!product.moves.length" class="text-sm text-muted-foreground py-4 text-center">No movements.</div>
        <div class="space-y-1">
          <div v-for="m in product.moves" :key="m.id" class="flex justify-between text-sm border-b py-1"><span>{{ m.move_no }} — {{ m.direction }} {{ m.qty }} · {{ m.reference_type }}</span><span class="text-muted-foreground">{{ formatDate(m.moved_at) }}</span></div>
        </div>
      </div>
    </div>
  </AdminLayout>
</template>
