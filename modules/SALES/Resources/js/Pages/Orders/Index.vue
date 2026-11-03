<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2, ClipboardCheck, Eye } from 'lucide-vue-next';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import PageHeader from '../../../../../CORE/Resources/js/Components/PageHeader.vue';
import ConfirmDialog from '../../../../../CORE/Resources/js/Components/ConfirmDialog.vue';
import DataTable, { type DataTableColumn } from '../../../../../CORE/Resources/js/Components/DataTable.vue';
import StatusBadge from '../../../../../CORE/Resources/js/Components/StatusBadge.vue';
import type { Paginator } from '../../../../../CORE/Resources/js/types';
import { formatCurrency, formatDate } from '../../../../../CORE/Resources/js/Utils/format';
interface Row { id:number; order_no:string; customer?:{name:string}; warehouse?:{name:string}; order_date:string; total_amount:string; status:string; payment_status:string; items_count:number; created_at:string }
defineProps<{ orders: Paginator<Row> }>();
const columns: DataTableColumn[] = [
  {key:'order_no',label:'Order No',sortable:true},
  {key:'customer',label:'Customer'},
  {key:'warehouse',label:'Warehouse'},
  {key:'order_date',label:'Date',sortable:true},
  {key:'total_amount',label:'Total',align:'right',sortable:true},
  {key:'status',label:'Status',align:'center',sortable:true},
  {key:'actions',label:'',align:'right',exportable:false,width:'7rem'},
];
const confirmOpen=ref(false), pending=ref<Row|null>(null), processing=ref(false);
function askDelete(r:Record<string,unknown>){ pending.value=r as unknown as Row; confirmOpen.value=true; }
function confirmDelete(){ if(!pending.value) return; processing.value=true; router.delete(route('sales.orders.destroy',pending.value.id),{preserveScroll:true,onFinish(){processing.value=false;confirmOpen.value=false;pending.value=null;}}); }
function tone(s:string){ if(s==='confirmed') return 'info'; if(s==='invoiced') return 'success'; if(s==='draft') return 'warning'; return 'neutral'; }
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'Sales'},{title:'Orders'}]">
    <Head title="Sales Orders" />
    <PageHeader title="Sales Orders" description="Draft → Confirmed (stock reserved) → Invoiced. Linked to quotations." :icon="ClipboardCheck" :actions="[{ label:'New Order', route:'sales.orders.create', icon: Plus }]">
    <DataTable route-name="sales.orders.index" title="Orders" :columns="columns" :rows="(orders.data as unknown as Record<string,unknown>[])" :meta="orders.meta" search-placeholder="Search order no…">
      <template #cell-order_no="{row}"><span class="font-mono text-xs font-bold px-1.5 py-0.5 rounded bg-sky-500/10 text-sky-600">{{ row.order_no }}</span></template>
      <template #cell-customer="{row}"><span class="text-sm">{{ row.customer?.name || '—' }}</span></template>
      <template #cell-warehouse="{row}"><span class="text-sm">{{ row.warehouse?.name || '—' }}</span></template>
      <template #cell-order_date="{row}"><span class="text-sm">{{ formatDate(row.order_date) }}</span></template>
      <template #cell-total_amount="{row}"><span class="font-mono font-bold">{{ formatCurrency(Number(row.total_amount)) }}</span> <span class="text-xs text-muted-foreground">{{ row.items_count }} items</span></template>
      <template #cell-status="{row}"><StatusBadge :label="row.status" :tone="tone(row.status)" /></template>
      <template #cell-actions="{row}"><div class="flex justify-end gap-1"><Link :href="route('sales.orders.show',row.id)" class="size-8 grid place-items-center rounded-md hover:bg-accent"><Eye class="size-4"/></Link><Link :href="route('sales.orders.edit',row.id)" class="size-8 grid place-items-center rounded-md hover:bg-accent"><Pencil class="size-4"/></Link><button @click="askDelete(row)" class="size-8 grid place-items-center rounded-md hover:bg-destructive/10 hover:text-destructive"><Trash2 class="size-4"/></button></div></template>
    </DataTable>
    <ConfirmDialog v-model:open="confirmOpen" :processing="processing" title="Delete order?" :description="`Delete ${pending?.order_no}?`" confirm-label="Delete" @confirm="confirmDelete" />
      </PageHeader>
  </AdminLayout>
</template>
