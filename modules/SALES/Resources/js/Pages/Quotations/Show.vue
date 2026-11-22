<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import type { Quotation } from '@sales/types';
import { Button } from '@/components/ui/button';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import StatusBadge from '@core/Components/StatusBadge.vue';
import { formatCurrency, formatDate } from '@core/Utils/format';
import StaticTable from '@core/Components/StaticTable.vue';
defineProps<{ quotation:Quotation }>();
function convert(id:number){ router.post(route('sales.quotations.convert', id)); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Sales & POS' }, { title: 'Quotations', href: route('sales.quotations.index') }, { title: quotation.quotation_no }]">
    <Head :title="quotation.quotation_no" />    <PageHeader :title="quotation.quotation_no" :description="`${quotation.customer?.name} · ${formatDate(quotation.quotation_date)} → ${quotation.expiry_date?formatDate(quotation.expiry_date):'No expiry'} · ${formatCurrency(Number(quotation.total_amount))}`">
      <template #badge>
        <StatusBadge :label="quotation.status" :tone="quotation.status==='accepted'?'success':quotation.status==='sent'?'info':'warning'" />
      </template>
      <template #actions>
        <div class="flex gap-2">
            <Button v-if="quotation.status==='sent' || quotation.status==='draft'" @click="convert(quotation.id)" size="sm" class="bg-emerald-600 hover:bg-emerald-700">Convert to Order</Button>
            <Link :href="route('sales.quotations.edit', quotation.id)"><Button variant="outline" size="sm">Edit</Button></Link>
          </div>
      </template>


      <div class="bg-card border rounded-xl p-4">
        <h3 class="font-semibold mb-2">Items — {{ quotation.items.length }}</h3>
        <StaticTable
          :empty="!quotation?.items?.length"
          empty-title="No lines on this quotation"
          empty-description="Items quoted on this document appear here."
        >
          <thead class="bg-muted/40 text-xs uppercase"><tr><th class="px-3 py-2 text-left">Product</th><th class="px-3 py-2 text-center">Qty</th><th class="px-3 py-2 text-right">Price</th><th class="px-3 py-2 text-right">Total</th></tr></thead>
          <tbody class="divide-y"><tr v-for="it in quotation.items" :key="it.id"><td class="px-3 py-2">{{ it.product_name }}</td><td class="px-3 py-2 text-center font-mono">{{ it.qty }}</td><td class="px-3 py-2 text-right font-mono">{{ formatCurrency(Number(it.unit_price)) }}</td><td class="px-3 py-2 text-right font-mono font-bold">{{ formatCurrency(Number(it.line_total)) }}</td></tr></tbody>
        </StaticTable>
          </div>
    </PageHeader>
  </AdminLayout>
</template>
