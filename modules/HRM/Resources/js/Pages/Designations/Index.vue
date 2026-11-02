<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2 } from 'lucide-vue-next';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import ConfirmDialog from '../../../../../CORE/Resources/js/Components/ConfirmDialog.vue';
import DataTable, { type DataTableColumn } from '../../../../../CORE/Resources/js/Components/DataTable.vue';
import type { Paginator } from '../../../../../CORE/Resources/js/types';
import { formatDate } from '../../../../../CORE/Resources/js/Utils/format';
interface Row { id:number; name:string; code?:string; department?:{name:string}; grade?:string; level?:number; is_active:boolean; created_at:string }
defineProps<{ designations: Paginator<Row>; departmentOptions:any[] }>();
const columns: DataTableColumn[] = [
  {key:'name',label:'Designation',sortable:true},
  {key:'code',label:'Code',sortable:true},
  {key:'department',label:'Department'},
  {key:'grade',label:'Grade'},
  {key:'is_active',label:'Status',align:'center'},
  {key:'created_at',label:'Created',sortable:true,width:'11rem'},
  {key:'actions',label:'',align:'right',exportable:false,width:'1rem'},
];
const confirmOpen=ref(false), pending=ref<Row|null>(null), processing=ref(false);
function askDelete(r:Record<string,unknown>){ pending.value=r as unknown as Row; confirmOpen.value=true; }
function confirmDelete(){ if(!pending.value) return; processing.value=true; router.delete(route('hrm.designations.destroy',pending.value.id),{preserveScroll:true,onFinish(){processing.value=false;confirmOpen.value=false;pending.value=null;}}); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'HRM'},{title:'Designations'}]">
    <Head title="Designations"/>
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3"><div><h1 class="text-xl font-bold">Designations</h1><p class="text-sm text-muted-foreground">Manage job titles & grades.</p></div><Link :href="route('hrm.designations.create')"><Button size="sm"><Plus class="size-4"/> New Designation</Button></Link></div>
    <DataTable route-name="hrm.designations.index" title="Designations" :columns="columns" :rows="(designations.data as unknown as Record<string,unknown>[])" :meta="designations.meta">
      <template #cell-name="{row}"><span class="font-semibold">{{ row.name }}</span></template>
      <template #cell-code="{row}"><span class="text-sm text-muted-foreground">{{ row.code || '—' }}</span></template>
      <template #cell-department="{row}"><span class="text-sm">{{ row.department?.name || '—' }}</span></template>
      <template #cell-grade="{row}"><span class="text-sm">{{ row.grade || '—' }}</span></template>
      <template #cell-is_active="{row}"><span class="px-2 py-0.5 rounded-full text-xs font-medium" :class="row.is_active?'bg-emerald-500/10 text-emerald-600':'bg-zinc-500/10 text-zinc-600'">{{ row.is_active?'Active':'Inactive' }}</span></template>
      <template #cell-created_at="{row}"><span class="text-sm text-muted-foreground">{{ formatDate(row.created_at) }}</span></template>
      <template #cell-actions="{row}"><div class="flex justify-end gap-1"><Link :href="route('hrm.designations.edit',row.id)" class="size-8 grid place-items-center rounded-md hover:bg-accent"><Pencil class="size-4"/></Link><button @click="askDelete(row)" class="size-8 grid place-items-center rounded-md hover:bg-destructive/10 hover:text-destructive"><Trash2 class="size-4"/></button></div></template>
    </DataTable>
    <ConfirmDialog v-model:open="confirmOpen" :processing="processing" title="Delete designation?" :description="`Delete ${pending?.name}?`" confirm-label="Delete" @confirm="confirmDelete"/>
  </AdminLayout>
</template>
