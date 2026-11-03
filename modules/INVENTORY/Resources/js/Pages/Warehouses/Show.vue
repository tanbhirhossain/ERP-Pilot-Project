<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Warehouse, MapPin, Boxes } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import StatusBadge from '../../../../../CORE/Resources/js/Components/StatusBadge.vue';
defineProps<{ warehouse:any; stockSummary:any }>();
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'Warehouses',href:route('inventory.warehouses.index')},{title:warehouse.code}]">
    <Head :title="warehouse.code" />
    <div class="space-y-4">
      <div class="bg-card border rounded-xl p-4">
        <div class="flex justify-between"><div><h1 class="text-xl font-bold flex items-center gap-2"><Warehouse class="size-5"/> {{ warehouse.name }} <span class="font-mono text-sm px-2 py-0.5 rounded bg-amber-500/10 text-amber-600">{{ warehouse.code }}</span> <StatusBadge :label="warehouse.type" tone="primary"/></h1><p class="text-sm text-muted-foreground">{{ warehouse.address_line || '' }} {{ warehouse.city }} {{ warehouse.district }}</p></div><Link :href="route('inventory.warehouses.edit', warehouse.id)"><Button variant="outline" size="sm">Edit</Button></Link></div>
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mt-4">
          <div class="bg-muted/40 rounded-lg p-3 border"><p class="text-xs text-muted-foreground">Locations</p><p class="text-lg font-bold">{{ warehouse.locations?.length ?? 0 }}</p></div>
          <div class="bg-muted/40 rounded-lg p-3 border"><p class="text-xs text-muted-foreground">Stock Lines</p><p class="text-lg font-bold">{{ warehouse.stocks?.length ?? 0 }}</p></div>
          <div class="bg-muted/40 rounded-lg p-3 border"><p class="text-xs text-muted-foreground">Manager</p><p class="font-bold">{{ warehouse.manager?.name || '—' }}</p></div>
          <div class="bg-muted/40 rounded-lg p-3 border"><p class="text-xs text-muted-foreground">Status</p><StatusBadge :label="warehouse.is_active?'Active':'Inactive'" :tone="warehouse.is_active?'success':'neutral'" /></div>
        </div>
      </div>
      <div class="bg-card border rounded-xl p-4">
        <h3 class="font-semibold flex items-center gap-2 mb-2"><MapPin class="size-4"/> Locations — {{ warehouse.locations?.length ?? 0 }}</h3>
        <div v-if="!warehouse.locations?.length" class="text-sm text-muted-foreground py-4 text-center">No locations — add bins/shelves.</div>
        <div v-for="loc in warehouse.locations" :key="loc.id" class="border rounded p-2 text-sm flex justify-between"><span>{{ loc.name }} ({{ loc.code }}) — {{ loc.type }}</span><span class="text-muted-foreground">{{ loc.is_active?'Active':'Inactive' }}</span></div>
      </div>
      <div class="bg-card border rounded-xl p-4">
        <h3 class="font-semibold flex items-center gap-2 mb-2"><Boxes class="size-4"/> Stock Summary</h3>
        <div v-if="!warehouse.stocks?.length" class="text-sm text-muted-foreground py-4 text-center">No stock.</div>
        <div v-for="s in warehouse.stocks" :key="s.id" class="flex justify-between text-sm border-b py-1"><span>{{ s.product?.name || 'Product #'+s.product_id }}</span><span class="font-mono">{{ s.qty_on_hand }} @ {{ s.avg_cost }}</span></div>
      </div>
    </div>
  </AdminLayout>
</template>
