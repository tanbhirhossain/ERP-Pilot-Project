<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Plus, Trash2 } from 'lucide-vue-next';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import SearchableSelect from '../../../../../CORE/Resources/js/Components/SearchableSelect.vue';
defineProps<{ order?:any }>();
const o = (typeof window !== 'undefined' ? null : null) as any;
const form=useForm({ customer_id: (globalThis as any).__orderCustomer ?? null as number|null, warehouse_id: null as number|null, order_id: null as number|null, invoice_date: new Date().toISOString().slice(0,10), due_date:'', currency:'BDT', payment_method:'credit', bank_id:null as number|null, notes:'', items:[{ product_id:null as number|null, qty:1, unit_price:null as number|null, discount_pct:0, vat_rate:0 }] });
function addItem(){ form.items.push({ product_id:null, qty:1, unit_price:null, discount_pct:0, vat_rate:0 }); }
function removeItem(i:number){ form.items.splice(i,1); }
function submit(){ form.post(route('sales.invoices.store')); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'Invoices',href:route('sales.invoices.index')},{title:'Create'}]">
    <Head title="Create Invoice" />
    <div class="max-w-4xl mx-auto space-y-4">
      <h1 class="text-xl font-bold">New Invoice — posting will deduct stock & create journal (AR Dr → Sales Cr)</h1>
      <div v-if="order" class="bg-violet-500/5 border border-violet-500/20 rounded p-2 text-sm">Pre-filled from Order {{ order.order_no }} — {{ order.customer?.name }}</div>
      <form @submit.prevent="submit" class="space-y-4">
        <p v-if="Object.keys(form.errors).length" class="rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive">{{ Object.values(form.errors)[0] }}</p>
        <div class="bg-card border rounded-xl p-4 space-y-4">
          <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div class="space-y-2"><Label>Customer *</Label><SearchableSelect v-model="form.customer_id" endpoint="/partner/api/options/customers" placeholder="Search customer"/></div>
            <div class="space-y-2"><Label>Warehouse *</Label><SearchableSelect v-model="form.warehouse_id" endpoint="/inventory/api/options/warehouses" placeholder="Warehouse"/></div>
            <div class="space-y-2"><Label>Date *</Label><Input v-model="form.invoice_date" type="date"/></div>
          </div>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4"><div class="space-y-2"><Label>Due Date</Label><Input v-model="form.due_date" type="date"/></div><div class="space-y-2"><Label>Payment Method</Label><select v-model="form.payment_method" class="w-full h-9 rounded-md border bg-background px-3 text-sm"><option value="credit">Credit</option><option value="cash">Cash</option><option value="bank">Bank</option></select></div></div>
        </div>
        <div class="bg-card border rounded-xl p-4 space-y-3">
          <h3 class="font-semibold text-sm">Items</h3>
          <div v-for="(it,i) in form.items" :key="i" class="border rounded-lg p-3 space-y-3 relative">
            <Button type="button" variant="ghost" size="icon" class="absolute top-2 right-2 size-6" @click="removeItem(i)"><Trash2 class="size-3"/></Button>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
              <div class="space-y-1"><Label>Product *</Label><SearchableSelect v-model="it.product_id" endpoint="/inventory/api/options/products" placeholder="Search"/></div>
              <div class="space-y-1"><Label>Qty</Label><Input v-model.number="it.qty" type="number" step="0.01"/></div>
              <div class="space-y-1"><Label>Price</Label><Input v-model.number="it.unit_price" type="number" step="0.01"/></div>
            </div>
          </div>
          <Button type="button" variant="outline" size="sm" @click="addItem"><Plus class="size-4"/> Add Item</Button>
        </div>
        <Button type="submit">Create Invoice</Button>
      </form>
    </div>
  </AdminLayout>
</template>
