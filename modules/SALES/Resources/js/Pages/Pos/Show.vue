<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import StatusBadge from '../../../../../CORE/Resources/js/Components/StatusBadge.vue';
import { formatCurrency } from '../../../../../CORE/Resources/js/Utils/format';
defineProps<{ sale:any }>();
function voidSale(id:number){ if(confirm('Void this POS sale? Stock & ledger will be reversed.')) router.post(route('sales.pos.void', id)); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'POS History',href:route('sales.pos.history')},{title:sale.pos_no}]"><Head :title="sale.pos_no"/><div class="space-y-4 max-w-2xl mx-auto"><div class="bg-card border rounded-xl p-4"><h1 class="text-xl font-bold flex items-center gap-2">{{ sale.pos_no }} <StatusBadge :label="sale.status" :tone="sale.status==='completed'?'success':'danger'" /></h1><p class="text-sm text-muted-foreground">{{ sale.customer_name }} · {{ sale.sale_date }} · {{ formatCurrency(Number(sale.total_amount)) }} via {{ sale.payment_method }}</p><div class="flex gap-2 mt-3"><Link :href="route('sales.pos.receipt', sale.id)"><Button size="sm">Receipt</Button></Link><Button v-if="sale.status==='completed'" variant="destructive" size="sm" @click="voidSale(sale.id)">Void</Button></div></div><div class="bg-card border rounded-xl p-4"><h3 class="font-semibold mb-2">Items</h3><div v-for="it in sale.items" :key="it.id" class="flex justify-between text-sm border-b py-1"><span>{{ it.product_name }} × {{ it.qty }}</span><span class="font-mono">{{ formatCurrency(Number(it.line_total)) }}</span></div></div></div></AdminLayout>
</template>
