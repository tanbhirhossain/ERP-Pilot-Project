<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import type { PurchaseReturn } from '@purchase/types';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import StatusBadge from '@core/Components/StatusBadge.vue';
import { formatCurrency, formatDate } from '@core/Utils/format';
import StaticTable from '@core/Components/StaticTable.vue';
defineProps<{ ret: PurchaseReturn }>();
function tone(s:string){ if(s==='posted') return 'success'; if(s==='draft') return 'warning'; if(s==='cancelled') return 'danger'; return 'neutral'; }
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Purchase' }, { title: $props.ret.return_no }]">
    <Head :title="$props.ret.return_no" />
    <PageHeader title="Pages Detail" description="Vendor: {{ $props.ret.vendor?.name }} · WH: {{ $props.ret.warehouse?.name }} · {{ formatDate($props." :icon="FileText">
    <div class="space-y-4">
      <div class="bg-card border rounded-xl shadow-sm p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h1 class="text-xl font-bold font-mono">{{ $props.ret.return_no }} <StatusBadge :label="$props.ret.status" :tone="tone($props.ret.status)" class="ml-2"/></h1>
            <p class="text-sm text-muted-foreground">Vendor: {{ $props.ret.vendor?.name }} · WH: {{ $props.ret.warehouse?.name }} · {{ formatDate($props.ret.return_date) }} <span v-if="$props.ret.receipt">· GRN: {{ $props.ret.receipt.receipt_no }}</span> <span v-if="$props.ret.bill">· Bill: {{ $props.ret.bill.bill_no }}</span></p>
            <p class="text-sm">Total: <span class="font-mono font-bold">{{ formatCurrency(Number($props.ret.total_amount)) }}</span> <span v-if="$props.ret.journal" class="text-xs text-muted-foreground">· Journal {{ $props.ret.journal.journal_no }}</span></p>
          </div>
          <div class="flex gap-2">
            <Link :href="route('purchase.returns.edit', $props.ret.id)"><Button variant="outline" size="sm">Edit</Button></Link>
            <Button v-if="$props.ret.status==='draft'" size="sm" @click="router.post(route('purchase.returns.post', $props.ret.id))">Post — Stock OUT + AP Debit</Button>
          </div>
        </div>
        <p v-if="$props.ret.notes" class="mt-2 text-sm bg-muted/30 rounded p-2">{{ $props.ret.notes }}</p>
      </div>
      <div class="bg-card border rounded-xl shadow-sm p-4">
        <h3 class="font-semibold mb-2">Items</h3>
        <StaticTable
          :empty="!$props.ret?.items?.length"
          empty-title="No lines on this return"
          empty-description="Items returned to the vendor appear here."
        >
          <thead><tr class="border-b text-muted-foreground"><th class="text-left py-2">Product</th><th class="text-right">Qty</th><th class="text-right">Price</th><th class="text-right">Total</th></tr></thead>
          <tbody><tr v-for="it in $props.ret.items" :key="it.id" class="border-b"><td class="py-2"><div class="font-medium">{{ it.product_name }}</div><div class="text-xs text-muted-foreground font-mono">{{ it.sku }}</div></td><td class="text-right font-mono">{{ it.qty }}</td><td class="text-right font-mono">{{ formatCurrency(Number(it.unit_price)) }}</td><td class="text-right font-mono font-bold">{{ formatCurrency(Number(it.line_total)) }}</td></tr></tbody>
        </StaticTable>
      </div>
    </div>
      </PageHeader>
  </AdminLayout>
</template>