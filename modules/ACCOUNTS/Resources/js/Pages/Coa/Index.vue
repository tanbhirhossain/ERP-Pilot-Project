<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ListTree, Plus, Pencil, Trash2, Eye, Shield, Layers } from 'lucide-vue-next';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import PageHeader from '../../../../../CORE/Resources/js/Components/PageHeader.vue';
import ConfirmDialog from '../../../../../CORE/Resources/js/Components/ConfirmDialog.vue';
import DataTable, { type DataTableColumn } from '../../../../../CORE/Resources/js/Components/DataTable.vue';
import StatusBadge from '../../../../../CORE/Resources/js/Components/StatusBadge.vue';
import type { Paginator } from '../../../../../CORE/Resources/js/types';
interface Row { id:number; code:string; name:string; account_type:string; nature:string; parent?:{code:string;name:string}; level:number; is_group:boolean; is_system:boolean; is_active:boolean; children_count:number; lines_count:number }
defineProps<{ accounts: Paginator<Row>; tree:any[]; parentOptions:any[] }>();
const columns: DataTableColumn[] = [
  {key:'code',label:'Code',sortable:true,width:'7.5rem'},
  {key:'name',label:'Account',sortable:true},
  {key:'account_type',label:'Type',align:'center',sortable:true,width:'8rem'},
  {key:'nature',label:'Nature',align:'center',width:'6rem'},
  {key:'is_group',label:'Group',align:'center',width:'7rem'},
  {key:'is_active',label:'Active',align:'center',width:'7rem'},
  {key:'actions',label:'',align:'right',exportable:false,width:'8rem'},
];
const confirmOpen=ref(false), pending=ref<Row|null>(null), processing=ref(false);
const localSearch=ref('');
function askDelete(r:Record<string,unknown>){ pending.value=r as unknown as Row; confirmOpen.value=true; }
function confirmDelete(){ if(!pending.value) return; processing.value=true; router.delete(route('accounts.coa.destroy',pending.value.id),{preserveScroll:true,onFinish(){processing.value=false;confirmOpen.value=false;pending.value=null;}}); }
function typeTone(t:string){ if(t==='asset') return 'info'; if(t==='liability') return 'warning'; if(t==='equity') return 'primary'; if(t==='income') return 'success'; return 'danger'; }
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'Accounts'},{title:'Chart of Accounts'}]">
    <Head title="Chart of Accounts" />
    <PageHeader
      title="Chart of Accounts"
      description="Hierarchical COA — groups are containers, only leaf accounts accept postings. 1xxx Assets · 2xxx Liabilities · 3xxx Equity · 4xxx Income · 5xxx Expense."
      :icon="ListTree"
      :actions="[{ label:'Add Account', route:'accounts.coa.create', icon: Plus }]"
    >
      <!-- Tree quick nav — premium -->
      <div v-if="tree.length" class="bg-card border rounded-2xl shadow-sm overflow-hidden">
        <div class="px-4 py-3 border-b bg-muted/30 flex items-center justify-between">
          <h3 class="text-sm font-semibold flex items-center gap-2"><Layers class="size-4 text-primary" /> Account tree</h3>
          <span class="text-xs text-muted-foreground">{{ tree.length }} roots</span>
        </div>
        <div class="p-3 grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
          <div v-for="root in tree" :key="root.id" class="rounded-xl border bg-card p-3 shadow-sm">
            <p class="font-mono text-xs font-bold flex items-center gap-1.5"><span class="size-2 rounded-full bg-primary"></span>{{ root.code }} — {{ root.name }}</p>
            <div class="flex flex-wrap gap-1.5 mt-2">
              <Link v-for="ch in root.children" :key="ch.id" :href="route('accounts.coa.show', ch.id)" class="text-xs px-2 py-1 rounded-full border bg-muted/40 hover:bg-accent transition font-medium">{{ ch.code }} {{ ch.name }}</Link>
              <span v-if="!root.children?.length" class="text-xs text-muted-foreground">No children — leaf</span>
            </div>
          </div>
        </div>
      </div>

      <DataTable route-name="accounts.coa.index" title="Chart of Accounts" :columns="columns" :rows="(accounts.data as unknown as Record<string,unknown>[])" :meta="accounts.meta" search-placeholder="Search code, name, type…">
        <template #cell-code="{row}"><span class="font-mono text-xs font-bold px-1.5 py-1 rounded border flex items-center gap-1" :class="row.is_system?'bg-amber-500/10 text-amber-700 border-amber-500/20':'bg-slate-500/10 text-slate-700 border-slate-500/20'">{{ row.code }} <Shield v-if="row.is_system" class="inline size-3" /></span></template>
        <template #cell-name="{row}"><div class="min-w-0"><p class="font-semibold flex items-center gap-1.5 truncate">{{ row.name }} <span v-if="row.is_group" class="text-[10px] px-1.5 py-0.5 rounded-full bg-violet-500/10 text-violet-600 border border-violet-500/20 font-bold tracking-widest">GROUP</span></p><p class="text-xs text-muted-foreground truncate">{{ row.parent ? `↳ ${row.parent.code} ${row.parent.name}` : 'Root' }} · L{{ row.level }} · {{ row.children_count }} children · {{ row.lines_count }} postings</p></div></template>
        <template #cell-account_type="{row}"><StatusBadge :label="row.account_type" :tone="typeTone(row.account_type)" /></template>
        <template #cell-nature="{row}"><span class="text-xs font-mono px-2 py-1 rounded-full border font-bold" :class="row.nature==='debit'?'bg-emerald-500/10 text-emerald-700 border-emerald-500/20':'bg-rose-500/10 text-rose-700 border-rose-500/20'">{{ row.nature.toUpperCase() }}</span></template>
        <template #cell-is_group="{row}"><StatusBadge :label="row.is_group?'Group':'Posting'" :tone="row.is_group?'warning':'success'" /></template>
        <template #cell-is_active="{row}"><StatusBadge :label="row.is_active?'Active':'Inactive'" :tone="row.is_active?'success':'neutral'" /></template>
        <template #cell-actions="{row}"><div class="flex justify-end gap-1"><Link :href="route('accounts.coa.show',row.id)" class="size-8 grid place-items-center rounded-lg hover:bg-accent transition"><Eye class="size-4"/></Link><Link :href="route('accounts.coa.edit',row.id)" class="size-8 grid place-items-center rounded-lg hover:bg-accent transition"><Pencil class="size-4"/></Link><button @click="askDelete(row as any)" class="size-8 grid place-items-center rounded-lg hover:bg-destructive/10 hover:text-destructive transition"><Trash2 class="size-4"/></button></div></template>
      </DataTable>
      <ConfirmDialog v-model:open="confirmOpen" :processing="processing" title="Delete account?" :description="`Delete ${pending?.code} — ${pending?.name}? System & posted accounts are protected.`" confirm-label="Delete" @confirm="confirmDelete" />
    </PageHeader>
  </AdminLayout>
</template>
