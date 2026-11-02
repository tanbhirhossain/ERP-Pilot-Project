<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2, CalendarDays, ArrowLeftRight } from 'lucide-vue-next';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import ConfirmDialog from '../../../../../CORE/Resources/js/Components/ConfirmDialog.vue';
import DataTable, { type DataTableColumn } from '../../../../../CORE/Resources/js/Components/DataTable.vue';
import SearchableSelect from '../../../../../CORE/Resources/js/Components/SearchableSelect.vue';
import type { Paginator } from '../../../../../CORE/Resources/js/types';
interface Row { id:number; employee_id:string; employee:string; shift?:{shift_name:string;start_time:string;end_time:string}; roster_date:string; status:string; scheduled_start_time?:string; scheduled_end_time?:string }
const props=defineProps<{ duties: Paginator<Row>; shiftOptions:any[]; employeeOptions:any[] }>();
const columns: DataTableColumn[] = [
  {key:'employee_id',label:'Employee',sortable:true},{key:'roster_date',label:'Date',sortable:true},{key:'shift',label:'Shift'},{key:'status',label:'Status',align:'center'},{key:'actions',label:'',align:'right',exportable:false,width:'1rem'}
];
const confirmOpen=ref(false), pending=ref<Row|null>(null), processing=ref(false);
function askDelete(r:Record<string,unknown>){ pending.value=r as unknown as Row; confirmOpen.value=true; }
function confirmDelete(){ if(!pending.value) return; processing.value=true; router.delete(route('hrm.duties.destroy',pending.value.id),{preserveScroll:true,onFinish(){processing.value=false;confirmOpen.value=false;pending.value=null;}}); }
// Bulk assign
const bulkOpen=ref(false);
const bulk=ref({shift_id:null as number|null, employee_ids:[] as string[], from_date:'', to_date:''});
function submitBulk(){ router.post(route('hrm.duties.bulk'),{employee_ids:bulk.value.employee_ids, shift_id:bulk.value.shift_id, from_date:bulk.value.from_date, to_date:bulk.value.to_date},{onSuccess(){ bulkOpen.value=false; bulk.value={shift_id:null,employee_ids:[],from_date:'',to_date:''}; }}); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'HRM'},{title:'Roster Duties'}]">
    <Head title="Roster Duties"/>
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
      <div><h1 class="text-xl font-bold">Roster Duties</h1><p class="text-sm text-muted-foreground">Assign shifts per employee per date — bulk assign supported.</p></div>
      <div class="flex gap-2">
        <Link :href="route('hrm.duties.bulk-week')"><Button size="sm" class="bg-violet-600 text-white hover:bg-violet-700"><CalendarDays class="size-4"/> Week Bulk</Button></Link>
        <Link :href="route('hrm.swaps.index')"><Button size="sm" variant="outline" class="bg-indigo-600 text-white hover:bg-indigo-700"><ArrowLeftRight class="size-4"/> Roster Swaps</Button></Link>
        <Link :href="route('hrm.duties.excel')"><Button size="sm" variant="outline" class="bg-emerald-600 text-white hover:bg-emerald-700"><CalendarDays class="size-4"/> Excel Sheet</Button></Link>
        <Button variant="outline" size="sm" @click="bulkOpen=!bulkOpen"><CalendarDays class="size-4"/> Bulk Assign</Button>
        <Link :href="route('hrm.duties.create')"><Button size="sm"><Plus class="size-4"/> Single Assign</Button></Link>
      </div>
    </div>
    <div v-if="bulkOpen" class="bg-card border rounded-xl p-4 space-y-3">
      <h3 class="font-semibold text-sm">Bulk Assign</h3>
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
        <div class="space-y-1"><Label>Shift *</Label><SearchableSelect v-model="bulk.shift_id" :options="shiftOptions" placeholder="Select shift"/></div>
        <div class="space-y-1"><Label>From Date *</Label><Input v-model="bulk.from_date" type="date"/></div>
        <div class="space-y-1"><Label>To Date *</Label><Input v-model="bulk.to_date" type="date"/></div>
      </div>
      <div class="space-y-1"><Label>Employees * (select multiple via type)</Label><SearchableSelect v-model="bulk.employee_ids" :options="employeeOptions" endpoint="/hrm/api/options/employees" placeholder="Select employees" multiple/></div>
      <Button size="sm" @click="submitBulk" :disabled="!bulk.shift_id||!bulk.from_date||!bulk.to_date||!bulk.employee_ids.length">Assign Bulk</Button>
    </div>
    <DataTable route-name="hrm.duties.index" title="Roster Duties" :columns="columns" :rows="(duties.data as unknown as Record<string,unknown>[])" :meta="duties.meta">
      <template #cell-employee_id="{row}"><div><p class="font-mono font-semibold text-sm">{{ row.employee_id }}</p><p class="text-xs text-muted-foreground">{{ row.employee }}</p></div></template>
      <template #cell-roster_date="{row}"><span class="text-sm">{{ row.roster_date }}</span></template>
      <template #cell-shift="{row}"><span class="text-sm">{{ row.shift?.shift_name || '—' }} <span v-if="row.shift" class="text-muted-foreground text-xs">({{ row.shift.start_time }}-{{ row.shift.end_time }})</span></span></template>
      <template #cell-status="{row}"><span class="px-2 py-0.5 rounded-full text-xs font-medium" :class="row.status==='Scheduled'?'bg-blue-500/10 text-blue-600': row.status==='Completed'?'bg-emerald-500/10 text-emerald-600': row.status==='Absent'?'bg-red-500/10 text-red-600':'bg-muted text-muted-foreground'">{{ row.status }}</span></template>
      <template #cell-actions="{row}"><div class="flex justify-end gap-1"><Link :href="route('hrm.duties.edit',row.id)" class="size-8 grid place-items-center rounded-md hover:bg-accent"><Pencil class="size-4"/></Link><button @click="askDelete(row)" class="size-8 grid place-items-center rounded-md hover:bg-destructive/10 hover:text-destructive"><Trash2 class="size-4"/></button></div></template>
    </DataTable>
    <ConfirmDialog v-model:open="confirmOpen" :processing="processing" title="Delete duty?" :description="`Delete duty for ${pending?.employee_id} on ${pending?.roster_date}?`" confirm-label="Delete" @confirm="confirmDelete"/>
  </AdminLayout>
</template>
