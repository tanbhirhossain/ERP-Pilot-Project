<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2, Eye } from 'lucide-vue-next';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import PageHeader from '../../../../../CORE/Resources/js/Components/PageHeader.vue';
import ConfirmDialog from '../../../../../CORE/Resources/js/Components/ConfirmDialog.vue';
import DataTable, { type DataTableColumn } from '../../../../../CORE/Resources/js/Components/DataTable.vue';
import type { Paginator } from '../../../../../CORE/Resources/js/types';
import { formatDate } from '../../../../../CORE/Resources/js/Utils/format';
interface Row { id:number; employee_id:string; biometric_id?:string|null; full_name:string; email?:string; phone?:string; department?:{name:string}; designation?:{name:string}; employment_type:string; status:string; joining_date?:string }
defineProps<{ employees: Paginator<Row> }>();
const columns: DataTableColumn[] = [
  {key:'employee_id',label:'ID',sortable:true,width:'9rem'},
  {key:'biometric_id',label:'Biometric ID',sortable:true,width:'8rem'},
  {key:'full_name',label:'Employee',sortable:true},
  {key:'department',label:'Department'},
  {key:'designation',label:'Designation'},
  {key:'status',label:'Status',align:'center'},
  {key:'joining_date',label:'Joining',sortable:true,width:'10rem'},
  {key:'actions',label:'',align:'right',exportable:false,width:'6rem'},
];
const confirmOpen=ref(false), pending=ref<Row|null>(null), processing=ref(false);
function askDelete(r:Record<string,unknown>){ pending.value=r as unknown as Row; confirmOpen.value=true; }
function confirmDelete(){ if(!pending.value) return; processing.value=true; router.delete(route('hrm.employees.destroy',pending.value.id),{preserveScroll:true,onFinish(){processing.value=false;confirmOpen.value=false;pending.value=null;}}); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'HRM'},{title:'Employees'}]">
    <Head title="Employees"/>
    <PageHeader title="Employees" description="Central HR directory — profiles, salaries, policies linked." :icon="Pencil" :actions="[{ label:'Add Employee', route:'hrm.employees.create', icon: Plus }]">
    <DataTable route-name="hrm.employees.index" title="Employees" :columns="columns" :rows="(employees.data as unknown as Record<string,unknown>[])" :meta="employees.meta" search-placeholder="Search by ID, name, email...">
      <template #cell-employee_id="{row}"><span class="font-mono text-sm font-semibold bg-primary/10 text-primary px-1.5 py-0.5 rounded">{{ row.employee_id }}</span></template>
      <template #cell-biometric_id="{row}"><span class="font-mono text-xs px-1.5 py-0.5 rounded" :class="row.biometric_id ? 'bg-emerald-500/10 text-emerald-600 font-semibold' : 'bg-amber-500/10 text-amber-600'">{{ row.biometric_id || '—' }}</span></template>
      <template #cell-full_name="{row}"><div><p class="font-semibold">{{ row.full_name }}</p><p class="text-xs text-muted-foreground">{{ row.email || row.phone || '' }}</p></div></template>
      <template #cell-department="{row}"><span class="text-sm">{{ row.department?.name || '—' }}</span></template>
      <template #cell-designation="{row}"><span class="text-sm">{{ row.designation?.name || '—' }}</span></template>
      <template #cell-status="{row}"><span class="px-2 py-0.5 rounded-full text-xs font-medium" :class="row.status==='Active'?'bg-emerald-500/10 text-emerald-600': row.status==='Inactive'?'bg-zinc-500/10 text-zinc-600':'bg-amber-500/10 text-amber-600'">{{ row.status }}</span></template>
      <template #cell-joining_date="{row}"><span class="text-sm text-muted-foreground">{{ row.joining_date?formatDate(row.joining_date):'—' }}</span></template>
      <template #cell-actions="{row}"><div class="flex justify-end gap-1">
        <Link :href="route('hrm.employees.show',row.id)" class="size-8 grid place-items-center rounded-md hover:bg-accent" title="View"><Eye class="size-4"/></Link>
        <Link :href="route('hrm.employees.edit',row.id)" class="size-8 grid place-items-center rounded-md hover:bg-accent"><Pencil class="size-4"/></Link>
        <button @click="askDelete(row)" class="size-8 grid place-items-center rounded-md hover:bg-destructive/10 hover:text-destructive"><Trash2 class="size-4"/></button>
      </div></template>
    </DataTable>
    <ConfirmDialog v-model:open="confirmOpen" :processing="processing" title="Delete employee?" :description="`Delete ${pending?.full_name} (${pending?.employee_id})?`" confirm-label="Delete" @confirm="confirmDelete"/>
      </PageHeader>
  </AdminLayout>
</template>
