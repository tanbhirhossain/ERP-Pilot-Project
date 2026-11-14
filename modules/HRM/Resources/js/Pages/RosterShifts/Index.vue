<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2 } from 'lucide-vue-next';
import { ref } from 'vue';

import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import ConfirmDialog from '@core/Components/ConfirmDialog.vue';
import DataTable, { type DataTableColumn } from '@core/Components/DataTable.vue';
import type { Paginator } from '@core/types';
interface Row { id:number; shift_name:string; start_time?:string; end_time?:string; punch_in_window_start?:string; punch_in_window_end?:string; crosses_midnight:boolean; max_duration_hours:number; created_at:string }
defineProps<{ shifts: Paginator<Row> }>();
const columns: DataTableColumn[] = [
  {key:'shift_name',label:'Shift',sortable:true},
  {key:'start_time',label:'Schedule',sortable:true},
  {key:'punch_in_window_start',label:'Flex Window'},
  {key:'crosses_midnight',label:'Night',align:'center'},
  {key:'max_duration_hours',label:'Max Hrs',align:'center'},
  {key:'actions',label:'',align:'right',exportable:false,width:'1rem'},
];
const confirmOpen=ref(false), pending=ref<Row|null>(null), processing=ref(false);
function askDelete(r:Record<string,unknown>){ pending.value=r as unknown as Row; confirmOpen.value=true; }
function confirmDelete(){ if(!pending.value) return; processing.value=true; router.delete(route('hrm.shifts.destroy',pending.value.id),{preserveScroll:true,onFinish(){processing.value=false;confirmOpen.value=false;pending.value=null;}}); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Roster' }, { title: 'Roster Shifts' }]">
    <Head title="Roster Shifts"/>
    <PageHeader title="Roster Shifts" description="Define Fixed / Flexible / Custom shifts — midnight crossing supported." :icon="Pencil" :actions="[{ label:'New Shift', route:'hrm.shifts.create', icon: Plus }]">
    <DataTable route-name="hrm.shifts.index" title="Roster Shifts" :columns="columns" :rows="shifts.data" :meta="shifts.meta">
      <template #cell-shift_name="{row}"><span class="font-semibold">{{ row.shift_name }}</span></template>
      <template #cell-start_time="{row}"><span class="text-sm font-mono bg-muted px-1.5 py-0.5 rounded">{{ row.start_time || '—' }} → {{ row.end_time || '—' }}</span></template>
      <template #cell-punch_in_window_start="{row}"><span class="text-sm font-mono">{{ row.punch_in_window_start && row.punch_in_window_end ? `${row.punch_in_window_start} - ${row.punch_in_window_end}` : '—' }}</span></template>
      <template #cell-crosses_midnight="{row}"><span class="text-xs px-2 py-0.5 rounded-full" :class="row.crosses_midnight?'bg-amber-500/10 text-amber-600':'bg-muted text-muted-foreground'">{{ row.crosses_midnight?'Yes':'No' }}</span></template>
      <template #cell-max_duration_hours="{row}"><span class="text-sm">{{ row.max_duration_hours }}h</span></template>
      <template #cell-actions="{row}"><div class="flex justify-end gap-1"><Link :href="route('hrm.shifts.edit',row.id)" class="size-8 grid place-items-center rounded-md hover:bg-accent"><Pencil class="size-4"/></Link><button @click="askDelete(row)" class="size-8 grid place-items-center rounded-md hover:bg-destructive/10 hover:text-destructive"><Trash2 class="size-4"/></button></div></template>
    </DataTable>
    <ConfirmDialog v-model:open="confirmOpen" :processing="processing" title="Delete shift?" :description="`Delete ${pending?.shift_name}?`" confirm-label="Delete" @confirm="confirmDelete"/>
      </PageHeader>
  </AdminLayout>
</template>
