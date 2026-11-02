<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Eye, Plus, Trash2, Check, X, CalendarDays, Clock } from 'lucide-vue-next';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import ConfirmDialog from '../../../../../CORE/Resources/js/Components/ConfirmDialog.vue';
import DataTable, { type DataTableColumn } from '../../../../../CORE/Resources/js/Components/DataTable.vue';
import type { Paginator } from '../../../../../CORE/Resources/js/types';
interface Row { id:number; employee_id:string; employee:string; leave_type:string|null; leave_type_color:string|null; code:string|null; from_date:string; to_date:string; days:number|string; is_half_day:boolean; status:string; created_at:string }
const props=defineProps<{ leaves: Paginator<Row>; filterDefs:any[]; canApprove:boolean }>();
const columns: DataTableColumn[]=[
  {key:'employee_id',label:'Employee',sortable:true},
  {key:'leave_type',label:'Leave Type',sortable:true},
  {key:'from_date',label:'Period',sortable:true},
  {key:'days',label:'Days',align:'center',sortable:true},
  {key:'status',label:'Status',align:'center',sortable:true},
  {key:'actions',label:'',align:'right',exportable:false,width:'9rem'},
];
const confirmOpen=ref(false), pending=ref<Row|null>(null);
function askDelete(r:Row){ pending.value=r; confirmOpen.value=true; }
function confirmDelete(){ if(!pending.value) return; router.delete(route('hrm.leaves.destroy',pending.value.id),{preserveScroll:true,onFinish(){confirmOpen.value=false;pending.value=null;}}); }
function approve(id:number){ router.post(route('hrm.leaves.approve',id),{},{preserveScroll:true}); }
function reject(id:number){ if(confirm('Reject this leave?')) router.post(route('hrm.leaves.reject',id),{},{preserveScroll:true}); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'HRM'},{title:'Leaves'}]">
    <Head title="Leave Requests"/>
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
      <div><h1 class="text-xl font-bold flex items-center gap-2"><CalendarDays class="size-5"/> Leave Requests</h1><p class="text-sm text-muted-foreground">Apply → manager approve — days auto-calculated, half-day supported.</p></div>
      <div class="flex gap-2">
        <Link :href="route('hrm.leaves.balances')"><Button variant="outline" size="sm">Leave Balances</Button></Link>
        <Link :href="route('hrm.leave-types.index')"><Button variant="outline" size="sm">Leave Types</Button></Link>
        <Link :href="route('hrm.leaves.create')"><Button size="sm"><Plus class="size-4"/> New Leave</Button></Link>
      </div>
    </div>
    <div class="grid grid-cols-1 sm:grid-cols-4 gap-3">
      <div class="bg-card border rounded-xl p-4"><p class="text-xs text-muted-foreground">Total</p><p class="text-2xl font-bold">{{ (leaves as any).meta?.total ?? leaves.data.length }}</p></div>
      <div class="bg-amber-50 border border-amber-200 rounded-xl p-4"><p class="text-xs text-amber-700">Pending</p><p class="text-2xl font-bold text-amber-700">{{ leaves.data.filter(s=>s.status==='Pending').length }}</p></div>
      <div class="bg-emerald-50 border border-emerald-200 rounded-xl p-4"><p class="text-xs text-emerald-700">Approved</p><p class="text-2xl font-bold text-emerald-700">{{ leaves.data.filter(s=>s.status==='Approved').length }}</p></div>
      <div class="bg-red-50 border border-red-200 rounded-xl p-4"><p class="text-xs text-red-700">Rejected</p><p class="text-2xl font-bold text-red-700">{{ leaves.data.filter(s=>s.status==='Rejected').length }}</p></div>
    </div>
    <DataTable route-name="hrm.leaves.index" title="Leave Requests" :columns="columns" :rows="(leaves.data as unknown as Record<string,unknown>[])" :meta="leaves.meta" :filter-defs="filterDefs">
      <template #cell-employee_id="{row}"><div><p class="font-mono font-semibold text-sm">{{ row.employee_id }}</p><p class="text-xs text-muted-foreground">{{ row.employee }}</p></div></template>
      <template #cell-leave_type="{row}"><span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-xs font-medium border" :style="{backgroundColor: (row as any).leave_type_color+'18', borderColor:(row as any).leave_type_color+'40', color:(row as any).leave_type_color || '#6366f1'}">{{ (row as any).code ? (row as any).code+' — ' : '' }}{{ row.leave_type || '—' }}</span></template>
      <template #cell-from_date="{row}"><span class="font-mono text-sm flex items-center gap-1"><Clock class="size-3"/>{{ row.from_date }} → {{ row.to_date }}</span><span v-if="(row as any).is_half_day" class="text-xs bg-amber-100 text-amber-700 px-1.5 py-0.5 rounded">½ day</span></template>
      <template #cell-days="{row}"><span class="font-mono font-semibold">{{ row.days }}d</span></template>
      <template #cell-status="{row}"><span class="px-2.5 py-0.5 rounded-full text-xs font-medium" :class="row.status==='Pending'?'bg-amber-100 text-amber-700 border border-amber-200': row.status==='Approved'?'bg-emerald-100 text-emerald-700 border border-emerald-200': row.status==='Rejected'?'bg-red-100 text-red-700 border border-red-200':'bg-muted'">{{ row.status }}</span></template>
      <template #cell-actions="{row}">
        <div class="flex justify-end gap-1">
          <Link :href="route('hrm.leaves.show',(row as any).id)" class="size-8 grid place-items-center rounded-md hover:bg-accent"><Eye class="size-4"/></Link>
          <button v-if="row.status==='Pending'&&canApprove" @click="approve((row as any).id)" class="size-8 grid place-items-center rounded-md bg-emerald-500 text-white hover:bg-emerald-600"><Check class="size-4"/></button>
          <button v-if="row.status==='Pending'&&canApprove" @click="reject((row as any).id)" class="size-8 grid place-items-center rounded-md bg-red-500 text-white hover:bg-red-600"><X class="size-4"/></button>
          <button @click="askDelete(row as unknown as Row)" class="size-8 grid place-items-center rounded-md hover:bg-destructive/10 hover:text-destructive"><Trash2 class="size-4"/></button>
        </div>
      </template>
    </DataTable>
    <ConfirmDialog v-model:open="confirmOpen" title="Delete leave?" :description="`Delete leave for ${pending?.employee_id} ${pending?.from_date}→${pending?.to_date}?`" confirm-label="Delete" @confirm="confirmDelete"/>
  </AdminLayout>
</template>
