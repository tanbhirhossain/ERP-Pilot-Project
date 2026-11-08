<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2 } from 'lucide-vue-next';
import { ref } from 'vue';

import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import PageHeader from '../../../../../CORE/Resources/js/Components/PageHeader.vue';
import ConfirmDialog from '../../../../../CORE/Resources/js/Components/ConfirmDialog.vue';
import DataTable, { type DataTableColumn } from '../../../../../CORE/Resources/js/Components/DataTable.vue';
import type { Paginator} from '../../../../../CORE/Resources/js/types';
import { formatDate } from '../../../../../CORE/Resources/js/Utils/format';
interface Row { id:number; name:string; code?:string; parent?:{id:number;name:string}; is_active:boolean; created_at:string }
defineProps<{ departments: Paginator<Row> }>();
const columns: DataTableColumn[] = [
  {key:'name',label:'Department',sortable:true},
  {key:'code',label:'Code',sortable:true},
  {key:'parent',label:'Parent'},
  {key:'is_active',label:'Status',align:'center'},
  {key:'created_at',label:'Created',sortable:true,width:'11rem'},
  {key:'actions',label:'',align:'right',exportable:false,width:'1rem'},
];
const confirmOpen=ref(false), pending=ref<Row|null>(null), processing=ref(false);
function askDelete(r:Record<string,unknown>){ pending.value=r as unknown as Row; confirmOpen.value=true; }
function confirmDelete(){ if(!pending.value) return; processing.value=true; router.delete(route('hrm.departments.destroy',pending.value.id),{preserveScroll:true,onFinish(){processing.value=false;confirmOpen.value=false;pending.value=null;}}); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'HRM'},{title:'Departments'}]">
    <Head title="Departments"/>
    <PageHeader title="Departments" description="Organize structure by departments & sub-departments." :icon="Pencil" :actions="[{ label:'New Department', route:'hrm.departments.create', icon: Plus }]">
    <DataTable route-name="hrm.departments.index" title="Departments" :columns="columns" :rows="(departments.data as unknown as Record<string,unknown>[])" :meta="departments.meta" search-placeholder="Search department...">
      <template #cell-name="{row}"><span class="font-semibold">{{ row.name }}</span></template>
      <template #cell-code="{row}"><span class="text-sm text-muted-foreground">{{ row.code || '—' }}</span></template>
      <template #cell-parent="{row}"><span class="text-sm">{{ row.parent?.name || '—' }}</span></template>
      <template #cell-is_active="{row}"><span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium" :class="row.is_active?'bg-emerald-500/10 text-emerald-600':'bg-zinc-500/10 text-zinc-600'">{{ row.is_active?'Active':'Inactive' }}</span></template>
      <template #cell-created_at="{row}"><span class="text-sm text-muted-foreground">{{ formatDate(row.created_at) }}</span></template>
      <template #cell-actions="{row}">
        <div class="flex justify-end gap-1">
          <Link :href="route('hrm.departments.edit',row.id)" class="size-8 grid place-items-center rounded-md hover:bg-accent"><Pencil class="size-4"/></Link>
          <button @click="askDelete(row)" class="size-8 grid place-items-center rounded-md hover:bg-destructive/10 hover:text-destructive"><Trash2 class="size-4"/></button>
        </div>
      </template>
    </DataTable>
    <ConfirmDialog v-model:open="confirmOpen" :processing="processing" title="Delete department?" :description="`Delete ${pending?.name}?`" confirm-label="Delete" @confirm="confirmDelete"/>
      </PageHeader>
  </AdminLayout>
</template>
