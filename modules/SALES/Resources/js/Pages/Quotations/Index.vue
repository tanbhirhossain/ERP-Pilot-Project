<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2, FileText, Eye } from 'lucide-vue-next';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import PageHeader from '../../../../../CORE/Resources/js/Components/PageHeader.vue';
import ConfirmDialog from '../../../../../CORE/Resources/js/Components/ConfirmDialog.vue';
import DataTable, { type DataTableColumn } from '../../../../../CORE/Resources/js/Components/DataTable.vue';
import StatusBadge from '../../../../../CORE/Resources/js/Components/StatusBadge.vue';
import type { Paginator } from '../../../../../CORE/Resources/js/types';
import { formatCurrency, formatDate } from '../../../../../CORE/Resources/js/Utils/format';
interface Row { id:number; quotation_no:string; customer?:{name:string}; quotation_date:string; expiry_date?:string; total_amount:string; status:string; items_count:number; created_at:string }
defineProps<{ quotations: Paginator<Row> }>();
const columns: DataTableColumn[] = [
  {key:'quotation_no',label:'Quotation No',sortable:true},
  {key:'customer',label:'Customer'},
  {key:'quotation_date',label:'Date',sortable:true},
  {key:'total_amount',label:'Total',align:'right',sortable:true},
  {key:'status',label:'Status',align:'center',sortable:true},
  {key:'actions',label:'',align:'right',exportable:false,width:'7rem'},
];
const confirmOpen=ref(false), pending=ref<Row|null>(null), processing=ref(false);
function askDelete(r:Record<string,unknown>){ pending.value=r as unknown as Row; confirmOpen.value=true; }
function confirmDelete(){ if(!pending.value) return; processing.value=true; router.delete(route('sales.quotations.destroy',pending.value.id),{preserveScroll:true,onFinish(){processing.value=false;confirmOpen.value=false;pending.value=null;}}); }
function tone(s:string){ if(s==='accepted') return 'success'; if(s==='sent') return 'info'; if(s==='draft') return 'warning'; if(s==='converted') return 'primary'; return 'neutral'; }
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'Sales'},{title:'Quotations'}]">
    <Head title="Quotations" />
    <PageHeader title="Quotations" description="Price offers — draft → sent → accepted → converted to Order." :icon="FileText" :actions="[{ label:'New Quotation', route:'sales.quotations.create', icon: Plus }]">
    <DataTable route-name="sales.quotations.index" title="Quotations" :columns="columns" :rows="(quotations.data as unknown as Record<string,unknown>[])" :meta="quotations.meta" search-placeholder="Search quotation no…">
      <template #cell-quotation_no="{row}"><span class="font-mono text-xs font-bold px-1.5 py-0.5 rounded bg-violet-500/10 text-violet-600">{{ row.quotation_no }}</span></template>
      <template #cell-customer="{row}"><span class="text-sm">{{ row.customer?.name || '—' }}</span></template>
      <template #cell-quotation_date="{row}"><span class="text-sm">{{ formatDate(row.quotation_date) }}</span><span v-if="row.expiry_date" class="text-xs text-muted-foreground"> exp {{ formatDate(row.expiry_date) }}</span></template>
      <template #cell-total_amount="{row}"><span class="font-mono font-bold">{{ formatCurrency(Number(row.total_amount)) }}</span> <span class="text-xs text-muted-foreground">{{ row.items_count }} items</span></template>
      <template #cell-status="{row}"><StatusBadge :label="row.status" :tone="tone(row.status)" /></template>
      <template #cell-actions="{row}"><div class="flex justify-end gap-1"><Link :href="route('sales.quotations.show',row.id)" class="size-8 grid place-items-center rounded-md hover:bg-accent"><Eye class="size-4"/></Link><Link :href="route('sales.quotations.edit',row.id)" class="size-8 grid place-items-center rounded-md hover:bg-accent"><Pencil class="size-4"/></Link><button @click="askDelete(row)" class="size-8 grid place-items-center rounded-md hover:bg-destructive/10 hover:text-destructive"><Trash2 class="size-4"/></button></div></template>
    </DataTable>
    <ConfirmDialog v-model:open="confirmOpen" :processing="processing" title="Delete quotation?" :description="`Delete ${pending?.quotation_no}?`" confirm-label="Delete" @confirm="confirmDelete" />
      </PageHeader>
  </AdminLayout>
</template>
