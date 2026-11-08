<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2, Network, Users } from 'lucide-vue-next';
import { ref } from 'vue';

import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import PageHeader from '../../../../../CORE/Resources/js/Components/PageHeader.vue';
import ConfirmDialog from '../../../../../CORE/Resources/js/Components/ConfirmDialog.vue';
import DataTable, { type DataTableColumn } from '../../../../../CORE/Resources/js/Components/DataTable.vue';
import StatusBadge from '../../../../../CORE/Resources/js/Components/StatusBadge.vue';
import type { Paginator } from '../../../../../CORE/Resources/js/types';

interface Row { id:number; name:string; code?:string; type:string; parent?:{name:string}; description?:string; sort_order:number; is_active:boolean; partners_count:number; created_at:string }
defineProps<{ groups: Paginator<Row>; tree:any[]; parentOptions:any[] }>();
const columns: DataTableColumn[] = [
  {key:'name',label:'Group',sortable:true},
  {key:'code',label:'Code',sortable:true,width:'7rem'},
  {key:'type',label:'Type',align:'center'},
  {key:'parent',label:'Parent'},
  {key:'partners_count',label:'Partners',align:'center',sortable:true},
  {key:'is_active',label:'Active',align:'center'},
  {key:'actions',label:'',align:'right',exportable:false,width:'6rem'},
];
const confirmOpen=ref(false), pending=ref<Row|null>(null), processing=ref(false);
function askDelete(r:Record<string,unknown>){ pending.value=r as unknown as Row; confirmOpen.value=true; }
function confirmDelete(){ if(!pending.value) return; processing.value=true; router.delete(route('partner.groups.destroy',pending.value.id),{preserveScroll:true,onFinish(){processing.value=false;confirmOpen.value=false;pending.value=null;}}); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Ven & Cust' }, { title: 'Groups' }]">
    <Head title="Partner Groups" />
    <PageHeader title="Partner Groups" description="Organize vendors & customers hierarchically — like departments for partners." :icon="Network" :actions="[{ label:'Add Group', route:'partner.groups.create', icon: Plus }]">
    <DataTable route-name="partner.groups.index" title="Partner Groups" :columns="columns" :rows="(groups.data as unknown as Record<string,unknown>[])" :meta="groups.meta" search-placeholder="Search groups…">
      <template #cell-name="{row}"><div><p class="font-semibold">{{ row.name }}</p><p class="text-xs text-muted-foreground">{{ row.description || '' }}</p></div></template>
      <template #cell-code="{row}"><span class="font-mono text-xs px-1.5 py-0.5 rounded bg-muted">{{ row.code || '—' }}</span></template>
      <template #cell-type="{row}"><StatusBadge :label="row.type" :tone="row.type==='vendor'?'info':row.type==='customer'?'success':'primary'" /></template>
      <template #cell-parent="{row}"><span class="text-sm">{{ row.parent?.name || '—' }}</span></template>
      <template #cell-partners_count="{row}"><span class="inline-flex items-center gap-1 text-sm"><Users class="size-3"/>{{ row.partners_count }}</span></template>
      <template #cell-is_active="{row}"><StatusBadge :label="row.is_active?'Active':'Inactive'" :tone="row.is_active?'success':'neutral'" /></template>
      <template #cell-actions="{row}"><div class="flex justify-end gap-1"><Link :href="route('partner.groups.edit',row.id)" class="size-8 grid place-items-center rounded-md hover:bg-accent"><Pencil class="size-4"/></Link><button @click="askDelete(row)" class="size-8 grid place-items-center rounded-md hover:bg-destructive/10 hover:text-destructive"><Trash2 class="size-4"/></button></div></template>
    </DataTable>
    <ConfirmDialog v-model:open="confirmOpen" :processing="processing" title="Delete group?" :description="`Delete ${pending?.name}? Ensure no partners or child groups remain.`" confirm-label="Delete" @confirm="confirmDelete" />
      </PageHeader>
  </AdminLayout>
</template>
