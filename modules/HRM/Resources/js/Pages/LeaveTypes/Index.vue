<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2, Tag, ShieldCheck } from 'lucide-vue-next';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import PageHeader from '../../../../../CORE/Resources/js/Components/PageHeader.vue';
import ConfirmDialog from '../../../../../CORE/Resources/js/Components/ConfirmDialog.vue';
import DataTable, { type DataTableColumn } from '../../../../../CORE/Resources/js/Components/DataTable.vue';
import type { Paginator } from '../../../../../CORE/Resources/js/types';
interface Row { id:number; name:string; code:string|null; days_per_year:number; is_paid:boolean; color:string; is_active:boolean; description:string|null; created_at:string }
const props=defineProps<{ leaveTypes: Paginator<Row> }>();
const columns: DataTableColumn[]=[
  {key:'name',label:'Leave Type',sortable:true},
  {key:'code',label:'Code',sortable:true},
  {key:'days_per_year',label:'Quota',align:'center',sortable:true},
  {key:'is_paid',label:'Paid',align:'center'},
  {key:'is_active',label:'Active',align:'center'},
  {key:'actions',label:'',align:'right',exportable:false,width:'6rem'},
];
const confirmOpen=ref(false), pending=ref<Row|null>(null);
function askDelete(r:Row){ pending.value=r; confirmOpen.value=true; }
function confirmDelete(){ if(!pending.value) return; router.delete(route('hrm.leave-types.destroy',pending.value.id),{preserveScroll:true,onFinish(){confirmOpen.value=false;pending.value=null;}}); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'HRM'},{title:'Leave Types'}]">
    <Head title="Leave Types"/>
    <PageHeader title="Leave Types" description="Define quotas — Casual, Sick, Earned etc. Color codes the requests." :icon="Tag" :actions="[{ label:'New Type', route:'hrm.leave-types.create', icon: Plus }]">
    <DataTable route-name="hrm.leave-types.index" title="Leave Types" :columns="columns" :rows="(leaveTypes.data as unknown as Record<string,unknown>[])" :meta="leaveTypes.meta">
      <template #cell-name="{row}"><div class="flex items-center gap-2"><span class="size-3 rounded-full" :style="{backgroundColor: (row as any).color}"></span><span class="font-semibold text-sm">{{ row.name }}</span></div><p class="text-xs text-muted-foreground truncate max-w-[240px]">{{ (row as any).description || '' }}</p></template>
      <template #cell-code="{row}"><span class="font-mono text-sm px-2 py-0.5 rounded bg-muted">{{ row.code || '—' }}</span></template>
      <template #cell-days_per_year="{row}"><span class="font-mono font-semibold">{{ row.days_per_year }}d</span></template>
      <template #cell-is_paid="{row}"><span class="px-2 py-0.5 rounded-full text-xs font-medium" :class="(row as any).is_paid ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'">{{ (row as any).is_paid ? 'Paid' : 'Unpaid' }}</span></template>
      <template #cell-is_active="{row}"><span class="px-2 py-0.5 rounded-full text-xs font-medium" :class="(row as any).is_active ? 'bg-blue-100 text-blue-700' : 'bg-muted text-muted-foreground'">{{ (row as any).is_active ? 'Active' : 'Inactive' }}</span></template>
      <template #cell-actions="{row}"><div class="flex justify-end gap-1"><Link :href="route('hrm.leave-types.edit',(row as any).id)" class="size-8 grid place-items-center rounded-md hover:bg-accent"><Pencil class="size-4"/></Link><button @click="askDelete(row as unknown as Row)" class="size-8 grid place-items-center rounded-md hover:bg-destructive/10 hover:text-destructive"><Trash2 class="size-4"/></button></div></template>
    </DataTable>
    <ConfirmDialog v-model:open="confirmOpen" title="Delete leave type?" :description="`Delete ${pending?.name}?`" confirm-label="Delete" @confirm="confirmDelete"/>
      </PageHeader>
  </AdminLayout>
</template>
