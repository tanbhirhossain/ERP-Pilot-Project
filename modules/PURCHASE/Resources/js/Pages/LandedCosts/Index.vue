<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
defineProps<{landedCosts:any}>();
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'Landed Costs',href:route('purchase.landed-costs.index')}]">
    <Head title="Landed Costs"/>
    <div class="max-w-6xl mx-auto space-y-4">
      <div class="flex justify-between items-center"><h1 class="text-xl font-bold">🚢 Landed Costs — Freight/Customs Allocation</h1><Link :href="route('purchase.landed-costs.create')"><span class="px-3 py-2 rounded bg-primary text-primary-foreground text-sm">New Landed Cost</span></Link></div>
      <p class="text-sm text-muted-foreground">Allocate freight, customs duty, insurance to GRN items by value/qty/weight → bumps FIFO layer unit_cost & stock avg_cost.</p>
      <div class="bg-card border rounded-xl p-4">
        <table class="w-full text-sm"><thead><tr class="text-xs text-muted-foreground"><th class="text-left">LC No</th><th>GRN</th><th class="text-right">Total</th><th>Basis</th><th>Status</th></tr></thead>
        <tbody><tr v-for="lc in landedCosts.data" :key="lc.id" class="border-t"><td class="font-mono"><Link :href="route('purchase.landed-costs.show', lc.id)" class="text-primary hover:underline">{{ lc.lc_no }}</Link></td><td>{{ lc.receipt?.receipt_no || lc.receipt_id }}</td><td class="text-right">{{ lc.total_cost }}</td><td>{{ lc.allocation_basis }}</td><td>{{ lc.status }}</td></tr></tbody></table>
        <div v-if="!landedCosts.data.length" class="py-8 text-center text-sm text-muted-foreground">No landed costs yet — create from GRN Show → Add Landed Cost or via Create page.</div>
      </div>
    </div>
  </AdminLayout>
</template>
