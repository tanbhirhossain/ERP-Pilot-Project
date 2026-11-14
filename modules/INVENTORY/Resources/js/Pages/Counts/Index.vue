<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ClipboardCheck, Plus } from 'lucide-vue-next';

import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import DataTable, { type DataTableColumn } from '@core/Components/DataTable.vue';
import StatusBadge from '@core/Components/StatusBadge.vue';
import type { Paginator } from '@core/types';
import { formatDate } from '@core/Utils/format';
interface Row { id:number; count_no:string; warehouse?:{name:string}; status:string; counted_at?:string; created_at:string; items_count:number }
defineProps<{ counts: Paginator<Row> }>();
const columns: DataTableColumn[] = [
  {key:'count_no',label:'Count No',sortable:true},
  {key:'warehouse',label:'Warehouse'},
  {key:'status',label:'Status',align:'center',sortable:true},
  {key:'items_count',label:'Items',align:'center'},
  {key:'created_at',label:'Date',sortable:true},
  {key:'actions',label:'',align:'right',exportable:false,width:'6rem'},
];
function tone(s:string){ if(s==='posted') return 'success'; if(s==='counted') return 'info'; if(s==='draft') return 'warning'; return 'neutral'; }
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'Inventory'},{title:'Physical Counts'}]">
    <Head title="Physical Counts" />
    <PageHeader title="Physical Counts" description="Cycle counts — freeze, count, post variances." :icon="ClipboardCheck" :actions="[{ label:'New Count', route:'inventory.counts.create', icon: Plus }]">
    <DataTable route-name="inventory.counts.index" title="Physical Counts" :columns="columns" :rows="counts.data" :meta="counts.meta" search-placeholder="Search counts…">
      <template #cell-count_no="{row}"><span class="font-mono text-sm font-semibold bg-teal-500/10 text-teal-600 px-1.5 py-0.5 rounded">{{ row.count_no }}</span></template>
      <template #cell-warehouse="{row}"><span class="text-sm">{{ row.warehouse?.name || '—' }}</span></template>
      <template #cell-status="{row}"><StatusBadge :label="row.status" :tone="tone(row.status)" /></template>
      <template #cell-created_at="{row}"><span class="text-xs text-muted-foreground">{{ formatDate(row.created_at) }}</span></template>
      <template #cell-actions="{row}"><Link :href="route('inventory.counts.show',row.id)" class="text-sm text-primary hover:underline">View</Link></template>
    </DataTable>
      </PageHeader>
  </AdminLayout>
</template>
