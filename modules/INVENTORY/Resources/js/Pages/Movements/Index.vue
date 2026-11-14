<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { History, ArrowDown, ArrowUp } from 'lucide-vue-next';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import DataTable, { type DataTableColumn } from '@core/Components/DataTable.vue';
import StatusBadge from '@core/Components/StatusBadge.vue';
import type { Paginator } from '@core/types';
import { formatDate } from '@core/Utils/format';
interface Row { id:number; move_no:string; product?:{name:string; sku:string}; warehouse?:{name:string}; qty:string; direction:string; unit_cost:string; total_cost:string; reference_type:string; reference_no?:string; mover?:string; moved_at:string }
defineProps<{ movements: Paginator<Row>; warehouseOptions:any[] }>();
const columns: DataTableColumn[] = [
  {key:'move_no',label:'Move No',sortable:true,width:'8rem'},
  {key:'product',label:'Product'},
  {key:'warehouse',label:'Warehouse'},
  {key:'qty',label:'Qty',align:'center',sortable:true},
  {key:'direction',label:'Dir',align:'center'},
  {key:'reference_type',label:'Ref'},
  {key:'moved_at',label:'Date',sortable:true,width:'10rem'},
];
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'Inventory'},{title:'Stock Ledger'}]">
    <Head title="Stock Ledger" />
    <PageHeader title="Stock Ledger" description="Append-only audit of every stock movement — source for valuation & audit." :icon="History" >
    <DataTable route-name="inventory.movements.index" title="Stock Ledger" :columns="columns" :rows="movements.data" :meta="movements.meta" search-placeholder="Search move no, ref…">
      <template #cell-move_no="{row}"><span class="font-mono text-xs font-semibold bg-slate-500/10 text-slate-600 px-1.5 py-0.5 rounded">{{ row.move_no }}</span></template>
      <template #cell-product="{row}"><div><p class="font-medium text-sm">{{ row.product?.name || '—' }}</p><p class="text-xs text-muted-foreground font-mono">{{ row.product?.sku }}</p></div></template>
      <template #cell-warehouse="{row}"><span class="text-sm">{{ row.warehouse?.name || '—' }}</span></template>
      <template #cell-qty="{row}"><span class="font-mono font-bold flex items-center justify-center gap-1" :class="row.direction==='in'?'text-emerald-600':'text-rose-600'"><ArrowDown v-if="row.direction==='in'" class="size-3"/> <ArrowUp v-else class="size-3"/> {{ row.qty }}</span></template>
      <template #cell-direction="{row}"><StatusBadge :label="row.direction" :tone="row.direction==='in'?'success':'danger'" /></template>
      <template #cell-reference_type="{row}"><span class="text-xs px-1.5 py-0.5 bg-muted rounded capitalize">{{ row.reference_type }}</span> <span v-if="row.reference_no" class="text-xs font-mono">{{ row.reference_no }}</span></template>
      <template #cell-moved_at="{row}"><span class="text-xs text-muted-foreground">{{ formatDate(row.moved_at) }}</span></template>
    </DataTable>
      </PageHeader>
  </AdminLayout>
</template>
