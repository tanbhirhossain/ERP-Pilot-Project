<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Boxes, AlertTriangle, Package, Wallet } from 'lucide-vue-next';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import DataTable, { type DataTableColumn } from '@core/Components/DataTable.vue';
import StatusBadge from '@core/Components/StatusBadge.vue';
import type { Paginator } from '@core/types';
import { formatCurrency, formatNumber } from '@core/Utils/format';
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
        <PageHeader title="Stock Overview" description="What is physically in each warehouse, and what it is worth." :icon="Boxes">

      <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
        <div class="bg-card rounded-xl border p-3"><p class="text-muted-foreground flex items-center gap-1 text-xs"><Wallet class="size-3" /> Total Value</p><p class="mt-1 font-mono text-lg font-semibold tabular-nums">{{ formatCurrency(summary.total_value) }}</p></div>
        <div class="bg-card rounded-xl border p-3"><p class="text-muted-foreground flex items-center gap-1 text-xs"><AlertTriangle class="size-3" /> Low Stock</p><p class="text-warning mt-1 text-lg font-semibold tabular-nums">{{ summary.low_count }}</p></div>
        <div class="bg-card rounded-xl border p-3"><p class="text-muted-foreground flex items-center gap-1 text-xs"><Package class="size-3" /> Out of Stock</p><p class="text-danger mt-1 text-lg font-semibold tabular-nums">{{ summary.out_count }}</p></div>
        <div class="bg-card rounded-xl border p-3"><p class="text-muted-foreground flex items-center gap-1 text-xs"><Boxes class="size-3" /> Stock Lines</p><p class="mt-1 text-lg font-semibold tabular-nums">{{ stocks.meta.total }}</p></div>
      </div>
    <DataTable route-name="inventory.stocks.index" title="Stock Overview" :columns="columns" :rows="stocks.data" :meta="stocks.meta" search-placeholder="Search product…">
      <template #cell-product="{row}"><div><p class="font-semibold">{{ row.product.name }}</p><p class="text-xs text-muted-foreground font-mono">{{ row.product.sku }} <span v-if="row.lot_no" class="ml-1 px-1 py-0.5 bg-muted rounded">Lot: {{ row.lot_no }}</span></p></div></template>
      <template #cell-warehouse="{row}"><div class="leading-tight"><p class="text-sm">{{ row.warehouse.name }}</p><p v-if="row.location?.name" class="text-muted-foreground mt-0.5 text-xs">{{ row.location.name }}</p></div></template>
      <!--
        `qty_on_hand` arrives as a decimal string ("10.0000"). Interpolating it printed four
        trailing zeros on every line — a quantity in a stock list is a count of pieces, and the
        database's precision is not a fact about the goods. Same for the reservation, which was
        shown as "(0.0000 resv)" on every row: a zero that appears everywhere is noise, so it is
        only rendered when something is actually reserved.
      -->
      <template #cell-qty_on_hand="{row}">
        <span class="font-mono text-sm font-semibold tabular-nums" :class="row.status === 'out' ? 'text-danger' : row.status === 'low' ? 'text-warning' : 'text-foreground'">{{ formatNumber(Number(row.qty_on_hand)) }}</span>
      </template>
      <template #cell-qty_available="{row}">
        <div class="flex flex-col items-center leading-tight">
          <span class="font-mono text-sm tabular-nums">{{ formatNumber(Number(row.qty_available)) }}</span>
          <span v-if="Number(row.qty_reserved) > 0" class="text-muted-foreground mt-0.5 text-[11px] tabular-nums">{{ formatNumber(Number(row.qty_reserved)) }} reserved</span>
        </div>
      </template>
      <template #cell-avg_cost="{row}"><span class="font-mono text-sm">{{ formatCurrency(Number(row.avg_cost)) }}</span></template>
      <template #cell-total_value="{row}"><span class="font-mono font-semibold">{{ formatCurrency(Number(row.total_value)) }}</span></template>
      <template #cell-status="{row}"><StatusBadge :label="row.status" :tone="row.status==='in'?'success':row.status==='low'?'warning':'danger'" /></template>
    </DataTable>
      </PageHeader>
  </AdminLayout>
</template>
