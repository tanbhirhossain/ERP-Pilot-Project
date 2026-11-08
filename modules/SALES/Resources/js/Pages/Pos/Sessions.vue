<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Clock } from 'lucide-vue-next';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import DataTable, { type DataTableColumn } from '../../../../../CORE/Resources/js/Components/DataTable.vue';
import StatusBadge from '../../../../../CORE/Resources/js/Components/StatusBadge.vue';
import type { Paginator } from '../../../../../CORE/Resources/js/types';
import { formatDate } from '../../../../../CORE/Resources/js/Utils/format';
interface Row { id:number; session_no:string; warehouse?:{name:string}; opening_cash:string; total_sales:string; transactions_count:number; status:string; opened_at:string; closed_at?:string }
defineProps<{ sessions: Paginator<Row> }>();
const columns: DataTableColumn[] = [
  {key:'session_no',label:'Session No',sortable:true},
  {key:'warehouse',label:'Warehouse'},
  {key:'opening_cash',label:'Opening',align:'right'},
  {key:'total_sales',label:'Sales',align:'right'},
  {key:'transactions_count',label:'Txns',align:'center'},
  {key:'status',label:'Status',align:'center'},
  {key:'opened_at',label:'Opened',sortable:true},
  {key:'actions',label:'',align:'right',exportable:false,width:'7rem'},
];
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Sales & POS' }, { title: 'POS Sessions' }]"><Head title="POS Sessions"/><h1 class="text-xl font-bold flex items-center gap-2"><Clock class="size-5 text-primary"/> POS Sessions</h1><DataTable route-name="sales.pos.sessions" title="POS Sessions" :columns="columns" :rows="(sessions.data as unknown as Record<string,unknown>[])" :meta="sessions.meta"><template #cell-session_no="{row}"><span class="font-mono text-xs font-bold bg-amber-500/10 text-amber-600 px-1.5 py-0.5 rounded">{{ row.session_no }}</span></template><template #cell-warehouse="{row}"><span class="text-sm">{{ row.warehouse?.name || '—' }}</span></template><template #cell-status="{row}"><StatusBadge :label="row.status" :tone="row.status==='open'?'success':'neutral'" /></template><template #cell-opened_at="{row}"><span class="text-xs">{{ formatDate(row.opened_at) }}</span></template><template #cell-actions="{row}"><Link :href="route('sales.pos.sessions.dayEnd', row.id)" class="text-sm text-primary hover:underline">Day-end</Link></template></DataTable></AdminLayout>
</template>
