<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
defineProps<{kits:any}>();
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'Kits',href:route('inventory.kits.index')}]">
    <Head title="Kits / BOM Production"/>
    <div class="max-w-6xl mx-auto space-y-4">
      <div class="flex justify-between items-center"><h1 class="text-xl font-bold">🧩 Kits / BOM — Production</h1><Link :href="route('inventory.kits.create')"><span class="px-3 py-2 rounded bg-primary text-primary-foreground text-sm">New Kit Order</span></Link></div>
      <p class="text-sm text-muted-foreground">Consume components (FIFO cost) → produce finished kit. Uses BOM if defined on product, or ad-hoc components.</p>
      <div class="bg-card border rounded-xl p-4">
        <table class="w-full text-sm"><thead><tr class="text-xs text-muted-foreground"><th class="text-left">Kit No</th><th>Product (Kit)</th><th>Warehouse</th><th class="text-center">Qty</th><th class="text-right">Cost</th><th>Status</th></tr></thead>
        <tbody><tr v-for="k in kits.data" :key="k.id" class="border-t"><td class="font-mono"><Link :href="route('inventory.kits.show', k.id)" class="text-primary hover:underline">{{ k.kit_no }}</Link></td><td>{{ k.product?.name }}</td><td>{{ k.warehouse?.name }}</td><td class="text-center">{{ k.qty_to_produce }}</td><td class="text-right">{{ k.total_component_cost }}</td><td>{{ k.status }}</td></tr></tbody></table>
        <div v-if="!kits.data.length" class="py-8 text-center text-sm text-muted-foreground">No kits yet — create a BOM kit to combine components into a finished good.</div>
      </div>
    </div>
  </AdminLayout>
</template>
