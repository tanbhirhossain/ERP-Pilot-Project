<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { FileText, Plus, Eye } from 'lucide-vue-next';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import DataTable, { type DataTableColumn } from '@core/Components/DataTable.vue';
import StatusBadge from '@core/Components/StatusBadge.vue';

import { formatCurrency } from '@core/Utils/format';
const props = defineProps<{ orders?: any, receipts?: any, bills?: any }>();
const data = props.orders ?? props.receipts ?? props.bills;
const columns: DataTableColumn[] = [
  {key:'bill_no',label:'Bill No',sortable:true},
  {key:'vendor',label:'Vendor'},
  {key:'total_amount',label:'Total',align:'right'},
  {key:'status',label:'Status',align:'center',sortable:true},
  {key:'actions',label:'',align:'right',exportable:false,width:'6rem'},
];
function tone(s:string){ if(['posted','received','confirmed'].includes(s)) return 'success'; if(s==='draft') return 'warning'; if(s==='cancelled') return 'danger'; return 'neutral'; }
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'Purchase'},{title:'Vendor Bills'}]">
    <Head title="Vendor Bills" />
    <PageHeader title="Vendor Bills" description="AP • GRN → Bill posted = AP 2100 Cr • Payment clears AP" :icon="FileText" :actions="[{ label:'New Vendor Bill', route:'purchase.bills.create', icon: Plus }]">
    <DataTable route-name="purchase.bills.index" title="Vendor Bills" :columns="columns" :rows="data.data" :meta="data.meta" search-placeholder="Search...">
      <template #cell-bill_no="{row}"><span class="font-mono text-xs font-bold px-1.5 py-0.5 rounded bg-amber-500/10 text-amber-700">{{ row.bill_no }}</span></template>
      <template #cell-vendor="{row}"><span class="text-sm">{{ row.vendor?.name || '—' }}</span></template>
      <template #cell-total_amount="{row}"><span class="font-mono font-bold">{{ formatCurrency(Number(row.total_amount)) }}</span></template>
      <template #cell-status="{row}"><StatusBadge :label="row.status" :tone="tone(row.status)" /></template>
      <template #cell-actions="{row}"><Link :href="route('purchase.bills.show', row.id)" class="size-8 grid place-items-center rounded-md hover:bg-accent"><Eye class="size-4" /></Link></template>
    </DataTable>
      </PageHeader>
  </AdminLayout>
</template>