<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2, Ruler } from 'lucide-vue-next';
import { ref } from 'vue';

import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import ConfirmDialog from '@core/Components/ConfirmDialog.vue';
import DataTable, { type DataTableColumn } from '@core/Components/DataTable.vue';
import StatusBadge from '@core/Components/StatusBadge.vue';
import type { Paginator } from '@core/types';
interface Row { id:number; name:string; symbol:string; category:string; ratio_to_base:string; is_active:boolean; products_count:number }
defineProps<{ uoms: Paginator<Row> }>();
const columns: DataTableColumn[] = [
  {key:'name',label:'UOM',sortable:true},
  {key:'symbol',label:'Symbol',sortable:true,align:'center'},
  {key:'category',label:'Category'},
  {key:'ratio_to_base',label:'Ratio'},
  {key:'products_count',label:'Products',align:'center'},
  {key:'is_active',label:'Active',align:'center'},
  {key:'actions',label:'',align:'right',exportable:false,width:'6rem'},
];
const confirmOpen=ref(false), pending=ref<Row|null>(null), processing=ref(false);
function askDelete(r:Record<string,unknown>){ pending.value=r as unknown as Row; confirmOpen.value=true; }
function confirmDelete(){ if(!pending.value) return; processing.value=true; router.delete(route('inventory.uoms.destroy',pending.value.id),{preserveScroll:true,onFinish(){processing.value=false;confirmOpen.value=false;pending.value=null;}}); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'Inventory'},{title:'Units of Measure'}]">
    <Head title="UOMs" />
    <PageHeader title="Units of Measure" description="PCS, KG, MTR — with conversion ratios." :icon="Ruler" :actions="[{ label:'Add UOM', route:'inventory.uoms.create', icon: Plus }]">
    <DataTable route-name="inventory.uoms.index" title="UOMs" :columns="columns" :rows="uoms.data" :meta="uoms.meta" search-placeholder="Search units…">
      <template #cell-name="{row}"><span class="font-semibold">{{ row.name }}</span></template>
      <template #cell-symbol="{row}"><span class="font-mono text-sm px-2 py-0.5 bg-sky-500/10 text-sky-600 rounded">{{ row.symbol }}</span></template>
      <template #cell-is_active="{row}"><StatusBadge :label="row.is_active?'Active':'Inactive'" :tone="row.is_active?'success':'neutral'" /></template>
      <template #cell-actions="{row}"><div class="flex justify-end gap-1"><Link :href="route('inventory.uoms.edit',row.id)" class="size-8 grid place-items-center rounded-md hover:bg-accent"><Pencil class="size-4"/></Link><button @click="askDelete(row)" class="size-8 grid place-items-center rounded-md hover:bg-destructive/10 hover:text-destructive"><Trash2 class="size-4"/></button></div></template>
    </DataTable>
    <ConfirmDialog v-model:open="confirmOpen" :processing="processing" title="Delete UOM?" :description="`Delete ${pending?.name}?`" confirm-label="Delete" @confirm="confirmDelete" />
      </PageHeader>
  </AdminLayout>
</template>
