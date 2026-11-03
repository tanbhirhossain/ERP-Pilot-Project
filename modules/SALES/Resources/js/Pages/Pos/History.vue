<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { History } from 'lucide-vue-next';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import DataTable, { type DataTableColumn } from '../../../../../CORE/Resources/js/Components/DataTable.vue';
import StatusBadge from '../../../../../CORE/Resources/js/Components/StatusBadge.vue';
import type { Paginator } from '../../../../../CORE/Resources/js/types';
import { formatCurrency, formatDate } from '../../../../../CORE/Resources/js/Utils/format';
interface Row { id:number; pos_no:string; customer_name:string; sale_date:string; total_amount:string; payment_method:string; status:string; created_at:string }
defineProps<{ sales: Paginator<Row> }>();
const columns: DataTableColumn[] = [
  {key:'pos_no',label:'POS No',sortable:true},
  {key:'customer_name',label:'Customer'},
  {key:'sale_date',label:'Date',sortable:true},
  {key:'total_amount',label:'Total',align:'right'},
  {key:'payment_method',label:'Pay',align:'center'},
  {key:'status',label:'Status',align:'center'},
  {key:'actions',label:'',align:'right',exportable:false,width:'6rem'},
];
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'Sales'},{title:'POS History'}]"><Head title="POS History"/><h1 class="text-xl font-bold flex items-center gap-2"><History class="size-5 text-primary"/> POS History</h1><DataTable route-name="sales.pos.history" title="POS History" :columns="columns" :rows="(sales.data as unknown as Record<string,unknown>[])" :meta="sales.meta" search-placeholder="Search POS no…"><template #cell-pos_no="{row}"><span class="font-mono text-xs font-bold bg-primary/10 text-primary px-1.5 py-0.5 rounded">{{ row.pos_no }}</span></template><template #cell-total_amount="{row}"><span class="font-mono font-bold">{{ formatCurrency(Number(row.total_amount)) }}</span></template><template #cell-payment_method="{row}"><span class="text-xs px-1.5 py-0.5 bg-muted rounded">{{ row.payment_method }}</span></template><template #cell-status="{row}"><StatusBadge :label="row.status" :tone="row.status==='completed'?'success':'danger'" /></template><template #cell-actions="{row}"><Link :href="route('sales.pos.receipt', row.id)" class="text-sm text-primary hover:underline">Receipt</Link></template></DataTable></AdminLayout>
</template>
