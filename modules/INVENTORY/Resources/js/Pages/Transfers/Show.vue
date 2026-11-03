<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import StatusBadge from '../../../../../CORE/Resources/js/Components/StatusBadge.vue';
import { formatDate } from '../../../../../CORE/Resources/js/Utils/format';
defineProps<{ transfer:any }>();
function ship(id:number){ if(confirm('Ship this transfer? Stock will be deducted from source.')) router.post(route('inventory.transfers.ship', id), {}, {preserveScroll:true}); }
function receive(id:number){ if(confirm('Receive this transfer? Stock will be added to destination.')) router.post(route('inventory.transfers.receive', id), {}, {preserveScroll:true}); }
function tone(s:string){ if(s==='received') return 'success'; if(s==='in_transit') return 'info'; if(s==='draft') return 'warning'; return 'neutral'; }
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'Transfers',href:route('inventory.transfers.index')},{title:transfer.transfer_no}]">
    <Head :title="transfer.transfer_no" />
    <div class="space-y-4 max-w-4xl mx-auto">
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
        <table class="w-full text-sm">
          <thead class="bg-muted/40 text-xs uppercase"><tr><th class="px-3 py-2 text-left">Product</th><th class="px-3 py-2 text-center">Requested</th><th class="px-3 py-2 text-center">Shipped</th><th class="px-3 py-2 text-center">Received</th></tr></thead>
          <tbody class="divide-y"><tr v-for="it in transfer.items" :key="it.id"><td class="px-3 py-2">{{ it.product?.name || '#'+it.product_id }}</td><td class="px-3 py-2 text-center font-mono">{{ it.qty_requested }}</td><td class="px-3 py-2 text-center font-mono">{{ it.qty_shipped }}</td><td class="px-3 py-2 text-center font-mono">{{ it.qty_received }}</td></tr></tbody>
        </table>
      </div>
    </div>
  </AdminLayout>
</template>
