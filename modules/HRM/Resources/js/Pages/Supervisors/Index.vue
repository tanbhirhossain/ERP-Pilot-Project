<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2, Eye, Shield } from 'lucide-vue-next';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import ConfirmDialog from '../../../../../CORE/Resources/js/Components/ConfirmDialog.vue';
import DataTable, { type DataTableColumn } from '../../../../../CORE/Resources/js/Components/DataTable.vue';
import type { Paginator } from '../../../../../CORE/Resources/js/types';
import { formatDate } from '../../../../../CORE/Resources/js/Utils/format';
interface Row {
  id:number; employee_id:string; employee:string; supervisor_kind:string;
  supervisor:{kind:string;id:string;name:string;email?:string}; supervisor_type:string;
  is_active:boolean; is_primary:boolean; effective_from?:string; created_at:string
}
defineProps<{ supervisors: Paginator<Row> }>();
const columns: DataTableColumn[] = [
  {key:'employee',label:'Employee',sortable:true},
  {key:'supervisor',label:'Supervisor'},
  {key:'supervisor_type',label:'Type',sortable:true,align:'center'},
  {key:'is_active',label:'Status',align:'center'},
  {key:'is_primary',label:'Primary',align:'center'},
  {key:'created_at',label:'Created',sortable:true,width:'11rem'},
  {key:'actions',label:'',align:'right',exportable:false,width:'1rem'},
];
const confirmOpen=ref(false), pending=ref<Row|null>(null), processing=ref(false);
function askDelete(r:Record<string,unknown>){ pending.value=r as unknown as Row; confirmOpen.value=true; }
function confirmDelete(){ if(!pending.value) return; processing.value=true; router.delete(route('hrm.supervisors.destroy',pending.value.id),{preserveScroll:true,onFinish(){processing.value=false;confirmOpen.value=false;pending.value=null;}}); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'HRM'},{title:'Supervisors'}]">
    <Head title="Supervisors"/>
    <!-- Flexibility banner -->
    <div style="background:linear-gradient(135deg,#1e40af 0%,#06b6d4 100%);border-radius:12px;padding:14px 18px;color:white;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:16px">
      <div style="display:flex;align-items:center;gap:10px">
        <div style="background:rgba(255,255,255,0.18);border-radius:50%;width:36px;height:36px;display:grid;place-items:center"><Shield :size="18"/></div>
        <div>
          <p style="font-weight:700;font-size:14px">Flexible Supervisor Assignment</p>
          <p style="font-size:12px;opacity:0.9">Assign a User account as supervisor — or another Employee as supervisor. Both flows share the same CRUD.</p>
        </div>
      </div>
      <span style="font-size:11px;background:rgba(255,255,255,0.2);padding:4px 10px;border-radius:999px">User ⇄ Employee</span>
    </div>

    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
      <div><h1 class="text-xl font-bold">Employee Supervisors</h1><p class="text-sm text-muted-foreground">Map employees to their supervisors — supports User and Employee supervisors.</p></div>
      <Link :href="route('hrm.supervisors.create')"><Button size="sm"><Plus class="size-4"/> Assign Supervisor</Button></Link>
    </div>
    <DataTable route-name="hrm.supervisors.index" title="Supervisors" :columns="columns" :rows="(supervisors.data as unknown as Record<string,unknown>[])" :meta="supervisors.meta" search-placeholder="Search employee or type...">
      <template #cell-employee="{row}"><span class="font-semibold">{{ row.employee }}</span><span class="text-xs text-muted-foreground ml-1">({{ row.employee_id }})</span></template>
      <template #cell-supervisor="{row}">
        <div class="flex items-center gap-2">
          <span class="inline-flex px-2 py-0.5 rounded-full text-[10px] font-bold" :style="row.supervisor.kind==='employee' ? 'background:#e0f2fe;color:#0369a1' : 'background:#fef3c7;color:#92400e'">{{ row.supervisor.kind==='employee' ? 'EMP' : 'USER' }}</span>
          <span class="text-sm font-medium">{{ row.supervisor.name }}</span>
        </div>
        <span v-if="row.supervisor.email" class="text-xs text-muted-foreground">{{ row.supervisor.email }}</span>
      </template>
      <template #cell-supervisor_type="{row}"><span class="text-xs font-medium px-2 py-0.5 rounded bg-slate-100">{{ row.supervisor_type }}</span></template>
      <template #cell-is_active="{row}"><span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium" :class="row.is_active?'bg-emerald-500/10 text-emerald-600':'bg-zinc-500/10 text-zinc-600'">{{ row.is_active?'Active':'Inactive' }}</span></template>
      <template #cell-is_primary="{row}"><span v-if="row.is_primary" class="text-xs font-bold text-amber-600">★ Primary</span><span v-else class="text-xs text-muted-foreground">—</span></template>
      <template #cell-created_at="{row}"><span class="text-sm text-muted-foreground">{{ formatDate(row.created_at) }}</span></template>
      <template #cell-actions="{row}">
        <div class="flex justify-end gap-1">
          <Link :href="route('hrm.supervisors.show',row.id)" class="size-8 grid place-items-center rounded-md hover:bg-accent"><Eye class="size-4"/></Link>
          <Link :href="route('hrm.supervisors.edit',row.id)" class="size-8 grid place-items-center rounded-md hover:bg-accent"><Pencil class="size-4"/></Link>
          <button @click="askDelete(row)" class="size-8 grid place-items-center rounded-md hover:bg-destructive/10 hover:text-destructive"><Trash2 class="size-4"/></button>
        </div>
      </template>
    </DataTable>
    <ConfirmDialog v-model:open="confirmOpen" :processing="processing" title="Remove supervisor?" :description="`Remove ${pending?.supervisor_type} assignment for ${pending?.employee}?`" confirm-label="Remove" @confirm="confirmDelete"/>
  </AdminLayout>
</template>
