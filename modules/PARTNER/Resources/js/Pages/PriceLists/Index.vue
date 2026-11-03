<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2, Tag, Eye } from 'lucide-vue-next';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import PageHeader from '../../../../../CORE/Resources/js/Components/PageHeader.vue';
import ConfirmDialog from '../../../../../CORE/Resources/js/Components/ConfirmDialog.vue';
import DataTable, { type DataTableColumn } from '../../../../../CORE/Resources/js/Components/DataTable.vue';
import StatusBadge from '../../../../../CORE/Resources/js/Components/StatusBadge.vue';
import type { Paginator } from '../../../../../CORE/Resources/js/types';
interface Row { id:number; name:string; code:string; currency:string; is_default:boolean; is_active:boolean; effective_from?:string; effective_to?:string; items_count:number; partners_count:number; created_at:string }
defineProps<{ priceLists: Paginator<Row> }>();
const columns: DataTableColumn[] = [
  {key:'code',label:'Code',sortable:true,width:'8rem'},
  {key:'name',label:'Price List',sortable:true},
  {key:'currency',label:'Currency',align:'center'},
  {key:'is_default',label:'Default',align:'center'},
  {key:'items_count',label:'Items',align:'center'},
  {key:'partners_count',label:'Partners',align:'center'},
  {key:'is_active',label:'Active',align:'center'},
  {key:'actions',label:'',align:'right',exportable:false,width:'6rem'},
];
const confirmOpen=ref(false), pending=ref<Row|null>(null), processing=ref(false);
function askDelete(r:Record<string,unknown>){ pending.value=r as unknown as Row; confirmOpen.value=true; }
function confirmDelete(){ if(!pending.value) return; processing.value=true; router.delete(route('partner.pricelists.destroy',pending.value.id),{preserveScroll:true,onFinish(){processing.value=false;confirmOpen.value=false;pending.value=null;}}); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'Vendors & Customers'},{title:'Price Lists'}]">
    <Head title="Price Lists" />
    <PageHeader title="Price Lists" description="Customer-specific pricing — linked to partners for sales pricing." :icon="Tag" :actions="[{ label:'Add Price List', route:'partner.pricelists.create', icon: Plus }]">
    <DataTable route-name="partner.pricelists.index" title="Price Lists" :columns="columns" :rows="(priceLists.data as unknown as Record<string,unknown>[])" :meta="priceLists.meta" search-placeholder="Search price lists…">
      <template #cell-code="{row}"><span class="font-mono text-sm font-semibold bg-violet-500/10 text-violet-600 px-1.5 py-0.5 rounded">{{ row.code }}</span></template>
      <template #cell-name="{row}"><div><p class="font-semibold">{{ row.name }}</p><p class="text-xs text-muted-foreground">{{ row.currency }} · {{ row.effective_from || '—' }} → {{ row.effective_to || '—' }}</p></div></template>
      <template #cell-is_default="{row}"><StatusBadge v-if="row.is_default" label="Default" tone="success" /><span v-else class="text-sm text-muted-foreground">—</span></template>
      <template #cell-is_active="{row}"><StatusBadge :label="row.is_active?'Active':'Inactive'" :tone="row.is_active?'success':'neutral'" /></template>
      <template #cell-actions="{row}"><div class="flex justify-end gap-1"><Link :href="route('partner.pricelists.show',row.id)" class="size-8 grid place-items-center rounded-md hover:bg-accent"><Eye class="size-4"/></Link><Link :href="route('partner.pricelists.edit',row.id)" class="size-8 grid place-items-center rounded-md hover:bg-accent"><Pencil class="size-4"/></Link><button @click="askDelete(row)" class="size-8 grid place-items-center rounded-md hover:bg-destructive/10 hover:text-destructive"><Trash2 class="size-4"/></button></div></template>
    </DataTable>
    <ConfirmDialog v-model:open="confirmOpen" :processing="processing" title="Delete price list?" :description="`Delete ${pending?.name} (${pending?.code})?`" confirm-label="Delete" @confirm="confirmDelete" />
      </PageHeader>
  </AdminLayout>
</template>
