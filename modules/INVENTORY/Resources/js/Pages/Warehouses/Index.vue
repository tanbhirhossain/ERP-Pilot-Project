<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2, Warehouse, Eye } from 'lucide-vue-next';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import PageHeader from '../../../../../CORE/Resources/js/Components/PageHeader.vue';
import ConfirmDialog from '../../../../../CORE/Resources/js/Components/ConfirmDialog.vue';
import DataTable, { type DataTableColumn } from '../../../../../CORE/Resources/js/Components/DataTable.vue';
import StatusBadge from '../../../../../CORE/Resources/js/Components/StatusBadge.vue';
import type { Paginator } from '../../../../../CORE/Resources/js/types';
interface Row { id:number; name:string; code:string; type:string; city?:string; manager?:{name:string}; is_default:boolean; is_active:boolean; locations_count:number; stocks_count:number; created_at:string }
defineProps<{ warehouses: Paginator<Row> }>();
const columns: DataTableColumn[] = [
  {key:'code',label:'Code',sortable:true,width:'7rem'},
  {key:'name',label:'Warehouse',sortable:true},
  {key:'type',label:'Type',align:'center'},
  {key:'city',label:'City'},
  {key:'is_default',label:'Default',align:'center'},
  {key:'is_active',label:'Active',align:'center'},
  {key:'actions',label:'',align:'right',exportable:false,width:'7rem'},
];
const confirmOpen=ref(false), pending=ref<Row|null>(null), processing=ref(false);
function askDelete(r:Record<string,unknown>){ pending.value=r as unknown as Row; confirmOpen.value=true; }
function confirmDelete(){ if(!pending.value) return; processing.value=true; router.delete(route('inventory.warehouses.destroy',pending.value.id),{preserveScroll:true,onFinish(){processing.value=false;confirmOpen.value=false;pending.value=null;}}); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'Inventory'},{title:'Warehouses'}]">
    <Head title="Warehouses" />
    <PageHeader title="Warehouses" description="Storage locations — main, sub, transit." :icon="Warehouse" :actions="[{ label:'Add Warehouse', route:'inventory.warehouses.create', icon: Plus }]">
    <DataTable route-name="inventory.warehouses.index" title="Warehouses" :columns="columns" :rows="(warehouses.data as unknown as Record<string,unknown>[])" :meta="warehouses.meta" search-placeholder="Search warehouses…">
      <template #cell-code="{row}"><span class="font-mono text-sm font-semibold bg-amber-500/10 text-amber-600 px-1.5 py-0.5 rounded">{{ row.code }}</span></template>
      <template #cell-name="{row}"><div><p class="font-semibold">{{ row.name }}</p><p class="text-xs text-muted-foreground">{{ row.manager?.name || 'No manager' }} · {{ row.locations_count }} locations · {{ row.stocks_count }} stock lines</p></div></template>
      <template #cell-type="{row}"><StatusBadge :label="row.type" :tone="row.type==='main'?'primary':'neutral'" /></template>
      <template #cell-is_default="{row}"><StatusBadge v-if="row.is_default" label="Default" tone="success" /><span v-else class="text-muted-foreground text-sm">—</span></template>
      <template #cell-is_active="{row}"><StatusBadge :label="row.is_active?'Active':'Inactive'" :tone="row.is_active?'success':'neutral'" /></template>
      <template #cell-actions="{row}"><div class="flex justify-end gap-1"><Link :href="route('inventory.warehouses.show',row.id)" class="size-8 grid place-items-center rounded-md hover:bg-accent"><Eye class="size-4"/></Link><Link :href="route('inventory.warehouses.edit',row.id)" class="size-8 grid place-items-center rounded-md hover:bg-accent"><Pencil class="size-4"/></Link><button @click="askDelete(row)" class="size-8 grid place-items-center rounded-md hover:bg-destructive/10 hover:text-destructive"><Trash2 class="size-4"/></button></div></template>
    </DataTable>
    <ConfirmDialog v-model:open="confirmOpen" :processing="processing" title="Delete warehouse?" :description="`Delete ${pending?.name} (${pending?.code})?`" confirm-label="Delete" @confirm="confirmDelete" />
      </PageHeader>
  </AdminLayout>
</template>
