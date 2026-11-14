<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2, Eye, Package, AlertTriangle, ScanLine, Power, PowerOff } from 'lucide-vue-next';
import { ref } from 'vue';

import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import ConfirmDialog from '@core/Components/ConfirmDialog.vue';
import DataTable, { type BulkAction, type DataTableColumn } from '@core/Components/DataTable.vue';
import StatusBadge from '@core/Components/StatusBadge.vue';
import { useAcl } from '@core/Composables/useAcl';
import { useConfirm } from '@core/Composables/useConfirm';
import type { Paginator } from '@core/types';
import { formatCurrency } from '@core/Utils/format';

interface Row { id:number; name:string; sku:string; barcode?:string; category?:{name:string}; brand?:{name:string}; type:string; status:string; is_active:boolean; cost_price:number|string; selling_price:number|string; total_stock:number|string; reorder_level:number|string; image?:string; created_at:string }
defineProps<{ products: Paginator<Row>; categoryOptions:any[]; brandOptions:any[] }>();

const columns: DataTableColumn[] = [
  {key:'sku',label:'SKU',sortable:true,width:'7rem'},
  {key:'name',label:'Product',sortable:true},
  {key:'category',label:'Category'},
  {key:'type',label:'Type',align:'center'},
  {key:'cost_price',label:'Cost',align:'right',sortable:true},
  {key:'selling_price',label:'Price',align:'right',sortable:true},
  {key:'total_stock',label:'Stock',align:'center',sortable:true},
  {key:'status',label:'Status',align:'center'},
  {key:'actions',label:'',align:'right',exportable:false,width:'7rem'},
];

// ---- Bulk actions (Phase 2 U7) — offered only when the user holds the matching permission.
const { can } = useAcl();
const { confirm } = useConfirm();

const bulkActions: BulkAction[] = [
  ...(can('inventory.products.update') ? [
    { key: 'activate', label: 'Activate', icon: Power },
    { key: 'deactivate', label: 'Deactivate', icon: PowerOff },
  ] : []),
  ...(can('inventory.products.delete') ? [{ key: 'delete', label: 'Delete', icon: Trash2, danger: true }] : []),
];

const BULK_LABEL: Record<string, string> = { activate: 'Activate', deactivate: 'Deactivate', delete: 'Delete' };

async function onBulk(action: string, ids: Array<string | number>) {
  const ok = await confirm({
    title: `${BULK_LABEL[action] ?? action} ${ids.length} product(s)?`,
    description: action === 'delete'
      ? 'Products with on-hand stock or movement history are skipped and reported — nothing is force-deleted.'
      : 'This applies to every selected product on this page.',
    confirmLabel: BULK_LABEL[action] ?? 'Apply',
    danger: action !== 'activate',
  });
  if (!ok) return;
  router.post(route('inventory.products.bulk'), { action, ids }, { preserveScroll: true });
}

const confirmOpen=ref(false), pending=ref<Row|null>(null), processing=ref(false);
function askDelete(r:Record<string,unknown>){ pending.value=r as unknown as Row; confirmOpen.value=true; }
function confirmDelete(){ if(!pending.value) return; processing.value=true; router.delete(route('inventory.products.destroy',pending.value.id),{preserveScroll:true,onFinish(){processing.value=false;confirmOpen.value=false;pending.value=null;}}); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'Inventory'},{title:'Products'}]">
    <Head title="Products" />
    <PageHeader title="Products — Catalog & pricing" description="Manage stockable products, pricing, suppliers, BOM." :icon="Package" :actions="[{ label:'Add Product', route:'inventory.products.create', icon: Plus }]">
    <DataTable route-name="inventory.products.index" title="Products" :columns="columns" :rows="products.data" :meta="products.meta" search-placeholder="Search SKU, barcode, name…" :selectable="bulkActions.length > 0" :bulk-actions="bulkActions" @bulk="onBulk">
      <template #cell-sku="{row}"><div><span class="font-mono text-sm font-semibold bg-violet-500/10 text-violet-600 px-1.5 py-0.5 rounded">{{ row.sku }}</span><p v-if="row.barcode" class="text-xs text-muted-foreground mt-0.5 flex items-center gap-1"><ScanLine class="size-3"/> {{ row.barcode }}</p></div></template>
      <template #cell-name="{row}"><div class="flex gap-2"><img v-if="row.image" :src="`/storage/${row.image}`" class="size-8 rounded object-cover border"/><div><p class="font-semibold">{{ row.name }}</p><p class="text-xs text-muted-foreground">{{ row.brand?.name || 'No brand' }}</p></div></div></template>
      <template #cell-category="{row}"><span class="text-sm">{{ row.category?.name || '—' }}</span></template>
      <template #cell-type="{row}"><StatusBadge :label="row.type" :tone="row.type==='stockable'?'primary':'neutral'" /></template>
      <template #cell-cost_price="{row}"><span class="text-sm font-mono">{{ formatCurrency(Number(row.cost_price)) }}</span></template>
      <template #cell-selling_price="{row}"><span class="text-sm font-mono font-semibold">{{ formatCurrency(Number(row.selling_price)) }}</span></template>
      <template #cell-total_stock="{row}"><span class="inline-flex items-center gap-1 text-sm font-mono font-semibold" :class="Number(row.total_stock)<=0?'text-rose-600':Number(row.total_stock)<=Number(row.reorder_level)?'text-amber-600':'text-emerald-600'"><AlertTriangle v-if="Number(row.total_stock)<=Number(row.reorder_level)" class="size-3"/>{{ row.total_stock }}</span></template>
      <template #cell-status="{row}"><StatusBadge :label="row.status" :tone="row.is_active?'success':'neutral'" /></template>
      <template #cell-actions="{row}"><div class="flex justify-end gap-1"><Link :href="route('inventory.products.show',row.id)" class="size-8 grid place-items-center rounded-md hover:bg-accent"><Eye class="size-4"/></Link><Link :href="route('inventory.products.edit',row.id)" class="size-8 grid place-items-center rounded-md hover:bg-accent"><Pencil class="size-4"/></Link><button @click="askDelete(row)" class="size-8 grid place-items-center rounded-md hover:bg-destructive/10 hover:text-destructive"><Trash2 class="size-4"/></button></div></template>
    </DataTable>
    <ConfirmDialog v-model:open="confirmOpen" :processing="processing" title="Delete product?" :description="`Delete ${pending?.name} (${pending?.sku})? Only if no stock/history.`" confirm-label="Delete" @confirm="confirmDelete" />
      </PageHeader>
  </AdminLayout>
</template>
