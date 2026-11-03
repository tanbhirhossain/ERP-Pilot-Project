<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Pencil, Tag } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import StatusBadge from '../../../../../CORE/Resources/js/Components/StatusBadge.vue';
import { formatCurrency, formatDate } from '../../../../../CORE/Resources/js/Utils/format';
defineProps<{ priceList:any }>();
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'Price Lists',href:route('partner.pricelists.index')},{title:priceList.code}]">
    <Head :title="`${priceList.code} — ${priceList.name}`" />
    <div class="space-y-4 max-w-4xl mx-auto">
      <div class="bg-card border rounded-xl p-4">
        <div class="flex justify-between gap-4">
          <div><h1 class="text-xl font-bold flex items-center gap-2"><Tag class="size-5 text-primary"/> {{ priceList.name }} <span class="font-mono text-sm px-2 py-0.5 rounded bg-violet-500/10 text-violet-600">{{ priceList.code }}</span></h1><p class="text-sm text-muted-foreground">{{ priceList.currency }} · {{ priceList.notes || 'No notes' }}</p><div class="flex gap-2 mt-2"><StatusBadge v-if="priceList.is_default" label="Default" tone="success"/><StatusBadge :label="priceList.is_active?'Active':'Inactive'" :tone="priceList.is_active?'success':'neutral'"/></div></div>
          <Link :href="route('partner.pricelists.edit', priceList.id)"><Button variant="outline" size="sm"><Pencil class="size-4"/> Edit</Button></Link>
        </div>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mt-4">
          <div class="bg-muted/40 rounded-lg p-3 border"><p class="text-xs text-muted-foreground">Currency</p><p class="font-bold">{{ priceList.currency }}</p></div>
          <div class="bg-muted/40 rounded-lg p-3 border"><p class="text-xs text-muted-foreground">Effective From</p><p class="font-bold">{{ priceList.effective_from ? formatDate(priceList.effective_from) : '—' }}</p></div>
          <div class="bg-muted/40 rounded-lg p-3 border"><p class="text-xs text-muted-foreground">Effective To</p><p class="font-bold">{{ priceList.effective_to ? formatDate(priceList.effective_to) : '—' }}</p></div>
          <div class="bg-muted/40 rounded-lg p-3 border"><p class="text-xs text-muted-foreground">Created</p><p class="font-bold">{{ formatDate(priceList.created_at) }}</p></div>
        </div>
      </div>
      <div class="bg-card border rounded-xl p-4">
        <h3 class="font-semibold mb-3">Price Items — {{ priceList.items.length }}</h3>
        <div v-if="!priceList.items.length" class="text-sm text-muted-foreground py-8 text-center">No items.</div>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-muted/40 border-y text-xs uppercase tracking-wide text-muted-foreground"><tr><th class="px-3 py-2 text-left">SKU</th><th class="px-3 py-2 text-left">Product</th><th class="px-3 py-2 text-right">Price</th><th class="px-3 py-2 text-center">Min Qty</th><th class="px-3 py-2 text-center">Discount</th></tr></thead>
            <tbody class="divide-y"><tr v-for="it in priceList.items" :key="it.id" class="hover:bg-muted/30"><td class="px-3 py-2 font-mono">{{ it.sku || '—' }}</td><td class="px-3 py-2">{{ it.product_name || '—' }}</td><td class="px-3 py-2 text-right font-mono">{{ formatCurrency(Number(it.unit_price)) }}</td><td class="px-3 py-2 text-center">{{ it.min_qty }}</td><td class="px-3 py-2 text-center">{{ it.discount_pct }}%</td></tr></tbody>
          </table>
        </div>
      </div>
    </div>
  </AdminLayout>
</template>
