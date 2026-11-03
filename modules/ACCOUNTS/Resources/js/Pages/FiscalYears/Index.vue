<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { CalendarRange, Plus, Eye, Lock, CheckCircle2, Archive } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import PageHeader from '../../../../../CORE/Resources/js/Components/PageHeader.vue';
import DataTable, { type DataTableColumn } from '../../../../../CORE/Resources/js/Components/DataTable.vue';
import StatusBadge from '../../../../../CORE/Resources/js/Components/StatusBadge.vue';
import type { Paginator } from '../../../../../CORE/Resources/js/types';
import { formatDate } from '../../../../../CORE/Resources/js/Utils/format';
interface Row { id:number; name:string; code:string; start_date:string; end_date:string; status:string; periods_count:number; created_at:string }
defineProps<{ fiscalYears: Paginator<Row> }>();
const columns: DataTableColumn[] = [
  {key:'name',label:'Fiscal Year',sortable:true},
  {key:'code',label:'Code',width:'6rem'},
  {key:'start_date',label:'From',sortable:true,width:'8rem'},
  {key:'end_date',label:'To',width:'8rem'},
  {key:'status',label:'Status',align:'center',sortable:true,width:'7rem'},
  {key:'actions',label:'',align:'right',exportable:false,width:'6rem'},
];
function tone(s:string){ if(s==='open') return 'success'; if(s==='closed') return 'danger'; if(s==='locked') return 'neutral'; return 'warning'; }
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'Accounts'},{title:'Fiscal Years'}]">
    <Head title="Fiscal Years" />
    <PageHeader
      title="Fiscal Years"
      description="Bangladesh July–June FY. Closing locks all periods and blocks posting; reopen restores draft."
      :icon="CalendarRange"
      :actions="[{ label:'New Fiscal Year', route:'accounts.fiscal-years.create', icon: Plus }]"
    >
      <div class="bg-card border rounded-2xl p-3 shadow-sm flex flex-wrap items-center gap-2 text-xs">
        <span class="inline-flex items-center gap-1.5"><span class="size-2 rounded-full bg-emerald-500"></span> Open — posting allowed</span>
        <span class="inline-flex items-center gap-1.5"><span class="size-2 rounded-full bg-rose-500"></span> Closed — locked</span>
        <span class="inline-flex items-center gap-1.5"><span class="size-2 rounded-full bg-slate-400"></span> Locked — archived</span>
        <span class="ml-auto hidden sm:inline text-muted-foreground">Periods auto-created per FY (12 months)</span>
      </div>
      <DataTable route-name="accounts.fiscal-years.index" title="Fiscal Years" :columns="columns" :rows="(fiscalYears.data as unknown as Record<string,unknown>[])" :meta="fiscalYears.meta" search-placeholder="Search FY…">
        <template #cell-name="{row}"><div><p class="font-bold flex items-center gap-1.5">{{ row.name }} <CheckCircle2 v-if="row.status==='open'" class="size-3.5 text-emerald-600" /> <Lock v-if="row.status==='closed'" class="size-3.5 text-rose-500" /> <Archive v-if="row.status==='locked'" class="size-3.5 text-slate-400" /></p><p class="text-xs text-muted-foreground">{{ row.periods_count }} periods · FY range</p></div></template>
        <template #cell-code="{row}"><span class="font-mono text-xs font-bold bg-amber-500/10 text-amber-700 border border-amber-500/20 px-1.5 py-1 rounded">{{ row.code }}</span></template>
        <template #cell-start_date="{row}"><span class="text-sm font-medium">{{ formatDate(row.start_date) }}</span></template>
        <template #cell-end_date="{row}"><span class="text-sm font-medium">{{ formatDate(row.end_date) }}</span></template>
        <template #cell-status="{row}"><StatusBadge :label="row.status" :tone="tone(row.status)" /></template>
        <template #cell-actions="{row}"><Link :href="route('accounts.fiscal-years.show',row.id)" class="size-8 grid place-items-center rounded-lg hover:bg-accent transition ml-auto"><Eye class="size-4"/></Link></template>
      </DataTable>
    </PageHeader>
  </AdminLayout>
</template>
