<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Undo2, Plus, Eye, Trash2 } from 'lucide-vue-next';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import PageHeader from '../../../../../CORE/Resources/js/Components/PageHeader.vue';
import ConfirmDialog from '../../../../../CORE/Resources/js/Components/ConfirmDialog.vue';
import DataTable, { type DataTableColumn } from '../../../../../CORE/Resources/js/Components/DataTable.vue';
import StatusBadge from '../../../../../CORE/Resources/js/Components/StatusBadge.vue';
import type { Paginator } from '../../../../../CORE/Resources/js/types';
import { formatCurrency, formatDate } from '../../../../../CORE/Resources/js/Utils/format';
interface Row { id:number; return_no:string; customer?:{name:string}; invoice?:{invoice_no:string}; return_date:string; total_amount:string; status:string; items_count:number }
defineProps<{ returns: Paginator<Row> }>();
const columns: DataTableColumn[] = [
  {key:'return_no',label:'Return No',sortable:true},
  {key:'customer',label:'Customer'},
  {key:'invoice',label:'Invoice'},
  {key:'return_date',label:'Date',sortable:true},
  {key:'total_amount',label:'Total',align:'right',sortable:true},
  {key:'status',label:'Status',align:'center',sortable:true},
  {key:'actions',label:'',align:'right',exportable:false,width:'7rem'},
];
const confirmOpen=ref(false), pending=ref<Row|null>(null), processing=ref(false);
function askDelete(r:Record<string,unknown>){ pending.value=r as unknown as Row; confirmOpen.value=true; }
function confirmDelete(){ if(!pending.value) return; processing.value=true; router.delete(route('sales.returns.destroy',pending.value.id),{preserveScroll:true,onFinish(){processing.value=false;confirmOpen.value=false;pending.value=null}}); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'Sales'},{title:'Returns'}]">
    <Head title="Sales Returns" />
    <PageHeader title="Sales Returns (Credit Notes)" description="Invoice → Return (resalable → restock, damaged → scrap) → Credit journal." :icon="Undo2" :actions="[{ label:'New Return', route:'sales.returns.create', icon: Plus }]">
    <DataTable route-name="sales.returns.index" title="Returns" :columns="columns" :rows="(returns.data as unknown as Record<string,unknown>[])" :meta="returns.meta" search-placeholder="Search return no…">
      <template #cell-return_no="{row}"><span class="font-mono text-xs font-bold px-1.5 py-0.5 rounded bg-rose-500/10 text-rose-600">{{ row.return_no }}</span></template>
      <template #cell-customer="{row}"><span class="text-sm">{{ row.customer?.name || '—' }}</span></template>
      <template #cell-invoice="{row}"><span class="font-mono text-xs">{{ row.invoice?.invoice_no || '—' }}</span></template>
      <template #cell-return_date="{row}">{{ formatDate(row.return_date) }}</template>
      <template #cell-total_amount="{row}"><span class="font-mono font-bold">{{ formatCurrency(Number(row.total_amount)) }}</span></template>
      <template #cell-status="{row}"><StatusBadge :label="row.status" :tone="row.status==='approved'?'success':row.status==='draft'?'warning':'neutral'" /></template>
      <template #cell-actions="{row}"><div class="flex justify-end gap-1"><Link :href="route('sales.returns.show',row.id)" class="size-8 grid place-items-center rounded-md hover:bg-accent"><Eye class="size-4"/></Link><button @click="askDelete(row)" class="size-8 grid place-items-center rounded-md hover:bg-destructive/10 hover:text-destructive"><Trash2 class="size-4"/></button></div></template>
    </DataTable>
    <ConfirmDialog v-model:open="confirmOpen" :processing="processing" title="Delete return?" :description="`Delete ${pending?.return_no}?`" confirm-label="Delete" @confirm="confirmDelete" />
      </PageHeader>
  </AdminLayout>
</template>
