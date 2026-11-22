<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import type { Adjustment } from '@inventory/types';
import { Button } from '@/components/ui/button';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import StatusBadge from '@core/Components/StatusBadge.vue';
import { formatDate } from '@core/Utils/format';
import StaticTable from '@core/Components/StaticTable.vue';
import { useConfirm } from '@core/Composables/useConfirm';
const { confirm } = useConfirm();
defineProps<{ adjustment:Adjustment }>();
async function approve(id:number){ router.post(route('inventory.adjustments.approve', id)); }
async function postAdj(id:number){ if(await confirm({ description: 'Post this adjustment? Stock will be updated.', danger: false })) router.post(route('inventory.adjustments.post', id)); }
async function tone(s:string){ if(s==='posted') return 'success'; if(s==='approved') return 'info'; if(s==='draft') return 'warning'; return 'neutral'; }
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Inventory' }, { title: 'Adjustments', href: route('inventory.adjustments.index') }, { title: adjustment.adjustment_no }]">
    <Head :title="adjustment.adjustment_no" />    <PageHeader :title="adjustment.adjustment_no" :description="`${adjustment.warehouse?.name} · ${formatDate(adjustment.created_at)} by ${adjustment.creator?.name}`">
      <template #badge>
        <StatusBadge :label="adjustment.status" :tone="tone(adjustment.status)" /> <StatusBadge :label="adjustment.type" tone="neutral" />
      </template>
      <template #actions>
        <div class="flex gap-2">
            <Button v-if="['draft','pending_approval'].includes(adjustment.status)" @click="approve(adjustment.id)" size="sm" variant="outline">Approve</Button>
            <Button v-if="adjustment.status==='approved'" @click="postAdj(adjustment.id)" size="sm" class="bg-emerald-600 hover:bg-emerald-700">Post to Stock</Button>
            <Link :href="route('inventory.adjustments.edit', adjustment.id)"><Button variant="outline" size="sm">Edit</Button></Link>
          </div>
      </template>


      <div class="bg-card border rounded-xl p-4">
        <h3 class="font-semibold mb-2">Items — {{ adjustment.items.length }}</h3>
        <StaticTable
          :empty="!adjustment?.items?.length"
          empty-title="No items on this adjustment"
          empty-description="Products adjusted by this document appear here."
        >
          <thead class="bg-muted/40 text-xs uppercase"><tr><th class="px-3 py-2 text-left">Product</th><th class="px-3 py-2 text-center">Qty</th><th class="px-3 py-2 text-right">Unit Cost</th><th class="px-3 py-2 text-right">Total</th></tr></thead>
          <tbody class="divide-y"><tr v-for="it in adjustment.items" :key="it.id"><td class="px-3 py-2">{{ it.product?.name || '#'+it.product_id }}</td><td class="px-3 py-2 text-center font-mono font-bold">{{ it.qty }}</td><td class="px-3 py-2 text-right font-mono">{{ it.unit_cost }}</td><td class="px-3 py-2 text-right font-mono">{{ it.total_cost }}</td></tr></tbody>
        </StaticTable>
          </div>
    </PageHeader>
  </AdminLayout>
</template>
