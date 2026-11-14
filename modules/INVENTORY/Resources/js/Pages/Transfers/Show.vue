<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import type { Transfer } from '@inventory/types';
import { Button } from '@/components/ui/button';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import StatusBadge from '@core/Components/StatusBadge.vue';
import { formatDate } from '@core/Utils/format';
import StaticTable from '@core/Components/StaticTable.vue';
import { useConfirm } from '@core/Composables/useConfirm';
const { confirm } = useConfirm();
defineProps<{ transfer:Transfer }>();
async function ship(id:number){ if(await confirm({ description: 'Ship this transfer? Stock will be deducted from source.', danger: false })) router.post(route('inventory.transfers.ship', id), {}, {preserveScroll:true}); }
async function receive(id:number){ if(await confirm({ description: 'Receive this transfer? Stock will be added to destination.', danger: false })) router.post(route('inventory.transfers.receive', id), {}, {preserveScroll:true}); }
async function tone(s:string){ if(s==='received') return 'success'; if(s==='in_transit') return 'info'; if(s==='draft') return 'warning'; return 'neutral'; }
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Inventory' }, { title: 'Transfers', href: route('inventory.transfers.index') }, { title: transfer.transfer_no }]">
    <Head :title="transfer.transfer_no" />
    <div class="space-y-4 ">
      <div class="bg-card border rounded-xl p-4">
        <div class="flex justify-between gap-4">
          <div><h1 class="text-xl font-bold flex items-center gap-2">{{ transfer.transfer_no }} <StatusBadge :label="transfer.status" :tone="tone(transfer.status)" /></h1><p class="text-sm text-muted-foreground">{{ transfer.from_warehouse?.name }} → {{ transfer.to_warehouse?.name }} · {{ formatDate(transfer.created_at) }}</p></div>
          <div class="flex gap-2">
            <Button v-if="transfer.status==='draft' || transfer.status==='pending'" @click="ship(transfer.id)" size="sm">Ship</Button>
            <Button v-if="transfer.status==='in_transit'" @click="receive(transfer.id)" size="sm" class="bg-emerald-600 hover:bg-emerald-700">Receive</Button>
            <Link :href="route('inventory.transfers.edit', transfer.id)"><Button variant="outline" size="sm">Edit</Button></Link>
          </div>
        </div>
        <p v-if="transfer.reason" class="text-sm mt-3 p-2 bg-muted/40 rounded">{{ transfer.reason }}</p>
      </div>
      <div class="bg-card border rounded-xl p-4">
        <h3 class="font-semibold mb-2">Items — {{ transfer.items.length }}</h3>
        <StaticTable
          :empty="!transfer?.items?.length"
          empty-title="No items on this transfer"
          empty-description="Products moved by this transfer appear here."
        >
          <thead class="bg-muted/40 text-xs uppercase"><tr><th class="px-3 py-2 text-left">Product</th><th class="px-3 py-2 text-center">Requested</th><th class="px-3 py-2 text-center">Shipped</th><th class="px-3 py-2 text-center">Received</th></tr></thead>
          <tbody class="divide-y"><tr v-for="it in transfer.items" :key="it.id"><td class="px-3 py-2">{{ it.product?.name || '#'+it.product_id }}</td><td class="px-3 py-2 text-center font-mono">{{ it.qty_requested }}</td><td class="px-3 py-2 text-center font-mono">{{ it.qty_shipped }}</td><td class="px-3 py-2 text-center font-mono">{{ it.qty_received }}</td></tr></tbody>
        </StaticTable>
      </div>
    </div>
  </AdminLayout>
</template>
