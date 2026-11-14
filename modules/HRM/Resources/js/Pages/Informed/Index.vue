<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Eye, Plus, Trash2, Check, X, Bell, Clock } from 'lucide-vue-next';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import ConfirmDialog from '@core/Components/ConfirmDialog.vue';
import DataTable, { type DataTableColumn } from '@core/Components/DataTable.vue';
import type { Paginator } from '@core/types';
import { useConfirm } from '@core/Composables/useConfirm';
const { confirm } = useConfirm();
interface Row { id:number; employee_id:string; employee:string; informed_date:string; informed_type:string; expected_time:string|null; status:string; created_at:string }
defineProps<{ informed: Paginator<Row> }>();
const columns: DataTableColumn[]=[
  {key:'employee_id',label:'Employee',sortable:true},
  {key:'informed_date',label:'Date',sortable:true},
  {key:'informed_type',label:'Type',sortable:true},
  {key:'expected_time',label:'Expected',align:'center'},
  {key:'status',label:'Status',align:'center',sortable:true},
  {key:'actions',label:'',align:'right',exportable:false,width:'9rem'},
];
const confirmOpen=ref(false), pending=ref<Row|null>(null);
async function askDelete(r:Row){ pending.value=r; confirmOpen.value=true; }
async function confirmDelete(){ if(!pending.value) return; router.delete(route('hrm.informed.destroy',pending.value.id),{preserveScroll:true,onFinish(){confirmOpen.value=false;pending.value=null;}}); }
async function ack(id:number){ router.post(route('hrm.informed.acknowledge',id),{},{preserveScroll:true}); }
async function reject(id:number){ if(await confirm({ description: 'Reject this notice?', danger: true })) router.post(route('hrm.informed.reject',id),{},{preserveScroll:true}); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'HRM'},{title:'Informed Panel'}]">
    <Head title="Informed Panel"/>
    <PageHeader title="Informed Panel" description="Late/Early/Absent notices — employee informs, supervisor acknowledges. No payroll auto-deduction." :icon="Bell">
        <template #actions>
            <Link :href="route('hrm.informed.create')"><Button size="sm"><Plus class="size-4"/> New Notice</Button></Link>
        </template>
    </PageHeader>
    <div class="grid grid-cols-1 sm:grid-cols-4 gap-3">
      <div class="bg-card border rounded-xl p-4"><p class="text-xs text-muted-foreground">Total</p><p class="text-2xl font-bold">{{ informed.meta?.total ?? informed.data.length }}</p></div>
      <div class="bg-amber-50 border border-amber-200 rounded-xl p-4"><p class="text-xs text-amber-700">Pending</p><p class="text-2xl font-bold text-amber-700">{{ informed.data.filter(s=>s.status==='Pending').length }}</p></div>
      <div class="bg-emerald-50 border border-emerald-200 rounded-xl p-4"><p class="text-xs text-emerald-700">Acknowledged</p><p class="text-2xl font-bold text-emerald-700">{{ informed.data.filter(s=>s.status==='Acknowledged').length }}</p></div>
      <div class="bg-red-50 border border-red-200 rounded-xl p-4"><p class="text-xs text-red-700">Rejected</p><p class="text-2xl font-bold text-red-700">{{ informed.data.filter(s=>s.status==='Rejected').length }}</p></div>
    </div>
    <DataTable route-name="hrm.informed.index" title="Informed Notices" :columns="columns" :rows="informed.data" :meta="informed.meta">
      <template #cell-employee_id="{row}"><div><p class="font-mono font-semibold text-sm">{{ row.employee_id }}</p><p class="text-xs text-muted-foreground">{{ row.employee }}</p></div></template>
      <template #cell-informed_date="{row}"><span class="font-mono text-sm flex items-center gap-1"><Clock class="size-3"/>{{ row.informed_date }}</span></template>
      <template #cell-informed_type="{row}"><span class="px-2 py-0.5 rounded-full text-xs font-medium border" :class="row.informed_type==='Late Coming'?'bg-amber-50 text-amber-700 border-amber-200': row.informed_type==='Early Out'?'bg-blue-50 text-blue-700 border-blue-200': row.informed_type==='Absent Informed'?'bg-red-50 text-red-700 border-red-200': row.informed_type==='Field Work'?'bg-emerald-50 text-emerald-700 border-emerald-200':'bg-muted'">{{ row.informed_type }}</span></template>
      <template #cell-expected_time="{row}"><span class="font-mono text-sm">{{ row.expected_time || '—' }}</span></template>
      <template #cell-status="{row}"><span class="px-2.5 py-0.5 rounded-full text-xs font-medium" :class="row.status==='Pending'?'bg-amber-100 text-amber-700 border border-amber-200': row.status==='Acknowledged'?'bg-emerald-100 text-emerald-700 border border-emerald-200': row.status==='Rejected'?'bg-red-100 text-red-700 border border-red-200':'bg-muted'">{{ row.status }}</span></template>
      <template #cell-actions="{row}">
        <div class="flex justify-end gap-1">
          <Link :href="route('hrm.informed.show',row.id)" class="size-8 grid place-items-center rounded-md hover:bg-accent"><Eye class="size-4"/></Link>
          <button v-if="row.status==='Pending'" @click="ack(row.id)" class="size-8 grid place-items-center rounded-md bg-emerald-500 text-white hover:bg-emerald-600" title="Acknowledge"><Check class="size-4"/></button>
          <button v-if="row.status==='Pending'" @click="reject(row.id)" class="size-8 grid place-items-center rounded-md bg-red-500 text-white hover:bg-red-600" title="Reject"><X class="size-4"/></button>
          <button @click="askDelete(row)" class="size-8 grid place-items-center rounded-md hover:bg-destructive/10 hover:text-destructive"><Trash2 class="size-4"/></button>
        </div>
      </template>
    </DataTable>
    <ConfirmDialog v-model:open="confirmOpen" title="Delete notice?" :description="`Delete informed for ${pending?.employee_id} on ${pending?.informed_date}?`" confirm-label="Delete" @confirm="confirmDelete"/>
  </AdminLayout>
</template>
