<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2, Eye, Users} from 'lucide-vue-next';
import { ref } from 'vue';

import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import PageHeader from '../../../../../CORE/Resources/js/Components/PageHeader.vue';
import ConfirmDialog from '../../../../../CORE/Resources/js/Components/ConfirmDialog.vue';
import DataTable, { type DataTableColumn } from '../../../../../CORE/Resources/js/Components/DataTable.vue';
import StatusBadge from '../../../../../CORE/Resources/js/Components/StatusBadge.vue';
import type { Paginator } from '../../../../../CORE/Resources/js/types';
import { formatCurrency } from '../../../../../CORE/Resources/js/Utils/format';

interface Row { id:number; partner_code:string; name:string; legal_name?:string; type:string; group?:{name:string}; phone?:string; credit_limit:string|number; current_balance:string|number; is_active:boolean; is_verified:boolean; verification_status:string; created_at:string }
defineProps<{ partners: Paginator<Row>; groupOptions:any[]; type?:string }>();

const columns: DataTableColumn[] = [
  {key:'partner_code',label:'Code',sortable:true,width:'8rem'},
  {key:'name',label:'Customer',sortable:true},
  {key:'group',label:'Group'},
  {key:'phone',label:'Phone'},
  {key:'credit_limit',label:'Credit Limit',align:'right',sortable:true},
  {key:'current_balance',label:'Balance',align:'right',sortable:true},
  {key:'verification_status',label:'Status',align:'center'},
  {key:'is_active',label:'Active',align:'center',width:'5rem'},
  {key:'actions',label:'',align:'right',exportable:false,width:'7rem'},
];

const confirmOpen=ref(false), pending=ref<Row|null>(null), processing=ref(false);
function askDelete(r:Record<string,unknown>){ pending.value=r as unknown as Row; confirmOpen.value=true; }
function confirmDelete(){ if(!pending.value) return; processing.value=true; router.delete(route('partner.customers.destroy',pending.value.id),{preserveScroll:true,onFinish(){processing.value=false;confirmOpen.value=false;pending.value=null;}}); }
function statusTone(s:string){ if(s==='approved') return 'success' as const; if(s==='rejected') return 'danger' as const; return 'warning' as const; }
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Ven & Cust' }, { title: 'Customers' }]">
    <Head title="Customers" />
    <PageHeader title="Customers — Customer directory" description="Manage customers, credit limits, price lists." :icon="Users" :actions="[{ label:'Groups', route:'partner.customers.create', icon: Plus }]">
    <DataTable route-name="partner.customers.index" title="Customers" :columns="columns" :rows="(partners.data as unknown as Record<string,unknown>[])" :meta="partners.meta" search-placeholder="Search by code, name, phone, TIN…">
      <template #cell-partner_code="{row}"><span class="font-mono text-sm font-semibold bg-emerald-500/10 text-emerald-600 px-1.5 py-0.5 rounded">{{ row.partner_code }}</span></template>
      <template #cell-name="{row}"><div><p class="font-semibold">{{ row.name }}</p><p class="text-xs text-muted-foreground">{{ row.legal_name || row.type }}</p></div></template>
      <template #cell-group="{row}"><span class="text-sm">{{ row.group?.name || '—' }}</span></template>
      <template #cell-phone="{row}"><span class="text-sm font-mono">{{ row.phone || '—' }}</span></template>
      <template #cell-credit_limit="{row}"><span class="text-sm font-mono">{{ formatCurrency(Number(row.credit_limit)) }}</span></template>
      <template #cell-current_balance="{row}"><span class="text-sm font-mono font-semibold" :class="Number(row.current_balance) > Number(row.credit_limit) && Number(row.credit_limit)>0 ? 'text-rose-600' : ''">{{ formatCurrency(Number(row.current_balance)) }}</span></template>
      <template #cell-verification_status="{row}"><StatusBadge :label="row.verification_status" :tone="statusTone(row.verification_status)" dot /></template>
      <template #cell-is_active="{row}"><StatusBadge :label="row.is_active ? 'Active' : 'Inactive'" :tone="row.is_active ? 'success' : 'neutral'" /></template>
      <template #cell-actions="{row}">
        <div class="flex justify-end gap-1">
          <Link :href="route('partner.customers.show',row.id)" class="size-8 grid place-items-center rounded-md hover:bg-accent" title="View"><Eye class="size-4"/></Link>
          <Link :href="route('partner.customers.edit',row.id)" class="size-8 grid place-items-center rounded-md hover:bg-accent"><Pencil class="size-4"/></Link>
          <button @click="askDelete(row)" class="size-8 grid place-items-center rounded-md hover:bg-destructive/10 hover:text-destructive"><Trash2 class="size-4"/></button>
        </div>
      </template>
    </DataTable>
    <ConfirmDialog v-model:open="confirmOpen" :processing="processing" title="Delete customer?" :description="`Delete ${pending?.name} (${pending?.partner_code})?`" confirm-label="Delete" @confirm="confirmDelete" />
      </PageHeader>
  </AdminLayout>
</template>
