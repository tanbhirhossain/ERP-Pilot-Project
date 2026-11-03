<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import StatusBadge from '../../../../../CORE/Resources/js/Components/StatusBadge.vue';
import { formatCurrency, formatDate } from '../../../../../CORE/Resources/js/Utils/format';
defineProps<{ ret:any }>();
function approve(id:number){ if(confirm('Approve return? Stock will be restocked for resalable items & credit note posted.')) router.post(route('sales.returns.approve', id)); }
function cancel(id:number){ if(confirm('Cancel return?')) router.post(route('sales.returns.cancel', id)); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'Returns',href:route('sales.returns.index')},{title:ret.return_no}]">
    <Head :title="ret.return_no" />
    <div class="space-y-4 max-w-5xl mx-auto">
      <div class="flex items-center justify-between"><h1 class="text-xl font-bold flex items-center gap-2">{{ ret.return_no }} <StatusBadge :label="ret.status" :tone="ret.status==='approved'?'success':'warning'" /> <span v-if="ret.is_restocked" class="text-xs px-2 py-1 rounded bg-emerald-100 text-emerald-700">Restocked</span></h1>
        <div class="flex gap-2"><Button v-if="ret.status==='draft'" @click="approve(ret.id)" size="sm" class="bg-emerald-600 hover:bg-emerald-700">Approve — Restock + Credit Note</Button><Button v-if="ret.status==='draft'" variant="outline" @click="cancel(ret.id)" size="sm">Cancel</Button></div>
      </div>
      <div class="bg-card border rounded-xl p-4">
        <div class="grid md:grid-cols-3 gap-3 text-sm">
          <div><span class="text-muted-foreground">Invoice</span><div class="font-mono font-bold">{{ ret.invoice?.invoice_no }}</div></div>
          <div><span class="text-muted-foreground">Customer</span><div class="font-medium">{{ ret.customer?.name }}</div></div>
          <div><span class="text-muted-foreground">Return Date</span><div>{{ formatDate(ret.return_date) }}</div></div>
          <div><span class="text-muted-foreground">Warehouse</span><div>{{ ret.warehouse?.name }}</div></div>
          <div><span class="text-muted-foreground">Reason</span><div>{{ ret.reason || '—' }}</div></div>
          <div><span class="text-muted-foreground">Journal</span><div><Link v-if="ret.journal" :href="route('accounts.journals.show', ret.journal.id)" class="text-primary hover:underline font-mono">{{ ret.journal.journal_no }}</Link><span v-else class="text-muted-foreground">— pending approve</span></div></div>
        </div>
        <div class="mt-3 text-sm">Total: <b class="font-mono">{{ formatCurrency(Number(ret.total_amount)) }}</b> (Sub {{ formatCurrency(Number(ret.subtotal)) }} + VAT {{ formatCurrency(Number(ret.vat_amount)) }})</div>
      </div>
      <div class="bg-card border rounded-xl p-4">
        <h3 class="font-semibold mb-2">Items — {{ ret.items.length }}</h3>
        <table class="w-full text-sm"><thead class="bg-muted/40 text-xs uppercase"><tr><th class="px-3 py-2 text-left">Product</th><th class="px-3 py-2 text-center">Qty</th><th class="px-3 py-2 text-right">Price</th><th class="px-3 py-2 text-right">Total</th><th class="px-3 py-2 text-center">Condition</th></tr></thead>
        <tbody class="divide-y"><tr v-for="it in ret.items" :key="it.id"><td class="px-3 py-2">{{ it.product?.name }}</td><td class="px-3 py-2 text-center font-mono">{{ it.qty }}</td><td class="px-3 py-2 text-right font-mono">{{ formatCurrency(Number(it.unit_price)) }}</td><td class="px-3 py-2 text-right font-mono font-bold">{{ formatCurrency(Number(it.line_total)) }}</td><td class="px-3 py-2 text-center"><span :class="['px-2 py-1 rounded text-xs', it.condition==='resalable'?'bg-emerald-100 text-emerald-700':'bg-red-100 text-red-700']">{{ it.condition }}</span></td></tr></tbody></table>
      </div>
    </div>
  </AdminLayout>
</template>
