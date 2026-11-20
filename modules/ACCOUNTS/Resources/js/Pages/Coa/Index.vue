<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ListTree, Plus, Pencil, Trash2, Eye, Shield, Layers } from 'lucide-vue-next';
import { ref } from 'vue';

import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import ConfirmDialog from '@core/Components/ConfirmDialog.vue';
import DataTable, { type DataTableColumn } from '@core/Components/DataTable.vue';
import StatusBadge from '@core/Components/StatusBadge.vue';
import type { Paginator } from '@core/types';
interface Row { id:number; code:string; name:string; account_type:string; nature:string; parent?:{code:string;name:string}; level:number; is_group:boolean; is_system:boolean; is_active:boolean; children_count:number; lines_count:number }
defineProps<{ accounts: Paginator<Row>; tree:any[]; parentOptions:any[] }>();
const columns: DataTableColumn[] = [
  {key:'code',label:'Code',sortable:true,width:'7.5rem'},
  {key:'name',label:'Account',sortable:true},
  {key:'account_type',label:'Type',width:'9rem'},
  {key:'nature',label:'Nature',width:'6.5rem'},
  {key:'is_group',label:'Kind',width:'6.5rem'},
  {key:'is_active',label:'Active',width:'6.5rem'},
  {key:'actions',label:'',align:'right',exportable:false,width:'7rem'},
];
const confirmOpen=ref(false), pending=ref<Row|null>(null), processing=ref(false);
function askDelete(r:Row){ pending.value=r; confirmOpen.value=true; }
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
      <div v-if="tree.length" class="bg-card border rounded-xl shadow-sm overflow-hidden">
        <div class="px-4 py-3 border-b bg-muted/30 flex items-center justify-between">
          <h3 class="text-sm font-semibold flex items-center gap-2"><Layers class="size-4 text-primary" /> Account tree</h3>
          <span class="text-xs text-muted-foreground">{{ tree.length }} roots</span>
        </div>
        <!--
          A tree is a list, not a cloud.

          This was a three-column grid of pills. Account codes are fixed-width and people scan them
          down a column; wrapping them into a ragged cloud destroys that column, and `{{ ch.code }}
          {{ ch.name }}` interpolated into one inline link glued the two together ("1000Cash in
          Hand"). A real table gives the code a column, the name a column, and the eye a line to
          follow — which is the whole reason a chart of accounts is printed as a list.
        -->
        <table class="w-full text-sm">
          <tbody class="divide-border/70 divide-y">
            <template v-for="root in tree" :key="root.id">
              <tr class="bg-muted/40">
                <td class="w-[7.5rem] px-4 py-2.5">
                  <span class="font-mono text-xs font-semibold">{{ root.code }}</span>
                </td>
                <td class="px-4 py-2.5">
                  <span class="flex items-center gap-2 font-semibold">
                    <span class="bg-primary size-1.5 rounded-full" />
                    {{ root.name }}
                  </span>
                </td>
                <td class="text-muted-foreground w-[6rem] px-4 py-2.5 text-right text-xs">
                  {{ root.children?.length ?? 0 }} sub-accounts
                </td>
              </tr>
              <tr v-for="ch in root.children" :key="ch.id" class="hover:bg-accent/50 transition-colors">
                <td class="px-4 py-2">
                  <span class="text-muted-foreground pl-4 font-mono text-xs">{{ ch.code }}</span>
                </td>
                <td class="px-4 py-2">
                  <Link
                    :href="route('accounts.coa.show', ch.id)"
                    class="text-foreground decoration-muted-foreground/40 hover:decoration-foreground underline-offset-2 hover:underline"
                  >
                    {{ ch.name }}
                  </Link>
                </td>
                <!-- The name is the link; a second "open" label in the same row is a competing action. -->
                <td class="px-4 py-2" />
              </tr>
              <tr v-if="!root.children?.length">
                <td colspan="3" class="text-muted-foreground px-4 py-2 text-xs">No child accounts — leaf</td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>

      <DataTable route-name="accounts.coa.index" title="Chart of Accounts" :columns="columns" :rows="accounts.data" :meta="accounts.meta" search-placeholder="Search code, name, type…">
        <template #cell-code="{row}"><span class="text-foreground/80 inline-flex items-center gap-1 font-mono text-xs font-semibold">{{ row.code }} <Shield v-if="row.is_system" class="text-muted-foreground size-3" :title="'System account'" /></span></template>
        <template #cell-name="{row}"><div class="min-w-0"><p class="flex items-center gap-1.5 truncate font-medium">{{ row.name }}<span v-if="row.is_group" class="text-muted-foreground ml-1 text-[11px] font-normal">group</span></p><p class="text-xs text-muted-foreground truncate">{{ row.parent ? `↳ ${row.parent.code} ${row.parent.name}` : 'Root' }} · L{{ row.level }} · {{ row.children_count }} children · {{ row.lines_count }} postings</p></div></template>
        <template #cell-account_type="{row}"><StatusBadge :label="row.account_type" :tone="typeTone(row.account_type)" /></template>
        <template #cell-nature="{row}"><StatusBadge :label="row.nature === 'debit' ? 'Debit' : 'Credit'" :tone="row.nature === 'debit' ? 'info' : 'primary'" /></template>
        <template #cell-is_group="{row}"><StatusBadge :label="row.is_group?'Group':'Posting'" :tone="row.is_group?'warning':'success'" /></template>
        <template #cell-is_active="{row}"><StatusBadge :label="row.is_active?'Active':'Inactive'" :tone="row.is_active?'success':'neutral'" /></template>
        <template #cell-actions="{row}"><div class="flex justify-end gap-1"><Link :href="route('accounts.coa.show',row.id)" class="size-8 grid place-items-center rounded-lg hover:bg-accent transition"><Eye class="size-4"/></Link><Link :href="route('accounts.coa.edit',row.id)" class="size-8 grid place-items-center rounded-lg hover:bg-accent transition"><Pencil class="size-4"/></Link><button @click="askDelete(row)" class="size-8 grid place-items-center rounded-lg hover:bg-destructive/10 hover:text-destructive transition"><Trash2 class="size-4"/></button></div></template>
      </DataTable>
      <ConfirmDialog v-model:open="confirmOpen" :processing="processing" title="Delete account?" :description="`Delete ${pending?.code} — ${pending?.name}? System & posted accounts are protected.`" confirm-label="Delete" @confirm="confirmDelete" />
    </PageHeader>
  </AdminLayout>
</template>
