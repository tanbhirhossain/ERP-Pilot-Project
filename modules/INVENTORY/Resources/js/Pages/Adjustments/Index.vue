<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2, ClipboardList, Eye } from 'lucide-vue-next';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import PageHeader from '../../../../../CORE/Resources/js/Components/PageHeader.vue';
import ConfirmDialog from '../../../../../CORE/Resources/js/Components/ConfirmDialog.vue';
import DataTable, { type DataTableColumn } from '../../../../../CORE/Resources/js/Components/DataTable.vue';
import StatusBadge from '../../../../../CORE/Resources/js/Components/StatusBadge.vue';
import type { Paginator } from '../../../../../CORE/Resources/js/types';
import { formatDate } from '../../../../../CORE/Resources/js/Utils/format';
interface Row { id:number; adjustment_no:string; warehouse?:{name:string}; type:string; status:string; reason:string; items_count:number; creator?:string; created_at:string }
defineProps<{ adjustments: Paginator<Row>; warehouseOptions:any[] }>();
const columns: DataTableColumn[] = [
  {key:'adjustment_no',label:'Adj No',sortable:true},
  {key:'warehouse',label:'Warehouse'},
  {key:'type',label:'Type',align:'center'},
  {key:'status',label:'Status',align:'center',sortable:true},
  {key:'items_count',label:'Items',align:'center'},
  {key:'created_at',label:'Date',sortable:true},
  {key:'actions',label:'',align:'right',exportable:false,width:'6rem'},
];
const confirmOpen=ref(false), pending=ref<Row|null>(null), processing=ref(false);
function askDelete(r:Record<string,unknown>){ pending.value=r as unknown as Row; confirmOpen.value=true; }
function confirmDelete(){ if(!pending.value) return; processing.value=true; router.delete(route('inventory.adjustments.destroy',pending.value.id),{preserveScroll:true,onFinish(){processing.value=false;confirmOpen.value=false;pending.value=null;}}); }
function tone(s:string){ if(s==='posted') return 'success'; if(s==='approved') return 'info'; if(s==='draft') return 'warning'; return 'neutral'; }
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'Inventory'},{title:'Adjustments'}]">
    <Head title="Adjustments" />
    <PageHeader title="Stock Adjustments" description="Correct stock — damage, expired, found, correction. Draft → Approve → Post." :icon="ClipboardList" :actions="[{ label:'New Adjustment', route:'inventory.adjustments.create', icon: Plus }]">
    <DataTable route-name="inventory.adjustments.index" title="Adjustments" :columns="columns" :rows="(adjustments.data as unknown as Record<string,unknown>[])" :meta="adjustments.meta" search-placeholder="Search adjustments…">
      <template #cell-adjustment_no="{row}"><span class="font-mono text-sm font-semibold bg-amber-500/10 text-amber-600 px-1.5 py-0.5 rounded">{{ row.adjustment_no }}</span></template>
      <template #cell-warehouse="{row}"><span class="text-sm">{{ row.warehouse?.name || '—' }}</span></template>
      <template #cell-type="{row}"><StatusBadge :label="row.type" tone="neutral" /></template>
      <template #cell-status="{row}"><StatusBadge :label="row.status" :tone="tone(row.status)" /></template>
      <template #cell-created_at="{row}"><span class="text-xs text-muted-foreground">{{ formatDate(row.created_at) }}</span></template>
      <template #cell-actions="{row}"><div class="flex justify-end gap-1"><Link :href="route('inventory.adjustments.show',row.id)" class="size-8 grid place-items-center rounded-md hover:bg-accent"><Eye class="size-4"/></Link><Link :href="route('inventory.adjustments.edit',row.id)" class="size-8 grid place-items-center rounded-md hover:bg-accent"><Pencil class="size-4"/></Link><button @click="askDelete(row)" class="size-8 grid place-items-center rounded-md hover:bg-destructive/10 hover:text-destructive"><Trash2 class="size-4"/></button></div></template>
    </DataTable>
    <ConfirmDialog v-model:open="confirmOpen" :processing="processing" title="Delete adjustment?" :description="`Delete ${pending?.adjustment_no}?`" confirm-label="Delete" @confirm="confirmDelete" />
      </PageHeader>
  </AdminLayout>
</template>
