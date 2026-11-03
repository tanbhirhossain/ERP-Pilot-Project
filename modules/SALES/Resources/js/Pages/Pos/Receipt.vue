<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Printer, ArrowLeft, Ban } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import { formatCurrency } from '../../../../../CORE/Resources/js/Utils/format';
defineProps<{ sale: any }>();
function payLabel(m: string) { return ({ cash: 'Cash', bank: 'Bank', bkash: 'bKash', card: 'Card', split: 'Split (Cash+Bank)' } as any)[m] ?? m; }
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'POS', href: route('sales.pos.terminal') }, { title: sale.pos_no }]">
    <Head :title="`Receipt ${sale.pos_no}`" />
    <div class="mb-3 flex gap-2 print:hidden">
      <Link :href="route('sales.pos.terminal')"><Button variant="outline" size="sm"><ArrowLeft class="size-4" />Terminal</Button></Link>
      <Link :href="route('sales.pos.history')"><Button variant="outline" size="sm">History</Button></Link>
    </div>
    <div class="max-w-md mx-auto bg-white text-black border rounded-xl p-6 font-mono text-sm print:shadow-none print:border-none">
      <div class="text-center border-b pb-3 mb-3">
        <h1 class="text-lg font-bold tracking-wide">ERP PILOT</h1>
        <p class="text-xs">Chattogram, Bangladesh</p>
        <p class="text-xs">VAT Reg: 123456789 · TRN 2110</p>
        <p class="text-xs">Warehouse: {{ sale.warehouse?.name ?? '-' }} · {{ sale.pos_no }}</p>
        <p class="text-xs mt-1">{{ sale.sale_date }} · {{ sale.customer_name }}</p>
        <p v-if="sale.invoice?.invoice_no" class="text-xs">Invoice: {{ sale.invoice.invoice_no }} · Posted SV</p>
        <p class="text-xs" :class="sale.status==='void'?'text-red-600 font-bold':'text-emerald-700'">{{ sale.status.toUpperCase() }}</p>
      </div>
      <table class="w-full text-xs">
        <thead><tr class="border-b"><th class="text-left py-1">Item</th><th class="text-right">Qty</th><th class="text-right">Price</th><th class="text-right">Total</th></tr></thead>
        <tbody>
          <tr v-for="it in sale.items" :key="it.id" class="border-b border-dashed">
            <td class="py-1"><div class="font-semibold">{{ it.product_name }}</div><div class="text-[10px] text-gray-500">{{ it.sku }} <span v-if="it.vat_rate>0">· VAT {{ it.vat_rate }}%</span></div></td>
            <td class="text-right">{{ it.qty }}</td>
            <td class="text-right">{{ formatCurrency(Number(it.unit_price)) }}</td>
            <td class="text-right">{{ formatCurrency(Number(it.line_total)) }}</td>
          </tr>
        </tbody>
        <tfoot class="font-bold">
          <tr><td colspan="3" class="text-right pt-1">Subtotal</td><td class="text-right">{{ formatCurrency(Number(sale.subtotal)) }}</td></tr>
          <tr v-if="Number(sale.discount_amount)>0"><td colspan="3" class="text-right">Discount</td><td class="text-right">-{{ formatCurrency(Number(sale.discount_amount)) }}</td></tr>
          <tr v-if="Number(sale.vat_amount)>0"><td colspan="3" class="text-right">VAT (2110 VAT Payable)</td><td class="text-right">{{ formatCurrency(Number(sale.vat_amount)) }}</td></tr>
          <tr class="border-t"><td colspan="3" class="text-right text-sm">Grand Total</td><td class="text-right text-sm">{{ formatCurrency(Number(sale.total_amount)) }}</td></tr>
          <tr><td colspan="3" class="text-right">Paid ({{ payLabel(sale.payment_method) }})</td><td class="text-right">{{ formatCurrency(Number(sale.paid_amount)) }}</td></tr>
          <tr v-if="sale.payment_method==='split'"><td colspan="3" class="text-right text-[10px]">↳ Cash {{ formatCurrency(Number(sale.cash_amount)) }} + Bank {{ formatCurrency(Number(sale.bank_amount)) }}</td><td class="text-right"></td></tr>
          <tr v-if="sale.change_amount>0"><td colspan="3" class="text-right">Change</td><td class="text-right">{{ formatCurrency(Number(sale.change_amount)) }}</td></tr>
        </tfoot>
      </table>
      <div class="text-center text-[10px] mt-3 border-t pt-3 leading-tight">
        <p>Stock deducted & ledger posted (AR 1100 / Sales 4001 / VAT 2110 / COGS 5001).</p>
        <p class="mt-1">Thank you! Visit again. — ERP Pilot Project</p>
        <p>{{ sale.pos_no }} · {{ new Date().toLocaleString('en-BD') }}</p>
      </div>
      <div class="flex gap-2 mt-4 print:hidden">
        <Button class="flex-1" @click="window.print()"><Printer class="size-4" />Print Receipt</Button>
        <Link v-if="sale.status!=='void'" :href="route('sales.pos.void', sale.id)" method="post" as="button"><Button variant="destructive"><Ban class="size-4"/>Void</Button></Link>
      </div>
    </div>
  </AdminLayout>
</template>
