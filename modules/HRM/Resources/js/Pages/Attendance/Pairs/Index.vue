<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Trash2, Wrench, GitBranch } from 'lucide-vue-next';
import AdminLayout from '../../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import PageHeader from '../../../../../../CORE/Resources/js/Components/PageHeader.vue';
import DataTable, { type DataTableColumn } from '../../../../../../CORE/Resources/js/Components/DataTable.vue';
import type { FilterDef, Paginator } from '../../../../../../CORE/Resources/js/types';
import { formatDateTime } from '../../../../../../CORE/Resources/js/Utils/format';
interface Row { id:number; employee_id:string; employee?:string; department?:string; designation?:string; shift?:string; shift_time?:string; duty_date:string; punch_in:string; punch_out?:string; duty_type:string; shift_type_applied:string; worked_hours:number }
defineProps<{ pairs: Paginator<Row>; filterDefs: FilterDef[] }>();
const columns: DataTableColumn[] = [
  {key:'employee_id',label:'Employee',sortable:true},{key:'duty_date',label:'Duty Date',sortable:true},{key:'punch_in',label:'In → Out',sortable:true},{key:'shift',label:'Shift',align:'left'},{key:'duty_type',label:'Type',align:'center'},{key:'shift_type_applied',label:'Policy',align:'center'},{key:'worked_hours',label:'Hours',sortable:true,align:'center'},{key:'actions',label:'',align:'right',exportable:false,width:'1rem'}
];
function del(id:number){ if(confirm('Delete this pair only? Use Repair this date to rebuild its attendance pair and daily record from logs.')) router.delete(route('hrm.attendance.pairs.destroy',id),{preserveScroll:true}); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Attendance' }, { title: 'Attendance Pairs' }]">
    <Head title="Attendance Pairs"/>
    <PageHeader title="Attendance Pairs" description="Paired IN→OUT — shift auto-detected by nearest 8h window (grace + fallback). Filters: Dept / Designation / Employee / Type / Date range. Search by name." :icon="GitBranch" :actions="[{ label: 'Repair pairs', route: 'hrm.attendance.fix.index', icon: Wrench }]">
      <DataTable route-name="hrm.attendance.pairs.index" title="Attendance Pairs" :columns="columns" :rows="(pairs.data as unknown as Record<string,unknown>[])" :meta="pairs.meta" :filter-defs="filterDefs" search-placeholder="Search employee name or ID…">
        <template #cell-employee_id="{row}"><div class="leading-tight"><div class="font-mono text-sm font-semibold">{{ row.employee_id }}</div><div v-if="row.employee" class="text-xs">{{ row.employee }}</div><div v-if="row.department" class="text-[11px] text-muted-foreground">{{ row.department }}<span v-if="row.designation"> · {{ row.designation }}</span></div></div></template>
        <template #cell-duty_date="{row}"><span class="text-sm font-mono">{{ row.duty_date }}</span></template>
        <template #cell-punch_in="{row}"><span class="text-sm font-mono">{{ formatDateTime(row.punch_in) }} <span class="text-muted-foreground">→</span> {{ row.punch_out?formatDateTime(row.punch_out):'OPEN' }}</span></template>
        <template #cell-shift="{row}"><div class="leading-tight"><span class="text-sm font-medium">{{ row.shift || '—' }}</span><div v-if="row.shift_time" class="text-xs font-mono text-muted-foreground">{{ row.shift_time }}</div></div></template>
        <template #cell-duty_type="{row}"><span class="px-2 py-0.5 rounded-full text-xs font-medium" :class="row.duty_type==='Primary'?'bg-blue-500/10 text-blue-600':'bg-amber-500/10 text-amber-600'">{{ row.duty_type }}</span></template>
        <template #cell-shift_type_applied="{row}"><span class="text-xs px-2 py-0.5 rounded bg-muted">{{ row.shift_type_applied }}</span></template>
        <template #cell-worked_hours="{row}"><span class="font-mono font-semibold">{{ row.worked_hours }}h</span></template>
        <template #cell-actions="{row}"><div class="flex justify-end gap-1"><Link :href="route('hrm.attendance.fix.index',{employee_id:row.employee_id,from:row.duty_date,to:row.duty_date})" class="inline-flex size-8 items-center justify-center rounded-md hover:bg-primary/10 hover:text-primary" title="Repair this employee/date from biometric logs"><Wrench class="size-4"/></Link><button @click="del(row.id)" class="size-8 grid place-items-center rounded-md hover:bg-destructive/10 hover:text-destructive" title="Delete pair only"><Trash2 class="size-4"/></button></div></template>
      </DataTable>
    </PageHeader>
  </AdminLayout>
</template>
