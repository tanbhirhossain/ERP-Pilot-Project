<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import SearchableSelect from '../../../../../CORE/Resources/js/Components/SearchableSelect.vue';
const props=defineProps<{ invoice:any }>();
const form=useForm({ customer_id:props.invoice.customer_id, warehouse_id:props.invoice.warehouse_id, invoice_date:props.invoice.invoice_date.slice(0,10), due_date:props.invoice.due_date?.slice(0,10)??'', items: props.invoice.items.map((i:any)=>({ product_id:i.product_id, qty:Number(i.qty), unit_price:Number(i.unit_price) })) });
function submit(){ form.put(route('sales.invoices.update', props.invoice.id)); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'Invoices',href:route('sales.invoices.index')},{title:invoice.invoice_no},{title:'Edit'}]"><Head :title="`Edit ${invoice.invoice_no}`"/><div class="max-w-4xl mx-auto"><h1 class="text-xl font-bold mb-4">Edit {{ invoice.invoice_no }}</h1><form @submit.prevent="submit" class="bg-card border rounded-xl p-4 space-y-4"><div class="space-y-2"><Label>Customer</Label><SearchableSelect v-model="form.customer_id" endpoint="/partner/api/options/customers"/></div><div v-for="(it,i) in form.items" :key="i" class="grid grid-cols-2 gap-3 border rounded p-2"><div class="space-y-1"><Label>Product</Label><SearchableSelect v-model="it.product_id" endpoint="/inventory/api/options/products"/></div><div class="space-y-1"><Label>Qty</Label><Input v-model.number="it.qty" type="number" step="0.01"/></div></div><Button type="submit">Update</Button></form></div></AdminLayout>
</template>
