<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Truck, Plus, Eye, Pencil, Trash2 } from 'lucide-vue-next';
import { ref } from 'vue';

import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import ConfirmDialog from '@core/Components/ConfirmDialog.vue';
import DataTable, { type DataTableColumn } from '@core/Components/DataTable.vue';
import StatusBadge from '@core/Components/StatusBadge.vue';
import type { Paginator } from '@core/types';
import { formatDate } from '@core/Utils/format';
interface Row { id:number; delivery_no:string; customer?:{name:string}; warehouse?:{name:string}; delivery_date:string; status:string; items_count:number }
defineProps<{ deliveries: Paginator<Row> }>();
const columns: DataTableColumn[] = [
  {key:'delivery_no',label:'Delivery No',sortable:true},
  {key:'customer',label:'Customer'},
  {key:'warehouse',label:'Warehouse'},
  {key:'delivery_date',label:'Date',sortable:true},
  {key:'status',label:'Status',align:'center',sortable:true},
  {key:'actions',label:'',align:'right',exportable:false,width:'7rem'},
];
const confirmOpen=ref(false), pending=ref<Row|null>(null), processing=ref(false);
function askDelete(r:Record<string,unknown>){ pending.value=r as unknown as Row; confirmOpen.value=true; }
function confirmDelete(){ if(!pending.value) return; processing.value=true; router.delete(route('sales.deliveries.destroy',pending.value.id),{preserveScroll:true,onFinish(){processing.value=false;confirmOpen.value=false;pending.value=null}}); }
function tone(s:string){ if(s==='shipped') return 'success'; if(s==='delivered') return 'info'; if(s==='draft') return 'warning'; return 'neutral'; }
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Sales & POS' }, { title: 'Deliveries' }]">
    <Head title="Delivery Challans" />
    <PageHeader title="Delivery Challans" description="Order → Delivery (stock deducted on Ship) → Invoice. Challan print ready." :icon="Truck" :actions="[{ label:'New Delivery', route:'sales.deliveries.create', icon: Plus }]">
    <DataTable route-name="sales.deliveries.index" title="Deliveries" :columns="columns" :rows="deliveries.data" :meta="deliveries.meta" search-placeholder="Search delivery no…">
      <template #cell-delivery_no="{row}"><span class="font-mono text-xs font-bold px-1.5 py-0.5 rounded bg-amber-500/10 text-amber-600">{{ row.delivery_no }}</span></template>
      <template #cell-customer="{row}"><span class="text-sm">{{ row.customer?.name || '—' }}</span></template>
      <template #cell-warehouse="{row}"><span class="text-sm">{{ row.warehouse?.name || '—' }}</span></template>
      <template #cell-delivery_date="{row}"><span class="text-sm">{{ formatDate(row.delivery_date) }}</span></template>
      <template #cell-status="{row}"><StatusBadge :label="row.status" :tone="tone(row.status)" /></template>
      <template #cell-actions="{row}"><div class="flex justify-end gap-1"><Link :href="route('sales.deliveries.show',row.id)" class="size-8 grid place-items-center rounded-md hover:bg-accent"><Eye class="size-4"/></Link><Link :href="route('sales.deliveries.edit',row.id)" class="size-8 grid place-items-center rounded-md hover:bg-accent"><Pencil class="size-4"/></Link><button @click="askDelete(row)" class="size-8 grid place-items-center rounded-md hover:bg-destructive/10 hover:text-destructive"><Trash2 class="size-4"/></button></div></template>
    </DataTable>
    <ConfirmDialog v-model:open="confirmOpen" :processing="processing" title="Delete delivery?" :description="`Delete ${pending?.delivery_no}?`" confirm-label="Delete" @confirm="confirmDelete" />
      </PageHeader>
  </AdminLayout>
</template>
