<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { History } from 'lucide-vue-next';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import DataTable, { type DataTableColumn } from '../../../../../CORE/Resources/js/Components/DataTable.vue';
import StatusBadge from '../../../../../CORE/Resources/js/Components/StatusBadge.vue';
import type { Paginator } from '../../../../../CORE/Resources/js/types';
import { formatCurrency} from '../../../../../CORE/Resources/js/Utils/format';
interface Row { id:number; pos_no:string; customer_name:string; sale_date:string; total_amount:string; payment_method:string; status:string; created_at:string; due_amount:string; collected_amount:string }
defineProps<{ sales: Paginator<Row> }>();
const columns: DataTableColumn[] = [
  {key:'pos_no',label:'POS No',sortable:true},
  {key:'customer_name',label:'Customer'},
  {key:'sale_date',label:'Date',sortable:true},
  {key:'total_amount',label:'Total',align:'right'},
  {key:'payment_method',label:'Pay',align:'center'},
  // A credit sale shows as `due`; the tender column would otherwise read like an unpaid cash sale.
  {key:'status',label:'Status',align:'center'},
  {key:'due_amount',label:'Owed',align:'right'},
  {key:'actions',label:'',align:'right',exportable:false,width:'9rem'},
];
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Sales & POS' }, { title: 'POS History' }]"><Head title="POS History"/><h1 class="text-xl font-bold flex items-center gap-2"><History class="size-5 text-primary"/> POS History</h1><DataTable route-name="sales.pos.history" title="POS History" :columns="columns" :rows="(sales.data as unknown as Record<string,unknown>[])" :meta="sales.meta" search-placeholder="Search POS no…"><template #cell-pos_no="{row}"><span class="font-mono text-xs font-bold bg-primary/10 text-primary px-1.5 py-0.5 rounded">{{ row.pos_no }}</span></template><template #cell-total_amount="{row}"><span class="font-mono font-bold">{{ formatCurrency(Number(row.total_amount)) }}</span></template><template #cell-payment_method="{row}"><span class="text-xs px-1.5 py-0.5 bg-muted rounded">{{ row.payment_method }}</span></template><template #cell-status="{row}"><StatusBadge :label="row.status" :tone="row.status==='completed'?'success':'danger'" /></template><template #cell-due_amount="{row}"><span v-if="Number(row.due_amount)-Number(row.collected_amount)>0.005" class="font-mono text-xs font-bold text-amber-600">{{ formatCurrency(Number(row.due_amount)-Number(row.collected_amount)) }}</span><span v-else-if="Number(row.due_amount)>0" class="text-xs text-emerald-600">settled</span><span v-else class="text-xs text-muted-foreground">—</span></template><template #cell-actions="{row}"><Link :href="route('sales.pos.show', row.id)" class="text-sm text-primary hover:underline">Open</Link><span class="mx-1 text-muted-foreground">·</span><Link :href="route('sales.pos.receipt', row.id)" class="text-sm text-primary hover:underline">Receipt</Link></template></DataTable></AdminLayout>
</template>
