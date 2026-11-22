<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import type { PriceList } from '@partner/types';
import { Pencil, Tag } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import StatusBadge from '@core/Components/StatusBadge.vue';
import { formatCurrency, formatDate } from '@core/Utils/format';
import StaticTable from '@core/Components/StaticTable.vue';
defineProps<{ priceList:PriceList }>();
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Ven & Cust' }, { title: 'Price Lists', href: route('partner.pricelists.index') }, { title: priceList.code }]">
    <Head :title="`${priceList.code} — ${priceList.name}`" />
    <div class="space-y-4">
    <PageHeader
      :title="priceList.name"
      :icon="Tag"
      :description="`${priceList.currency} · ${priceList.notes || 'No notes'}`"
      :stats="[
        { label:'Currency', value: priceList.currency },
        { label:'Effective From', value: priceList.effective_from ? formatDate(priceList.effective_from) : '—' },
        { label:'Effective To', value: priceList.effective_to ? formatDate(priceList.effective_to) : '—' },
        { label:'Created', value: formatDate(priceList.created_at) },
      ]"
    >
      <template #badge>
        <span class="font-mono text-sm px-2 py-0.5 rounded bg-violet-500/10 text-violet-600">{{ priceList.code }}</span>
        <StatusBadge v-if="priceList.is_default" label="Default" tone="success"/>
        <StatusBadge :label="priceList.is_active?'Active':'Inactive'" :tone="priceList.is_active?'success':'neutral'"/>
      </template>
      <template #actions>
        <Link :href="route('partner.pricelists.edit', priceList.id)"><Button variant="outline" size="sm"><Pencil class="size-4"/> Edit</Button></Link>
      </template>
    </PageHeader>
      <div class="bg-card border rounded-xl p-4">
        <h3 class="font-semibold mb-3">Price Items — {{ priceList.items.length }}</h3>
        <div v-if="!priceList.items.length" class="text-sm text-muted-foreground py-8 text-center">No items.</div>
        <StaticTable
            :empty="!priceList?.items?.length"
            empty-title="No products on this price list"
            empty-description="Products priced by this list appear here."
        >
            <thead class="bg-muted/40 border-y text-xs uppercase tracking-wide text-muted-foreground"><tr><th class="px-3 py-2 text-left">SKU</th><th class="px-3 py-2 text-left">Product</th><th class="px-3 py-2 text-right">Price</th><th class="px-3 py-2 text-center">Min Qty</th><th class="px-3 py-2 text-center">Discount</th></tr></thead>
            <tbody class="divide-y"><tr v-for="it in priceList.items" :key="it.id" class="hover:bg-muted/30"><td class="px-3 py-2 font-mono">{{ it.sku || '—' }}</td><td class="px-3 py-2">{{ it.product_name || '—' }}</td><td class="px-3 py-2 text-right font-mono">{{ formatCurrency(Number(it.unit_price)) }}</td><td class="px-3 py-2 text-center">{{ it.min_qty }}</td><td class="px-3 py-2 text-center">{{ it.discount_pct }}%</td></tr></tbody>
          </StaticTable>
      </div>
    </div>
  </AdminLayout>
</template>
