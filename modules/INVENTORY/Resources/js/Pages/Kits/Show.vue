<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import PageHeader from '../../../../../CORE/Resources/js/Components/PageHeader.vue';
import StatusBadge from '../../../../../CORE/Resources/js/Components/StatusBadge.vue';
import StaticTable from '../../../../../CORE/Resources/js/Components/StaticTable.vue';
defineProps<{kit:any}>();
function complete(id:number){ if(confirm('Complete kitting? Components will be consumed (FIFO) and finished kit produced.')) router.post(route('inventory.kits.complete', id)); }
function cancel(id:number){ if(confirm('Cancel kit?')) router.post(route('inventory.kits.cancel', id)); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Inventory' }, { title: 'Kits / BOM Production', href: route('inventory.kits.index') }, { title: kit.kit_no }]">
    <Head :title="kit.kit_no"/>
    <PageHeader title="Pages Detail" description="Detail view — audit trail & actions" :icon="FileText">
    <div class="space-y-4">
      <div class="flex justify-between items-center"><h1 class="text-xl font-bold">{{ kit.kit_no }} <StatusBadge :label="kit.status" :tone="kit.status==='completed'?'success':kit.status==='draft'?'warning':'neutral'"/></h1>
        <div class="flex gap-2"><Button v-if="kit.status==='draft'" @click="complete(kit.id)" class="bg-emerald-600 hover:bg-emerald-700">Complete — Consume & Produce</Button><Button v-if="kit.status==='draft'" variant="outline" @click="cancel(kit.id)">Cancel</Button></div>
      </div>
      <div class="bg-card border rounded-xl shadow-sm p-4 text-sm">
        <div class="grid md:grid-cols-3 gap-3"><div>Product: <b>{{ kit.product?.name }} ({{ kit.product?.sku }})</b></div><div>Warehouse: {{ kit.warehouse?.name }}</div><div>Qty: <b>{{ kit.qty_to_produce }}</b> → Produced {{ kit.qty_produced }}</div></div>
        <div class="mt-2">Total component cost: <b>{{ kit.total_component_cost }} BDT</b> → Unit cost {{ kit.unit_cost }} BDT (BOM exploded)</div>
        <div class="mt-1">Notes: {{ kit.notes || '—' }}</div>
      </div>
      <div class="bg-card border rounded-xl shadow-sm p-4">
        <h3 class="font-semibold mb-2">Components — {{ kit.items.length }}</h3>
        <StaticTable
            :empty="!kit?.items?.length"
            empty-title="No components on this kit"
            empty-description="Components consumed by this kit appear here."
        ><thead class="text-xs text-muted-foreground"><tr><th class="text-left">Component</th><th class="text-right">Required</th><th class="text-right">Unit Cost</th><th class="text-right">Line Cost</th><th>Optional</th></tr></thead>
        <tbody><tr v-for="it in kit.items" :key="it.id" class="border-t"><td>{{ it.component?.name }}</td><td class="text-right">{{ it.required_qty }}</td><td class="text-right">{{ it.unit_cost }}</td><td class="text-right font-bold">{{ it.line_cost }}</td><td class="text-center">{{ it.is_optional?'Yes':'—' }}</td></tr></tbody></StaticTable>
      </div>
    </div>
      </PageHeader>
  </AdminLayout>
</template>
