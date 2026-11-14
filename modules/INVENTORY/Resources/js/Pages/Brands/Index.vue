<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2, Award } from 'lucide-vue-next';
import { ref } from 'vue';

import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import ConfirmDialog from '@core/Components/ConfirmDialog.vue';
import DataTable, { type DataTableColumn } from '@core/Components/DataTable.vue';
import StatusBadge from '@core/Components/StatusBadge.vue';
import type { Paginator } from '@core/types';
interface Row { id:number; name:string; code?:string; is_active:boolean; products_count:number; created_at:string }
defineProps<{ brands: Paginator<Row> }>();
const columns: DataTableColumn[] = [
  {key:'name',label:'Brand',sortable:true},
  {key:'code',label:'Code'},
  {key:'products_count',label:'Products',align:'center'},
  {key:'is_active',label:'Active',align:'center'},
  {key:'actions',label:'',align:'right',exportable:false,width:'6rem'},
];
const confirmOpen=ref(false), pending=ref<Row|null>(null), processing=ref(false);
function askDelete(r:Record<string,unknown>){ pending.value=r as unknown as Row; confirmOpen.value=true; }
function confirmDelete(){ if(!pending.value) return; processing.value=true; router.delete(route('inventory.brands.destroy',pending.value.id),{preserveScroll:true,onFinish(){processing.value=false;confirmOpen.value=false;pending.value=null;}}); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'Inventory'},{title:'Brands'}]">
    <Head title="Brands" />
    <PageHeader title="Brands" description="Product brands / manufacturers." :icon="Award" :actions="[{ label:'Add Brand', route:'inventory.brands.create', icon: Plus }]">
    <DataTable route-name="inventory.brands.index" title="Brands" :columns="columns" :rows="brands.data" :meta="brands.meta" search-placeholder="Search brands…">
      <template #cell-name="{row}"><span class="font-semibold">{{ row.name }}</span></template>
      <template #cell-code="{row}"><span class="font-mono text-xs px-1.5 py-0.5 bg-muted rounded">{{ row.code || '—' }}</span></template>
      <template #cell-is_active="{row}"><StatusBadge :label="row.is_active?'Active':'Inactive'" :tone="row.is_active?'success':'neutral'" /></template>
      <template #cell-actions="{row}"><div class="flex justify-end gap-1"><Link :href="route('inventory.brands.edit',row.id)" class="size-8 grid place-items-center rounded-md hover:bg-accent"><Pencil class="size-4"/></Link><button @click="askDelete(row)" class="size-8 grid place-items-center rounded-md hover:bg-destructive/10 hover:text-destructive"><Trash2 class="size-4"/></button></div></template>
    </DataTable>
    <ConfirmDialog v-model:open="confirmOpen" :processing="processing" title="Delete brand?" :description="`Delete ${pending?.name}?`" confirm-label="Delete" @confirm="confirmDelete" />
      </PageHeader>
  </AdminLayout>
</template>
