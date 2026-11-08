<script setup lang="ts">
import { Head, router, Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import PageHeader from '../../../../../CORE/Resources/js/Components/PageHeader.vue';
import StatusBadge from '../../../../../CORE/Resources/js/Components/StatusBadge.vue';
import StaticTable from '../../../../../CORE/Resources/js/Components/StaticTable.vue';
defineProps<{landedCost:any}>();
function allocate(id:number){ if(confirm('Allocate landed cost? Will bump FIFO layers & stock avg_cost and post JV (Dr Inventory / Cr Expenses).')) router.post(route('purchase.landed-costs.allocate', id)); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Purchase' }, { title: 'Landed Costs', href: route('purchase.landed-costs.index') }, { title: landedCost.lc_no }]">
    <Head :title="landedCost.lc_no"/>
    <PageHeader title="Pages Detail" description="Detail view — audit trail & actions" :icon="FileText">
    <div class="space-y-4">
      <div class="flex justify-between items-center"><h1 class="text-xl font-bold">{{ landedCost.lc_no }} <StatusBadge :label="landedCost.status" :tone="landedCost.status==='allocated'?'success':'warning'"/></h1>
        <div class="flex gap-2"><Button v-if="landedCost.status==='draft'" @click="allocate(landedCost.id)" class="bg-emerald-600 hover:bg-emerald-700">Allocate → Stock & Journal</Button><Link :href="route('purchase.receipts.show', landedCost.receipt_id)"><Button variant="outline" size="sm">View GRN</Button></Link></div>
      </div>
      <div class="bg-card border rounded-xl shadow-sm p-4 text-sm">
        <div class="grid md:grid-cols-3 gap-3"><div>GRN: <b>{{ landedCost.receipt?.receipt_no }}</b></div><div>Basis: {{ landedCost.allocation_basis }}</div><div>Total: <b>{{ landedCost.total_cost }} BDT</b></div></div>
        <div class="mt-2">Notes: {{ landedCost.notes || '—' }}</div>
      </div>
      <div class="bg-card border rounded-xl shadow-sm p-4">
        <h3 class="font-semibold mb-2">Cost Lines — {{ landedCost.lines.length }}</h3>
        <StaticTable
            :empty="!landedCost?.lines?.length"
            empty-title="No cost lines"
            empty-description="Charges recorded on this landed cost appear here."
        ><thead class="text-xs text-muted-foreground"><tr><th class="text-left">Name</th><th class="text-right">Amount</th></tr></thead><tbody><tr v-for="l in landedCost.lines" :key="l.id" class="border-t"><td>{{ l.name }}</td><td class="text-right">{{ l.amount }}</td></tr></tbody></StaticTable>
      </div>
      <div v-if="landedCost.allocations?.length" class="bg-card border rounded-xl shadow-sm p-4">
        <h3 class="font-semibold mb-2">Allocations — per receipt item</h3>
        <StaticTable
            :empty="!landedCost?.allocations?.length"
            empty-title="Not allocated yet"
            empty-description="How this cost is spread across the received items appears here."
        ><thead class="text-xs text-muted-foreground"><tr><th>Product</th><th class="text-right">Qty</th><th class="text-right">Base Value</th><th class="text-right">Allocated</th><th class="text-right">Landed/Unit</th><th class="text-right">New Unit</th></tr></thead>
        <tbody><tr v-for="a in landedCost.allocations" :key="a.id" class="border-t"><td>{{ a.product?.name || a.product_id }}</td><td class="text-right">{{ a.qty }}</td><td class="text-right">{{ a.base_value }}</td><td class="text-right font-bold">{{ a.allocated_cost }}</td><td class="text-right">{{ a.landed_unit_cost }}</td><td class="text-right">{{ a.new_unit_cost }}</td></tr></tbody></StaticTable>
      </div>
      <div v-else class="bg-card border rounded-xl shadow-sm p-6 text-center text-sm text-muted-foreground">Not yet allocated — cost will be distributed by {{ landedCost.allocation_basis }} weight once you click Allocate.</div>
    </div>
      </PageHeader>
  </AdminLayout>
</template>
