<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import type { Adjustment } from '@inventory/types';
import type { SelectOption } from '@core/types';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import FormSection from '@core/Components/FormSection.vue';
import FormField from '@core/Components/FormField.vue';
import FormActions from '@core/Components/FormActions.vue';
import SearchableSelect from '@core/Components/SearchableSelect.vue';
import { useUnsavedGuard } from '@core/Composables/useUnsavedGuard';
const props=defineProps<{ adjustment:Adjustment; warehouseOptions:SelectOption[] }>();
const form=useForm({ warehouse_id:props.adjustment.warehouse_id, type:props.adjustment.type, reason:props.adjustment.reason, notes:props.adjustment.notes??'', items:props.adjustment.items.map((i:any)=>({ product_id:i.product_id, qty:i.qty, unit_cost:i.unit_cost, reason:i.reason??'' })) });
useUnsavedGuard(() => form.isDirty);
function submit(){ form.put(route('inventory.adjustments.update', props.adjustment.id)); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Inventory' }, { title: 'Adjustments', href: route('inventory.adjustments.index') }, { title: adjustment.adjustment_no }, { title: 'Edit' }]"><Head :title="`Edit ${adjustment.adjustment_no}`"/><PageHeader title="Edit {{ adjustment.adjustment_no }}" description="Complete the form — required *" :icon="Package">
    <div class="space-y-4"><form @submit.prevent="submit" class="space-y-4"><p v-if="Object.keys(form.errors).length" class="rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive">{{ Object.values(form.errors)[0] }}</p>
        <FormSection title="Details" :cols="2">
<div class="bg-card border rounded-xl shadow-sm p-4 space-y-4 col-span-full"><div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4"><FormField label="Warehouse">
    <template #default="{ id, invalid }">
        <SearchableSelect :id="id" v-model="form.warehouse_id" :options="warehouseOptions"/>
    </template>
</FormField><FormField label="Type">
    <template #default="{ id, invalid }">
        <FormSelect :id="id" :invalid="invalid" v-model="form.type" ><option value="correction">Correction</option><option value="increase">Increase</option><option value="decrease">Decrease</option><option value="damage">Damage</option><option value="expired">Expired</option><option value="found">Found</option></FormSelect>
    </template>
</FormField></div><FormField label="Reason">
    <template #default="{ id, invalid }">
        <Textarea :id="id" :aria-invalid="invalid" v-model="form.reason" rows="2"/>
    </template>
</FormField></div><div class="bg-card border rounded-xl shadow-sm p-4 space-y-3 col-span-full"><h3 class="font-semibold text-sm">Items</h3><div v-for="(it,i) in form.items" :key="i" class="grid grid-cols-1 sm:grid-cols-3 gap-3 border rounded p-2"><div class="space-y-1"><Label>Product</Label><SearchableSelect v-model="it.product_id" endpoint="/inventory/api/options/products" placeholder="Search"/></div><div class="space-y-1"><Label>Qty</Label><Input v-model.number="it.qty" type="number" step="0.01"/></div><div class="space-y-1"><Label>Cost</Label><Input v-model.number="it.unit_cost" type="number" step="0.01"/></div></div></div><FormActions submit-label="Update" :processing="form.processing" :dirty="form.isDirty" cancel-href="route('inventory.adjustments.index')" />
        </FormSection>
</form></div>    </PageHeader>
  </AdminLayout>
</template>
