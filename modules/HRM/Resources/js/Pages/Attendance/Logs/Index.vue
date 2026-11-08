<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Trash2, Play, Plus, Clock } from 'lucide-vue-next';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '../../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import PageHeader from '../../../../../../CORE/Resources/js/Components/PageHeader.vue';
import DataTable, { type DataTableColumn } from '../../../../../../CORE/Resources/js/Components/DataTable.vue';
import type { FilterDef, Paginator } from '../../../../../../CORE/Resources/js/types';
import { formatDateTime } from '../../../../../../CORE/Resources/js/Utils/format';
interface Row { id:number; device_user_id:string; employee_id?:string; employee?:string; department?:string; designation?:string; punch_time:string; device_id?:number; punch_type:string; is_processed:boolean; created_at:string }
defineProps<{ logs: Paginator<Row>; filterDefs: FilterDef[] }>();
const columns: DataTableColumn[] = [
  {key:'device_user_id',label:'Device UID'},{key:'employee_id',label:'Employee'},{key:'punch_time',label:'Punch Time',sortable:true},{key:'punch_type',label:'Type',align:'center'},{key:'is_processed',label:'Processed',align:'center'},{key:'actions',label:'',align:'right',exportable:false,width:'1rem'}
];
function del(id:number){ router.delete(route('hrm.attendance.logs.destroy',id),{preserveScroll:true}); }
function processLogs(){ router.post(route('hrm.attendance.logs.process'),{limit:500},{preserveScroll:true}); }
const showManual=ref(false);
const manual=ref({employee_id:'',punch_time:'',device_id:null});
function addManual(){ router.post(route('hrm.attendance.manual'),manual.value,{onSuccess(){ showManual.value=false; manual.value={employee_id:'',punch_time:'',device_id:null}; }, preserveScroll:true}); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Attendance' }, { title: 'Attendance Logs' }]">
    <Head title="Attendance Logs"/>
    <PageHeader title="Attendance Logs (Raw)" description="Raw punches from biometric devices — filter by Dept / Designation / Employee. Search by name works." :icon="Clock" :actions="[{ label: showManual ? 'Close Manual' : 'Manual Punch', icon: Plus, variant: 'outline', onClick: () => showManual = !showManual }, { label: 'Process Pending', icon: Play, onClick: processLogs }]">
      <div v-if="showManual" class="bg-card border rounded-xl p-4 shadow-sm flex flex-wrap gap-3 items-end">
        <div class="space-y-1"><Label>Employee ID</Label><Input v-model="manual.employee_id" placeholder="EMP-0001" class="w-40 h-9" /></div>
        <div class="space-y-1"><Label>Punch Time</Label><Input v-model="manual.punch_time" type="datetime-local" class="w-56 h-9" /></div>
        <Button size="sm" @click="addManual" class="rounded-full">Add Log</Button>
      </div>
      <DataTable route-name="hrm.attendance.logs.index" title="Attendance Logs" :columns="columns" :rows="(logs.data as unknown as Record<string,unknown>[])" :meta="logs.meta" :filter-defs="filterDefs" search-placeholder="Search by name, device UID or Employee ID…">
        <template #cell-device_user_id="{row}"><span class="font-mono text-sm bg-muted px-1.5 py-0.5 rounded">{{ row.device_user_id }}</span></template>
        <template #cell-employee_id="{row}"><div class="leading-tight"><div class="text-sm font-medium">{{ row.employee_id || '—' }}<span v-if="row.employee" class="text-muted-foreground text-xs"> ({{ row.employee }})</span></div><div v-if="row.department" class="text-[11px] text-muted-foreground">{{ row.department }}<span v-if="row.designation"> · {{ row.designation }}</span></div></div></template>
        <template #cell-punch_time="{row}"><span class="text-sm font-mono">{{ formatDateTime(row.punch_time) }}</span></template>
        <template #cell-punch_type="{row}"><span class="px-2 py-0.5 rounded-full text-xs font-medium" :class="row.punch_type==='IN'?'bg-emerald-500/10 text-emerald-600': row.punch_type==='OUT'?'bg-red-500/10 text-red-600':'bg-muted text-muted-foreground'">{{ row.punch_type }}</span></template>
        <template #cell-is_processed="{row}"><span class="px-2 py-0.5 rounded-full text-xs font-medium" :class="row.is_processed?'bg-emerald-500/10 text-emerald-600':'bg-amber-500/10 text-amber-600'">{{ row.is_processed?'✓ Processed':'Pending' }}</span></template>
        <template #cell-actions="{row}"><button @click="del(row.id)" class="size-8 grid place-items-center rounded-md hover:bg-destructive/10 hover:text-destructive"><Trash2 class="size-4"/></button></template>
      </DataTable>
    </PageHeader>
  </AdminLayout>
</template>
