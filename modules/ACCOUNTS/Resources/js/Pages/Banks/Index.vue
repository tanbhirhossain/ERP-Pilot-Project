<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Landmark, Plus, Trash2, Eye, Pencil, Wallet, Building2 } from 'lucide-vue-next';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import PageHeader from '../../../../../CORE/Resources/js/Components/PageHeader.vue';
import ConfirmDialog from '../../../../../CORE/Resources/js/Components/ConfirmDialog.vue';
import DataTable, { type DataTableColumn } from '../../../../../CORE/Resources/js/Components/DataTable.vue';
import StatusBadge from '../../../../../CORE/Resources/js/Components/StatusBadge.vue';
import type { Paginator } from '../../../../../CORE/Resources/js/types';
import { formatCurrency } from '../../../../../CORE/Resources/js/Utils/format';
interface Row { id:number; name:string; code:string; bank_name:string; account_no:string; account?:{code:string;name:string}; current_balance:string; is_active:boolean }
defineProps<{ banks: Paginator<Row> }>();
const columns: DataTableColumn[] = [
  {key:'code',label:'Code',sortable:true,width:'7rem'},
  {key:'name',label:'Bank',sortable:true},
  {key:'account_no',label:'Account No',width:'10rem'},
  {key:'current_balance',label:'Balance',align:'right',sortable:true,width:'9rem'},
  {key:'is_active',label:'Active',align:'center',width:'6rem'},
  {key:'actions',label:'',align:'right',exportable:false,width:'7rem'},
];
const confirmOpen=ref(false), pending=ref<Row|null>(null), processing=ref(false);
function askDelete(r:Record<string,unknown>){ pending.value=r as unknown as Row; confirmOpen.value=true; }
function confirmDelete(){ if(!pending.value) return; processing.value=true; router.delete(route('accounts.banks.destroy',pending.value.id),{preserveScroll:true,onFinish(){processing.value=false;confirmOpen.value=false;pending.value=null;}}); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'Accounts'},{title:'Banks'}]">
    <Head title="Banks" />
    <PageHeader
      title="Banks"
      description="Each bank maps to a COA leaf (type asset) — drives BV/Bank Reconciliation and cash-flow grouping. Keep one per physical account."
      :icon="Landmark"
      :actions="[{ label:'Add Bank', route:'accounts.banks.create', icon: Plus }, { label:'Reconcile', route:'accounts.banks.reconcile', icon: Wallet, variant:'outline' }]"
    >
      <DataTable route-name="accounts.banks.index" title="Banks" :columns="columns" :rows="(banks.data as unknown as Record<string,unknown>[])" :meta="banks.meta" search-placeholder="Search banks, code, A/C no…">
        <template #cell-code="{row}"><span class="font-mono text-xs font-bold bg-sky-500/10 text-sky-700 border border-sky-500/20 px-1.5 py-1 rounded flex items-center gap-1 w-fit"><Building2 class="size-3" /> {{ row.code }}</span></template>
        <template #cell-name="{row}"><div class="min-w-0"><p class="font-semibold truncate">{{ row.name }}</p><p class="text-xs text-muted-foreground truncate">{{ row.bank_name }} · <span class="font-mono">{{ row.account?.code }}</span> {{ row.account?.name }}</p></div></template>
        <template #cell-account_no="{row}"><span class="font-mono text-sm">{{ row.account_no }}</span></template>
        <template #cell-current_balance="{row}"><span class="font-mono font-bold" :class="Number(row.current_balance)>=0?'text-emerald-600':'text-rose-600'">{{ formatCurrency(Number(row.current_balance)) }}</span></template>
        <template #cell-is_active="{row}"><StatusBadge :label="row.is_active?'Active':'Inactive'" :tone="row.is_active?'success':'neutral'" /></template>
        <template #cell-actions="{row}"><div class="flex justify-end gap-1"><Link :href="route('accounts.banks.show',row.id)" class="size-8 grid place-items-center rounded-lg hover:bg-accent transition"><Eye class="size-4"/></Link><Link :href="route('accounts.banks.edit',row.id)" class="size-8 grid place-items-center rounded-lg hover:bg-accent transition"><Pencil class="size-4"/></Link><button @click="askDelete(row as any)" class="size-8 grid place-items-center rounded-lg hover:bg-destructive/10 hover:text-destructive transition"><Trash2 class="size-4"/></button></div></template>
      </DataTable>
      <ConfirmDialog v-model:open="confirmOpen" :processing="processing" title="Delete bank?" :description="`Delete ${pending?.name}? Ledger link will remain but BV posting will fail until reassigned.`" confirm-label="Delete" @confirm="confirmDelete" />
    </PageHeader>
  </AdminLayout>
</template>
