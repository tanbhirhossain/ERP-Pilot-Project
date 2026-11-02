<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Eye, Plus, Trash2, Check, X, ArrowLeftRight, Clock } from 'lucide-vue-next';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import ConfirmDialog from '../../../../../CORE/Resources/js/Components/ConfirmDialog.vue';
import DataTable, { type DataTableColumn } from '../../../../../CORE/Resources/js/Components/DataTable.vue';
import type { Paginator } from '../../../../../CORE/Resources/js/types';
interface Row { id:number; swap_date:string; requester:string; requester_employee_id:string; target_employee_id:string|null; requested_shift:string|null; target_shift:string|null; reason:string|null; status:string; created_at:string }
const props = defineProps<{ swaps: Paginator<Row> }>();
const columns: DataTableColumn[] = [
  {key:'swap_date',label:'Swap Date',sortable:true},
  {key:'requester',label:'Requester',sortable:true},
  {key:'requested_shift',label:'Requested Shift'},
  {key:'status',label:'Status',align:'center',sortable:true},
  {key:'actions',label:'',align:'right',exportable:false,width:'8rem'},
];
const confirmOpen=ref(false), pending=ref<Row|null>(null);
function askDelete(r:Row){ pending.value=r; confirmOpen.value=true; }
function confirmDelete(){ if(!pending.value) return; router.delete(route('hrm.swaps.destroy', pending.value.id), { preserveScroll:true, onFinish(){ confirmOpen.value=false; pending.value=null; } }); }
function approve(id:number){ router.post(route('hrm.swaps.approve', id), {}, { preserveScroll:true }); }
function reject(id:number){ if(confirm('Reject this swap?')) router.post(route('hrm.swaps.reject', id), {}, { preserveScroll:true }); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'HRM'},{title:'Roster Swaps'}]">
    <Head title="Roster Swaps"/>
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
      <div>
        <h1 class="text-xl font-bold flex items-center gap-2"><ArrowLeftRight class="size-5"/> Roster Swap Panel</h1>
        <p class="text-sm text-muted-foreground">Request & approve roster swaps — employee ↔ employee or shift change. Like Excel, but for swaps.</p>
      </div>
      <Link :href="route('hrm.swaps.create')"><Button size="sm"><Plus class="size-4"/> New Swap Request</Button></Link>
    </div>
    <div class="grid grid-cols-1 sm:grid-cols-4 gap-3">
      <div class="bg-card border rounded-xl p-4"><p class="text-xs text-muted-foreground">Total Requests</p><p class="text-2xl font-bold">{{ (swaps as any).meta?.total ?? swaps.data.length }}</p></div>
      <div class="bg-amber-50 border border-amber-200 rounded-xl p-4"><p class="text-xs text-amber-700">Pending</p><p class="text-2xl font-bold text-amber-700">{{ swaps.data.filter(s=>s.status==='Pending').length }}</p></div>
      <div class="bg-emerald-50 border border-emerald-200 rounded-xl p-4"><p class="text-xs text-emerald-700">Approved</p><p class="text-2xl font-bold text-emerald-700">{{ swaps.data.filter(s=>s.status==='Approved').length }}</p></div>
      <div class="bg-red-50 border border-red-200 rounded-xl p-4"><p class="text-xs text-red-700">Rejected</p><p class="text-2xl font-bold text-red-700">{{ swaps.data.filter(s=>s.status==='Rejected').length }}</p></div>
    </div>
    <DataTable route-name="hrm.swaps.index" title="Swap Requests" :columns="columns" :rows="(swaps.data as unknown as Record<string,unknown>[])" :meta="swaps.meta">
      <template #cell-swap_date="{row}"><span class="font-mono text-sm flex items-center gap-1"><Clock class="size-3"/>{{ row.swap_date }}</span></template>
      <template #cell-requester="{row}"><div><p class="font-semibold text-sm">{{ row.requester }}</p><p class="text-xs text-muted-foreground">{{ row.requester_employee_id }} <span v-if="row.target_employee_id">→ {{ row.target_employee_id }}</span></p></div></template>
      <template #cell-requested_shift="{row}"><span class="text-sm">{{ row.requested_shift || '—' }} <span v-if="row.target_shift" class="text-muted-foreground">→ {{ row.target_shift }}</span></span></template>
      <template #cell-status="{row}">
        <span class="px-2.5 py-0.5 rounded-full text-xs font-medium" :class="row.status==='Pending'?'bg-amber-100 text-amber-700 border border-amber-200': row.status==='Approved'?'bg-emerald-100 text-emerald-700 border border-emerald-200': row.status==='Rejected'?'bg-red-100 text-red-700 border border-red-200':'bg-muted'">{{ row.status }}</span>
      </template>
      <template #cell-actions="{row}">
        <div class="flex justify-end gap-1">
          <Link :href="route('hrm.swaps.show', row.id)" class="size-8 grid place-items-center rounded-md hover:bg-accent"><Eye class="size-4"/></Link>
          <button v-if="row.status==='Pending'" @click="approve((row as any).id)" class="size-8 grid place-items-center rounded-md bg-emerald-500 text-white hover:bg-emerald-600" title="Approve"><Check class="size-4"/></button>
          <button v-if="row.status==='Pending'" @click="reject((row as any).id)" class="size-8 grid place-items-center rounded-md bg-red-500 text-white hover:bg-red-600" title="Reject"><X class="size-4"/></button>
          <button @click="askDelete(row as unknown as Row)" class="size-8 grid place-items-center rounded-md hover:bg-destructive/10 hover:text-destructive"><Trash2 class="size-4"/></button>
        </div>
      </template>
    </DataTable>
    <ConfirmDialog v-model:open="confirmOpen" title="Delete swap?" :description="`Delete swap for ${pending?.requester_employee_id} on ${pending?.swap_date}?`" confirm-label="Delete" @confirm="confirmDelete"/>
  </AdminLayout>
</template>
