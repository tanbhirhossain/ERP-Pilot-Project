<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Undo2, Plus, Eye } from 'lucide-vue-next';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import PageHeader from '../../../../../CORE/Resources/js/Components/PageHeader.vue';
import DataTable, { type DataTableColumn } from '../../../../../CORE/Resources/js/Components/DataTable.vue';
import StatusBadge from '../../../../../CORE/Resources/js/Components/StatusBadge.vue';
import type { Paginator } from '../../../../../CORE/Resources/js/types';
import { formatCurrency } from '../../../../../CORE/Resources/js/Utils/format';
const props = defineProps<{ returns: any }>();
const columns: DataTableColumn[] = [
  {key:'return_no',label:'Return No',sortable:true},
  {key:'vendor',label:'Vendor'},
  {key:'total_amount',label:'Total',align:'right'},
  {key:'status',label:'Status',align:'center',sortable:true},
  {key:'actions',label:'',align:'right',exportable:false,width:'6rem'},
];
function tone(s:string){ if(s==='posted') return 'success'; if(s==='draft') return 'warning'; if(s==='cancelled') return 'danger'; return 'neutral'; }
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'Purchase'},{title:'Returns'}]">
    <Head title="Purchase Returns" />
    <PageHeader title="Purchase Returns (Debit Notes)" description="Return to vendor — stock OUT (deduct) + AP debit note (AP Dr / Inv Cr / VAT Cr)." :icon="Undo2" :actions="[{ label:'New Return', route:'purchase.returns.create', icon: Plus }]">
    <DataTable route-name="purchase.returns.index" title="Returns" :columns="columns" :rows="(returns.data as unknown as Record<string,unknown>[])" :meta="returns.meta" search-placeholder="Search PR-...">
      <template #cell-return_no="{row}"><span class="font-mono text-xs font-bold px-1.5 py-0.5 rounded bg-amber-500/10 text-amber-700">{{ row.return_no }}</span></template>
      <template #cell-vendor="{row}"><span class="text-sm">{{ row.vendor?.name || '—' }}</span></template>
      <template #cell-total_amount="{row}"><span class="font-mono font-bold">{{ formatCurrency(Number(row.total_amount)) }}</span></template>
      <template #cell-status="{row}"><StatusBadge :label="row.status" :tone="tone(row.status)" /></template>
      <template #cell-actions="{row}"><Link :href="route('purchase.returns.show', row.id)" class="size-8 grid place-items-center rounded-md hover:bg-accent"><Eye class="size-4"/></Link></template>
    </DataTable>
      </PageHeader>
  </AdminLayout>
</template>