<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { CalendarCheck, Clock3 } from 'lucide-vue-next';
import { Trash2 } from 'lucide-vue-next';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import DataTable, { type DataTableColumn } from '@core/Components/DataTable.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { FilterDef, Paginator } from '@core/types';
interface Row { id:number; employee_id:string; employee?:string; department?:string; designation?:string; duty_date:string; primary_punch_in?:string; primary_punch_out?:string; primary_worked_hours:number; total_overtime_hours:number; grand_total_hours:number; status:string; shift?:string; expected_in_time?:string; expected_out_time?:string }
const props=defineProps<{ dailies: Paginator<Row>; filterDefs: FilterDef[]; reconcileFrom:string; reconcileTo:string }>();
const reconcileForm=useForm({from:props.reconcileFrom,to:props.reconcileTo});
function reconcile(){reconcileForm.post(route('hrm.attendance.dailies.reconcile'),{preserveScroll:true});}
const columns: DataTableColumn[] = [
  {key:'employee_id',label:'Employee'},{key:'duty_date',label:'Date',sortable:true},{key:'primary_punch_in',label:'In → Out'},{key:'primary_worked_hours',label:'Primary',align:'center'},{key:'total_overtime_hours',label:'OT',align:'center'},{key:'grand_total_hours',label:'Total',sortable:true,align:'center'},{key:'status',label:'Status',align:'center'},{key:'actions',label:'',align:'right',exportable:false,width:'1rem'}
];
function del(id:number){ router.delete(route('hrm.attendance.dailies.destroy',id),{preserveScroll:true}); }
function fmt(v?:string){ if(!v) return '—'; try{ return new Date(v).toLocaleTimeString('en-GB',{hour:'2-digit',minute:'2-digit'});}catch{return v;} }
function statusClass(s:string){
  if(s==='Present') return 'bg-emerald-500/10 text-emerald-600 border-emerald-200';
  if(s==='Late') return 'bg-red-500/10 text-red-600 border-red-200';
  if(s==='Early Out') return 'bg-amber-500/10 text-amber-600 border-amber-200';
  if(s==='Late & Early Out') return 'bg-orange-500/10 text-orange-700 border-orange-200';
  if(s==='Absent') return 'bg-zinc-800 text-white';
  if(s==='Missing In' || s==='Missing Out') return 'bg-amber-500/15 text-amber-700 border-amber-300';
  if(s==='On Leave') return 'bg-sky-500/10 text-sky-600 border-sky-200';
  if(s==='Holiday') return 'bg-violet-500/10 text-violet-600 border-violet-200';
  if(s==='Off Day') return 'bg-zinc-100 text-zinc-700 border-zinc-300';
  if(s==='Extra Duty') return 'bg-blue-500/10 text-blue-600 border-blue-200';
  return 'bg-muted text-muted-foreground border-transparent';
}
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Attendance' }, { title: 'Daily Attendance' }]">
    <Head title="Daily Attendance"/>
    <PageHeader title="Daily Attendance" description="One record per employee per calendar date: absent, roster Off Day, holiday, leave, extra duty and late/early classifications. No-log night duties remain pending through the following day." :icon="CalendarCheck">
      <div class="bg-card border rounded-xl p-4 shadow-sm">
        <h3 class="text-sm font-semibold flex items-center gap-2 mb-3"><Clock3 class="size-4 text-primary" /> Reconcile — classify missing days</h3>
        <form @submit.prevent="reconcile" class="flex flex-wrap items-end gap-3">
          <div class="space-y-1"><Label class="text-xs">From</Label><Input v-model="reconcileForm.from" type="date" class="h-9" /></div>
          <div class="space-y-1"><Label class="text-xs">To (completed days)</Label><Input v-model="reconcileForm.to" type="date" class="h-9" /></div>
          <Button :disabled="reconcileForm.processing" size="sm" class="rounded-full">{{ reconcileForm.processing?'Working…':'Classify missing days' }}</Button>
          <span class="text-xs text-muted-foreground ml-auto hidden sm:inline">Runs duty resolver for missing statuses</span>
        </form>
      </div>
      <DataTable route-name="hrm.attendance.dailies.index" title="Daily Attendance" :columns="columns" :rows="dailies.data" :meta="dailies.meta" :filter-defs="filterDefs" search-placeholder="Search employee name or ID…">
        <template #cell-employee_id="{row}"><div class="leading-tight"><div class="font-mono text-sm font-semibold">{{ row.employee_id }}</div><div v-if="row.employee" class="text-xs text-foreground">{{ row.employee }}</div><div v-if="row.department || row.designation" class="text-[11px] text-muted-foreground">{{ row.department ?? '' }}<span v-if="row.department && row.designation"> · </span>{{ row.designation ?? '' }}</div></div></template>
        <template #cell-duty_date="{row}"><span class="font-mono text-sm">{{ row.duty_date }}</span><div v-if="row.shift" class="text-[11px] text-muted-foreground">{{ row.shift }}</div></template>
        <template #cell-primary_punch_in="{row}"><span class="text-sm font-mono">{{ fmt(row.primary_punch_in) }} → {{ fmt(row.primary_punch_out) }}</span><div class="text-xs text-muted-foreground" v-if="row.expected_in_time">Exp: {{ row.expected_in_time }}–{{ row.expected_out_time }}</div></template>
        <template #cell-primary_worked_hours="{row}"><span class="font-mono font-semibold">{{ row.primary_worked_hours }}h</span></template>
        <template #cell-total_overtime_hours="{row}"><span class="font-mono" :class="Number(row.total_overtime_hours)>0?'text-amber-600 font-semibold':''">{{ row.total_overtime_hours }}h</span></template>
        <template #cell-grand_total_hours="{row}"><span class="font-mono font-bold">{{ row.grand_total_hours }}h</span></template>
        <template #cell-status="{row}"><span class="px-2.5 py-1 rounded-full text-xs font-medium border" :class="statusClass(String(row.status))">{{ row.status }}</span></template>
        <template #cell-actions="{row}"><button @click="del(row.id)" class="size-8 grid place-items-center rounded-md hover:bg-destructive/10 hover:text-destructive"><Trash2 class="size-4"/></button></template>
      </DataTable>
    </PageHeader>
  </AdminLayout>
</template>
