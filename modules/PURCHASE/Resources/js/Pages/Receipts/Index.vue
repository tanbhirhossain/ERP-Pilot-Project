<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Truck, Plus, Eye } from 'lucide-vue-next';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import DataTable, { type DataTableColumn } from '@core/Components/DataTable.vue';
import StatusBadge from '@core/Components/StatusBadge.vue';

import { formatCurrency } from '@core/Utils/format';
const props = defineProps<{ orders?: any, receipts?: any, bills?: any }>();
const data = props.orders ?? props.receipts ?? props.bills;
const columns: DataTableColumn[] = [
  {key:'receipt_no',label:'GRN No',sortable:true},
  {key:'vendor',label:'Vendor'},
  {key:'total_amount',label:'Total',align:'right'},
  {key:'status',label:'Status',align:'center',sortable:true},
  {key:'actions',label:'',align:'right',exportable:false,width:'6rem'},
];
function tone(s:string){ if(['posted','received','confirmed'].includes(s)) return 'success'; if(s==='draft') return 'warning'; if(s==='cancelled') return 'danger'; return 'neutral'; }
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'Purchase'},{title:'Goods Receipts (GRN)'}]">
    <Head title="Goods Receipts (GRN)" />
    <PageHeader title="Goods Receipts (GRN)" description="Stock IN • PO → GRN posted = Inventory 1200 Dr" :icon="Truck" :actions="[{ label:'New Goods Receipts (GRN)', route:'purchase.receipts.create', icon: Plus }]">
    <DataTable route-name="purchase.receipts.index" title="Goods Receipts (GRN)" :columns="columns" :rows="data.data" :meta="data.meta" search-placeholder="Search...">
      <template #cell-receipt_no="{row}"><span class="font-mono text-xs font-bold px-1.5 py-0.5 rounded bg-amber-500/10 text-amber-700">{{ row.receipt_no }}</span></template>
      <template #cell-vendor="{row}"><span class="text-sm">{{ row.vendor?.name || '—' }}</span></template>
      <template #cell-total_amount="{row}"><span class="font-mono font-bold">{{ formatCurrency(Number(row.total_amount)) }}</span></template>
      <template #cell-status="{row}"><StatusBadge :label="row.status" :tone="tone(row.status)" /></template>
      <template #cell-actions="{row}"><Link :href="route('purchase.receipts.show', row.id)" class="size-8 grid place-items-center rounded-md hover:bg-accent"><Eye class="size-4" /></Link></template>
    </DataTable>
      </PageHeader>
  </AdminLayout>
</template>