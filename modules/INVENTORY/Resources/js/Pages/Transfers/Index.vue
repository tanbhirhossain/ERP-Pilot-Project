<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2, ArrowLeftRight, Eye} from 'lucide-vue-next';
import { ref } from 'vue';

import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import PageHeader from '../../../../../CORE/Resources/js/Components/PageHeader.vue';
import ConfirmDialog from '../../../../../CORE/Resources/js/Components/ConfirmDialog.vue';
import DataTable, { type DataTableColumn } from '../../../../../CORE/Resources/js/Components/DataTable.vue';
import StatusBadge from '../../../../../CORE/Resources/js/Components/StatusBadge.vue';
import type { Paginator } from '../../../../../CORE/Resources/js/types';
import { formatDate } from '../../../../../CORE/Resources/js/Utils/format';
interface Row { id:number; transfer_no:string; from_warehouse?:{name:string}; to_warehouse?:{name:string}; status:string; items_count:number; requester?:string; created_at:string }
defineProps<{ transfers: Paginator<Row>; warehouseOptions:any[] }>();
const columns: DataTableColumn[] = [
  {key:'transfer_no',label:'Transfer No',sortable:true},
  {key:'from_warehouse',label:'From'},
  {key:'to_warehouse',label:'To'},
  {key:'status',label:'Status',align:'center',sortable:true},
  {key:'items_count',label:'Items',align:'center'},
  {key:'created_at',label:'Date',sortable:true},
  {key:'actions',label:'',align:'right',exportable:false,width:'8rem'},
];
const confirmOpen=ref(false), pending=ref<Row|null>(null), processing=ref(false);
function askDelete(r:Record<string,unknown>){ pending.value=r as unknown as Row; confirmOpen.value=true; }
function confirmDelete(){ if(!pending.value) return; processing.value=true; router.delete(route('inventory.transfers.destroy',pending.value.id),{preserveScroll:true,onFinish(){processing.value=false;confirmOpen.value=false;pending.value=null;}}); }
function tone(s:string){ if(s==='received') return 'success'; if(s==='in_transit') return 'info'; if(s==='draft') return 'warning'; if(s==='cancelled') return 'danger'; return 'neutral'; }
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'Inventory'},{title:'Transfers'}]">
    <Head title="Stock Transfers" />
    <PageHeader title="Stock Transfers" description="Move stock between warehouses — ship → receive with ledger postings." :icon="ArrowLeftRight" :actions="[{ label:'New Transfer', route:'inventory.transfers.create', icon: Plus }]">
    <DataTable route-name="inventory.transfers.index" title="Transfers" :columns="columns" :rows="(transfers.data as unknown as Record<string,unknown>[])" :meta="transfers.meta" search-placeholder="Search transfer no…">
      <template #cell-transfer_no="{row}"><span class="font-mono text-sm font-semibold bg-indigo-500/10 text-indigo-600 px-1.5 py-0.5 rounded">{{ row.transfer_no }}</span></template>
      <template #cell-from_warehouse="{row}"><span class="text-sm">{{ row.from_warehouse?.name || '—' }}</span></template>
      <template #cell-to_warehouse="{row}"><span class="text-sm">{{ row.to_warehouse?.name || '—' }}</span></template>
      <template #cell-status="{row}"><StatusBadge :label="row.status" :tone="tone(row.status)" /></template>
      <template #cell-created_at="{row}"><span class="text-xs text-muted-foreground">{{ formatDate(row.created_at) }}</span></template>
      <template #cell-actions="{row}"><div class="flex justify-end gap-1"><Link :href="route('inventory.transfers.show',row.id)" class="size-8 grid place-items-center rounded-md hover:bg-accent"><Eye class="size-4"/></Link><Link :href="route('inventory.transfers.edit',row.id)" class="size-8 grid place-items-center rounded-md hover:bg-accent"><Pencil class="size-4"/></Link><button @click="askDelete(row)" class="size-8 grid place-items-center rounded-md hover:bg-destructive/10 hover:text-destructive"><Trash2 class="size-4"/></button></div></template>
    </DataTable>
    <ConfirmDialog v-model:open="confirmOpen" :processing="processing" title="Delete transfer?" :description="`Delete ${pending?.transfer_no}?`" confirm-label="Delete" @confirm="confirmDelete" />
      </PageHeader>
  </AdminLayout>
</template>
