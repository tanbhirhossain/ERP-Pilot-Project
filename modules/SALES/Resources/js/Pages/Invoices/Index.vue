<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2, Receipt, Eye } from 'lucide-vue-next';
import { ref } from 'vue';

import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import PageHeader from '../../../../../CORE/Resources/js/Components/PageHeader.vue';
import ConfirmDialog from '../../../../../CORE/Resources/js/Components/ConfirmDialog.vue';
import DataTable, { type DataTableColumn } from '../../../../../CORE/Resources/js/Components/DataTable.vue';
import StatusBadge from '../../../../../CORE/Resources/js/Components/StatusBadge.vue';
import type { Paginator } from '../../../../../CORE/Resources/js/types';
import { formatCurrency, formatDate } from '../../../../../CORE/Resources/js/Utils/format';
interface Row { id:number; invoice_no:string; customer?:{name:string}; invoice_date:string; due_date?:string; total_amount:string; paid_amount:string; status:string; payment_status:string; items_count:number; created_at:string }
defineProps<{ invoices: Paginator<Row> }>();
const columns: DataTableColumn[] = [
  {key:'invoice_no',label:'Invoice No',sortable:true},
  {key:'customer',label:'Customer'},
  {key:'invoice_date',label:'Date',sortable:true},
  {key:'total_amount',label:'Total',align:'right',sortable:true},
  {key:'paid_amount',label:'Paid',align:'right'},
  {key:'status',label:'Status',align:'center',sortable:true},
  {key:'actions',label:'',align:'right',exportable:false,width:'7rem'},
];
const confirmOpen=ref(false), pending=ref<Row|null>(null), processing=ref(false);
function askDelete(r:Record<string,unknown>){ pending.value=r as unknown as Row; confirmOpen.value=true; }
function confirmDelete(){ if(!pending.value) return; processing.value=true; router.delete(route('sales.invoices.destroy',pending.value.id),{preserveScroll:true,onFinish(){processing.value=false;confirmOpen.value=false;pending.value=null;}}); }
function tone(s:string){ if(s==='paid') return 'success'; if(s==='posted') return 'info'; if(s==='draft') return 'warning'; return 'neutral'; }
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Sales & POS' }, { title: 'Invoices' }]">
    <Head title="Invoices" />
    <PageHeader title="Invoices" description="Draft → Posted (stock deducted + journal) → Paid. Links to Orders." :icon="Receipt" :actions="[{ label:'New Invoice', route:'sales.invoices.create', icon: Plus }]">
    <DataTable route-name="sales.invoices.index" title="Invoices" :columns="columns" :rows="(invoices.data as unknown as Record<string,unknown>[])" :meta="invoices.meta" search-placeholder="Search invoice no…">
      <template #cell-invoice_no="{row}"><span class="font-mono text-xs font-bold px-1.5 py-0.5 rounded bg-emerald-500/10 text-emerald-600">{{ row.invoice_no }}</span></template>
      <template #cell-customer="{row}"><span class="text-sm">{{ row.customer?.name || '—' }}</span></template>
      <template #cell-invoice_date="{row}"><span class="text-sm">{{ formatDate(row.invoice_date) }}</span><span v-if="row.due_date" class="text-xs text-muted-foreground"> due {{ formatDate(row.due_date) }}</span></template>
      <template #cell-total_amount="{row}"><span class="font-mono font-bold">{{ formatCurrency(Number(row.total_amount)) }}</span></template>
      <template #cell-paid_amount="{row}"><span class="font-mono">{{ formatCurrency(Number(row.paid_amount)) }} / {{ formatCurrency(Number(row.total_amount)) }}</span></template>
      <template #cell-status="{row}"><StatusBadge :label="row.status" :tone="tone(row.status)" /></template>
      <template #cell-actions="{row}"><div class="flex justify-end gap-1"><Link :href="route('sales.invoices.show',row.id)" class="size-8 grid place-items-center rounded-md hover:bg-accent"><Eye class="size-4"/></Link><Link :href="route('sales.invoices.edit',row.id)" class="size-8 grid place-items-center rounded-md hover:bg-accent"><Pencil class="size-4"/></Link><button @click="askDelete(row)" class="size-8 grid place-items-center rounded-md hover:bg-destructive/10 hover:text-destructive"><Trash2 class="size-4"/></button></div></template>
    </DataTable>
    <ConfirmDialog v-model:open="confirmOpen" :processing="processing" title="Delete invoice?" :description="`Delete ${pending?.invoice_no}?`" confirm-label="Delete" @confirm="confirmDelete" />
      </PageHeader>
  </AdminLayout>
</template>
