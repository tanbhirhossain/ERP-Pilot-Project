<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import type { Order } from '@sales/types';
import { reactive } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import SearchableSelect from '@core/Components/SearchableSelect.vue';
defineProps<{ order:Order }>();
const form = reactive({ customer_id: null as number | string | null, warehouse_id: null as number | string | null, order_id: null as number | string | null, delivery_date: new Date().toISOString().slice(0,10), challan_no:'', vehicle_no:'', notes:'', items: [{ product_id:null, qty:1, lot_no:'' }] as any[] });
function addLine(){ form.items.push({ product_id:null, qty:1, lot_no:'' }); }
function removeLine(i:number){ form.items.splice(i,1); }
function submit(){ router.post(route('sales.deliveries.store'), form as any); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Sales & POS' }, { title: 'Deliveries (Challan)', href: route('sales.deliveries.index') }, { title: 'Create' }]">
    <Head title="New Delivery" />
    <div class="space-y-4">
      <PageHeader title="New Delivery Challan" />
      <div v-if="order" class="bg-amber-50 border border-amber-200 rounded-xl p-3 text-sm">Creating from Order <b>{{ order.order_no }}</b> — {{ order.customer?.name }}. Items below auto-filled from order remaining qty.</div>
      <div class="bg-card border rounded-xl p-4 space-y-3">
        <div class="grid md:grid-cols-3 gap-3">
          <div class="space-y-1"><Label>Customer</Label><SearchableSelect v-model="form.customer_id" endpoint="/partner/api/options/customers" placeholder="Select customer" /></div>
          <div class="space-y-1"><Label>Warehouse</Label><SearchableSelect v-model="form.warehouse_id" endpoint="/inventory/api/options/warehouses" placeholder="Warehouse" /></div>
          <div class="space-y-1"><Label>Order (optional)</Label><SearchableSelect v-model="form.order_id" endpoint="/sales/api/options/orders" placeholder="Link order" /></div>
          <div class="space-y-1"><Label>Delivery Date</Label><Input v-model="form.delivery_date" type="date" /></div>
          <div class="space-y-1"><Label>Challan No</Label><Input v-model="form.challan_no" placeholder="CH-001" /></div>
          <div class="space-y-1"><Label>Vehicle No</Label><Input v-model="form.vehicle_no" placeholder="Dhaka Metro..." /></div>
        </div>
        <div class="space-y-1"><Label>Notes</Label><Input v-model="form.notes" placeholder="Delivery notes" /></div>
        <h3 class="font-semibold mt-4">Items</h3>
        <div v-for="(it, idx) in form.items" :key="idx" class="flex gap-2 items-end border rounded-lg p-2">
          <div class="flex-1"><Label>Product</Label><SearchableSelect v-model="it.product_id" endpoint="/inventory/api/options/products" placeholder="Product" /></div>
          <div class="w-24"><Label>Qty</Label><Input v-model.number="it.qty" type="number" min="0.01" step="1" /></div>
          <div class="w-32"><Label>Lot No</Label><Input v-model="it.lot_no" placeholder="LOT-001" /></div>
          <Button variant="ghost" @click="removeLine(idx)" type="button">✕</Button>
        </div>
        <Button variant="outline" @click="addLine" type="button" size="sm">+ Add Line</Button>
        <div class="flex justify-end gap-2"><Link :href="route('sales.deliveries.index')"><Button variant="outline" type="button">Cancel</Button></Link><Button @click="submit">Create Delivery</Button></div>
      </div>
    </div>
  </AdminLayout>
</template>
