<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { BookOpen, Plus, Trash2, Eye, Pencil, ShieldCheck, FileText } from 'lucide-vue-next';
import { ref } from 'vue';

import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import ConfirmDialog from '@core/Components/ConfirmDialog.vue';
import DataTable, { type DataTableColumn } from '@core/Components/DataTable.vue';
import StatusBadge from '@core/Components/StatusBadge.vue';
import type { Paginator } from '@core/types';
import { formatCurrency, formatDate } from '@core/Utils/format';

interface Row { id:number; journal_no:string; reference_no?:string; journal_date:string; type:string; narration?:string; total_debit:string; total_credit:string; status:string; lines_count:number; created_at:string }
defineProps<{ journals: Paginator<Row> }>();
const columns: DataTableColumn[] = [
  {key:'journal_no',label:'Journal No',sortable:true,width:'9rem'},
  {key:'journal_date',label:'Date',sortable:true,width:'8rem'},
  {key:'type',label:'Type',align:'center',width:'5rem'},
  {key:'narration',label:'Narration'},
  {key:'total_debit',label:'Amount',align:'right',sortable:true,width:'9rem'},
  {key:'status',label:'Status',align:'center',sortable:true,width:'7rem'},
  {key:'actions',label:'',align:'right',exportable:false,width:'8rem'},
];
const confirmOpen=ref(false), pending=ref<Row|null>(null), processing=ref(false);
function askDelete(r:Row){ pending.value=r; confirmOpen.value=true; }
function confirmDelete(){ if(!pending.value) return; processing.value=true; router.delete(route('accounts.journals.destroy',pending.value.id),{preserveScroll:true,onFinish(){processing.value=false;confirmOpen.value=false;pending.value=null;}}); }
function tone(s:string){ if(s==='posted') return 'success'; if(s==='draft') return 'warning'; if(s==='void') return 'danger'; return 'neutral'; }
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'Accounts'},{title:'Journals'}]">
    <Head title="Journals" />
    <PageHeader
      title="Journals"
      description="Double-entry heart — every journal balances Dr = Cr. Draft → Posted (ledger) → Void (reversal). Types: JV/PV/RV/CV/BV/SV."
      :icon="BookOpen"
      :actions="[{ label:'New Journal', route:'accounts.journals.create', icon: Plus, variant:'default' }]"
    >
      <div class="bg-card border rounded-xl p-3 shadow-sm flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
        <span class="inline-flex items-center gap-1.5"><span class="size-2 rounded-full bg-amber-500"></span> Draft — editable</span>
        <span class="inline-flex items-center gap-1.5"><span class="size-2 rounded-full bg-emerald-500"></span> Posted — in ledger</span>
        <span class="inline-flex items-center gap-1.5"><span class="size-2 rounded-full bg-rose-500"></span> Void — reversal</span>
        <span class="ml-auto hidden sm:inline-flex items-center gap-1.5"><ShieldCheck class="size-3.5" /> Only posted rows affect TB/PL/BS</span>
      </div>

      <DataTable route-name="accounts.journals.index" title="Journals" :columns="columns" :rows="journals.data" :meta="journals.meta" search-placeholder="Search journal no, ref, narration…">
        <template #cell-journal_no="{row}"><span class="font-mono text-xs font-bold px-1.5 py-0.5 rounded border" :class="row.status==='posted'?'bg-emerald-500/10 text-emerald-700 border-emerald-500/20':row.status==='draft'?'bg-amber-500/10 text-amber-600 border-amber-500/20':'bg-rose-500/10 text-rose-700 border-rose-500/20'">{{ row.journal_no }}</span></template>
        <template #cell-journal_date="{row}"><span class="text-sm font-medium">{{ formatDate(row.journal_date) }}</span><span class="text-xs text-muted-foreground block">{{ row.journal_no }}</span></template>
        <template #cell-type="{row}"><span class="text-xs px-2 py-0.5 rounded-full bg-primary text-primary-foreground font-mono font-bold tracking-widest">{{ row.type }}</span></template>
        <template #cell-narration="{row}"><div class="min-w-0"><span class="text-sm line-clamp-1">{{ row.narration || '—' }}</span><span class="text-xs text-muted-foreground flex items-center gap-1"><FileText class="size-3" /> {{ row.lines_count }} lines · {{ row.reference_no || 'no ref' }}</span></div></template>
        <template #cell-total_debit="{row}"><span class="font-mono font-bold">{{ formatCurrency(Number(row.total_debit)) }}</span><span class="text-xs text-muted-foreground block">Dr = Cr</span></template>
        <template #cell-status="{row}"><StatusBadge :label="row.status" :tone="tone(row.status)" /></template>
        <template #cell-actions="{row}"><div class="flex justify-end gap-1"><Link :href="route('accounts.journals.show',row.id)" class="size-8 grid place-items-center rounded-lg hover:bg-accent transition"><Eye class="size-4"/></Link><Link :href="route('accounts.journals.edit',row.id)" class="size-8 grid place-items-center rounded-lg hover:bg-accent transition"><Pencil class="size-4"/></Link><button @click="askDelete(row)" class="size-8 grid place-items-center rounded-lg hover:bg-destructive/10 hover:text-destructive transition"><Trash2 class="size-4"/></button></div></template>
      </DataTable>
      <ConfirmDialog v-model:open="confirmOpen" :processing="processing" title="Delete journal?" :description="`Delete ${pending?.journal_no}? Only draft journals can be deleted — posted ones must be voided.`" confirm-label="Delete" @confirm="confirmDelete" />
    </PageHeader>
  </AdminLayout>
</template>
