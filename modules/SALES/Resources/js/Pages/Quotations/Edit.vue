<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import SearchableSelect from '../../../../../CORE/Resources/js/Components/SearchableSelect.vue';
const props=defineProps<{ quotation:any }>();
const form=useForm({ customer_id: props.quotation.customer_id, quotation_date: props.quotation.quotation_date.slice(0,10), expiry_date: props.quotation.expiry_date?.slice(0,10) ?? '', notes: props.quotation.notes ?? '', items: props.quotation.items.map((i:any)=>({ product_id:i.product_id, qty:Number(i.qty), unit_price:Number(i.unit_price), discount_pct:Number(i.discount_pct), vat_rate:Number(i.vat_rate) })) });
function submit(){ form.put(route('sales.quotations.update', props.quotation.id)); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'Quotations',href:route('sales.quotations.index')},{title:quotation.quotation_no},{title:'Edit'}]"><Head :title="`Edit ${quotation.quotation_no}`"/><div class="max-w-4xl mx-auto space-y-4"><h1 class="text-xl font-bold">Edit {{ quotation.quotation_no }}</h1><form @submit.prevent="submit" class="space-y-4"><p v-if="Object.keys(form.errors).length" class="rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive">{{ Object.values(form.errors)[0] }}</p><div class="bg-card border rounded-xl p-4 space-y-4"><div class="space-y-2"><Label>Customer</Label><SearchableSelect v-model="form.customer_id" endpoint="/partner/api/options/customers" /></div><div class="grid grid-cols-1 sm:grid-cols-2 gap-3"><div v-for="(it,i) in form.items" :key="i" class="border rounded p-2 grid grid-cols-2 gap-2"><div class="space-y-1"><Label>Product</Label><SearchableSelect v-model="it.product_id" endpoint="/inventory/api/options/products"/></div><div class="space-y-1"><Label>Qty</Label><Input v-model.number="it.qty" type="number" step="0.01"/></div><div class="space-y-1"><Label>Price</Label><Input v-model.number="it.unit_price" type="number" step="0.01"/></div></div></div></div><Button type="submit">Update</Button></form></div></AdminLayout>
</template>
