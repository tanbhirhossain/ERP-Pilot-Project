<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';

import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import StatusBadge from '../../../../../CORE/Resources/js/Components/StatusBadge.vue';
import { formatCurrency, formatDate } from '../../../../../CORE/Resources/js/Utils/format';
import { ref } from 'vue';
import StaticTable from '../../../../../CORE/Resources/js/Components/StaticTable.vue';
defineProps<{ quotation:any }>();
const warehouseId=ref<number|null>(null);
function send(id:number){ router.post(route('sales.quotations.send', id)); }
function accept(id:number){ router.post(route('sales.quotations.accept', id)); }
function convert(id:number){ if(!warehouseId.value) return alert('Select fulfillment warehouse'); router.post(route('sales.quotations.convert', id), {warehouse_id: warehouseId.value}); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Sales & POS' }, { title: 'Quotations', href: route('sales.quotations.index') }, { title: quotation.quotation_no }]">
    <Head :title="quotation.quotation_no" />
    <div class="space-y-4 ">
      <div class="bg-card border rounded-xl p-4">
        <div class="flex justify-between gap-4">
          <div><h1 class="text-xl font-bold flex items-center gap-2">{{ quotation.quotation_no }} <StatusBadge :label="quotation.status" :tone="quotation.status==='accepted'?'success':quotation.status==='sent'?'info':'warning'" /></h1><p class="text-sm text-muted-foreground">{{ quotation.customer?.name }} · {{ formatDate(quotation.quotation_date) }} → {{ quotation.expiry_date?formatDate(quotation.expiry_date):'No expiry' }} · {{ formatCurrency(Number(quotation.total_amount)) }}</p><p v-if="quotation.notes" class="text-sm mt-2">{{ quotation.notes }}</p></div>
          <div class="flex flex-col gap-2">
            <Button v-if="quotation.status==='draft'" @click="send(quotation.id)" size="sm" variant="outline">Send</Button>
            <Button v-if="['draft','sent'].includes(quotation.status)" @click="accept(quotation.id)" size="sm">Accept</Button>
            <Link :href="route('sales.quotations.edit', quotation.id)"><Button variant="outline" size="sm" class="w-full">Edit</Button></Link>
          </div>
        </div>
        <div v-if="['accepted','sent'].includes(quotation.status)" class="mt-3 p-2 bg-violet-500/5 border border-violet-500/20 rounded flex gap-2 items-center">
          <span class="text-sm">Convert to Order — Warehouse:</span>
          <select v-model="warehouseId" class="h-8 rounded border bg-background px-2 text-sm"><option :value="null">Select warehouse</option><option value="1">Main Warehouse</option><option value="2">Sub Warehouse</option></select>
          <Button size="sm" @click="convert(quotation.id)">Convert</Button>
        </div>
      </div>
      <div class="bg-card border rounded-xl p-4">
        <h3 class="font-semibold mb-2">Items — {{ quotation.items.length }}</h3>
        <StaticTable
            :empty="!quotation?.items?.length"
            empty-title="No lines on this quotation"
            empty-description="Items quoted to the customer appear here."
        ><thead class="bg-muted/40 text-xs uppercase"><tr><th class="px-3 py-2 text-left">Product</th><th class="px-3 py-2 text-center">Qty</th><th class="px-3 py-2 text-right">Price</th><th class="px-3 py-2 text-right">Total</th></tr></thead><tbody class="divide-y"><tr v-for="it in quotation.items" :key="it.id"><td class="px-3 py-2">{{ it.product_name }} <span class="text-xs text-muted-foreground">({{ it.sku }})</span></td><td class="px-3 py-2 text-center font-mono">{{ it.qty }}</td><td class="px-3 py-2 text-right font-mono">{{ formatCurrency(Number(it.unit_price)) }}</td><td class="px-3 py-2 text-right font-mono font-bold">{{ formatCurrency(Number(it.line_total)) }}</td></tr></tbody><tfoot class="bg-muted/20 font-bold"><tr><td colspan="3" class="px-3 py-2 text-right">Grand Total</td><td class="px-3 py-2 text-right font-mono">{{ formatCurrency(Number(quotation.total_amount)) }}</td></tr></tfoot></StaticTable>
      </div>
    </div>
  </AdminLayout>
</template>
