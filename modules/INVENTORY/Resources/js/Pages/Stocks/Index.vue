<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Boxes, AlertTriangle, Package, Wallet } from 'lucide-vue-next';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import PageHeader from '../../../../../CORE/Resources/js/Components/PageHeader.vue';
import DataTable, { type DataTableColumn } from '../../../../../CORE/Resources/js/Components/DataTable.vue';
import StatusBadge from '../../../../../CORE/Resources/js/Components/StatusBadge.vue';
import type { Paginator } from '../../../../../CORE/Resources/js/types';
import { formatCurrency } from '../../../../../CORE/Resources/js/Utils/format';
interface Row { id:number; product:{name:string; sku:string; reorder_level:number}; variant?:{name:string}; warehouse:{name:string; code:string}; location?:{name:string}; lot_no?:string; qty_on_hand:string; qty_reserved:string; qty_available:string; avg_cost:string; total_value:string; status:string; last_movement_at?:string }
defineProps<{ stocks: Paginator<Row>; warehouseOptions:any[]; summary:any }>();
const columns: DataTableColumn[] = [
  {key:'product',label:'Product',sortable:true},
  {key:'warehouse',label:'Warehouse'},
  {key:'qty_on_hand',label:'On Hand',align:'center',sortable:true},
  {key:'qty_available',label:'Available',align:'center',sortable:true},
  {key:'avg_cost',label:'Avg Cost',align:'right',sortable:true},
  {key:'total_value',label:'Value',align:'right',sortable:true},
  {key:'status',label:'Status',align:'center'},
];
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'Inventory'},{title:'Stock Overview'}]">
    <Head title="Stock Overview" />
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
      <div class="bg-card border rounded-xl p-3"><p class="text-xs text-muted-foreground flex items-center gap-1"><Wallet class="size-3"/> Total Value</p><p class="text-lg font-bold font-mono">{{ formatCurrency(summary.total_value) }}</p></div>
      <div class="bg-amber-500/5 border border-amber-500/20 rounded-xl p-3"><p class="text-xs text-amber-600 flex items-center gap-1"><AlertTriangle class="size-3"/> Low Stock</p><p class="text-lg font-bold text-amber-600">{{ summary.low_count }}</p></div>
      <div class="bg-rose-500/5 border border-rose-500/20 rounded-xl p-3"><p class="text-xs text-rose-600 flex items-center gap-1"><Package class="size-3"/> Out of Stock</p><p class="text-lg font-bold text-rose-600">{{ summary.out_count }}</p></div>
      <div class="bg-card border rounded-xl p-3"><p class="text-xs text-muted-foreground flex items-center gap-1"><Boxes class="size-3"/> Stock Lines</p><p class="text-lg font-bold">{{ stocks.meta.total }}</p></div>
    </div>
        <PageHeader title="Stock Overview" description="Stock Overview" :icon="Boxes" >
    <DataTable route-name="inventory.stocks.index" title="Stock Overview" :columns="columns" :rows="(stocks.data as unknown as Record<string,unknown>[])" :meta="stocks.meta" search-placeholder="Search product…">
      <template #cell-product="{row}"><div><p class="font-semibold">{{ row.product.name }}</p><p class="text-xs text-muted-foreground font-mono">{{ row.product.sku }} <span v-if="row.lot_no" class="ml-1 px-1 py-0.5 bg-muted rounded">Lot: {{ row.lot_no }}</span></p></div></template>
      <template #cell-warehouse="{row}"><div><p class="text-sm">{{ row.warehouse.name }}</p><p class="text-xs text-muted-foreground">{{ row.location?.name || 'No location' }}</p></div></template>
      <template #cell-qty_on_hand="{row}"><span class="font-mono font-bold" :class="row.status==='out'?'text-rose-600':row.status==='low'?'text-amber-600':'text-emerald-600'">{{ row.qty_on_hand }}</span></template>
      <template #cell-qty_available="{row}"><span class="font-mono">{{ row.qty_available }} <span class="text-xs text-muted-foreground">({{ row.qty_reserved }} resv)</span></span></template>
      <template #cell-avg_cost="{row}"><span class="font-mono text-sm">{{ formatCurrency(Number(row.avg_cost)) }}</span></template>
      <template #cell-total_value="{row}"><span class="font-mono font-semibold">{{ formatCurrency(Number(row.total_value)) }}</span></template>
      <template #cell-status="{row}"><StatusBadge :label="row.status" :tone="row.status==='in'?'success':row.status==='low'?'warning':'danger'" /></template>
    </DataTable>
      </PageHeader>
  </AdminLayout>
</template>
