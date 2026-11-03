<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import PageHeader from '../../../../../CORE/Resources/js/Components/PageHeader.vue';
import SearchableSelect from '../../../../../CORE/Resources/js/Components/SearchableSelect.vue';
const props=defineProps<{ adjustment:any; warehouseOptions:any[] }>();
const form=useForm({ warehouse_id:props.adjustment.warehouse_id, type:props.adjustment.type, reason:props.adjustment.reason, notes:props.adjustment.notes??'', items:props.adjustment.items.map((i:any)=>({ product_id:i.product_id, qty:i.qty, unit_cost:i.unit_cost, reason:i.reason??'' })) });
function submit(){ form.put(route('inventory.adjustments.update', props.adjustment.id)); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'Adjustments',href:route('inventory.adjustments.index')},{title:adjustment.adjustment_no},{title:'Edit'}]"><Head :title="`Edit ${adjustment.adjustment_no}`"/><PageHeader title="Edit {{ adjustment.adjustment_no }}" description="Complete the form — required *" :icon="Package">
    <div class="max-w-3xl mx-auto space-y-4"><h1 class="text-xl font-bold">Edit {{ adjustment.adjustment_no }}</h1><form @submit.prevent="submit" class="space-y-4"><p v-if="Object.keys(form.errors).length" class="rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive">{{ Object.values(form.errors)[0] }}</p><div class="bg-card border rounded-2xl shadow-sm p-4 space-y-4"><div class="grid grid-cols-1 sm:grid-cols-2 gap-4"><div class="space-y-2"><Label>Warehouse</Label><SearchableSelect v-model="form.warehouse_id" :options="warehouseOptions"/></div><div class="space-y-2"><Label>Type</Label><select v-model="form.type" class="w-full h-9 rounded-md border bg-background px-3 text-sm"><option value="correction">Correction</option><option value="increase">Increase</option><option value="decrease">Decrease</option><option value="damage">Damage</option><option value="expired">Expired</option><option value="found">Found</option></select></div></div><div class="space-y-2"><Label>Reason</Label><Textarea v-model="form.reason" rows="2"/></div></div><div class="bg-card border rounded-2xl shadow-sm p-4 space-y-3"><h3 class="font-semibold text-sm">Items</h3><div v-for="(it,i) in form.items" :key="i" class="grid grid-cols-1 sm:grid-cols-3 gap-3 border rounded p-2"><div class="space-y-1"><Label>Product</Label><SearchableSelect v-model="it.product_id" endpoint="/inventory/api/options/products" placeholder="Search"/></div><div class="space-y-1"><Label>Qty</Label><Input v-model.number="it.qty" type="number" step="0.01"/></div><div class="space-y-1"><Label>Cost</Label><Input v-model.number="it.unit_cost" type="number" step="0.01"/></div></div></div><Button type="submit">Update</Button></form></div>    </PageHeader>
  </AdminLayout>
</template>
