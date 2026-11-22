<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import FormSection from '@core/Components/FormSection.vue';
import FormField from '@core/Components/FormField.vue';
import FormActions from '@core/Components/FormActions.vue';
import FormCheckbox from '@core/Components/FormCheckbox.vue';
import { useUnsavedGuard } from '@core/Composables/useUnsavedGuard';
const form=useForm({ name:'', symbol:'', category:'unit', ratio_to_base:'1', is_active:true });
useUnsavedGuard(() => form.isDirty);
function submit(){ form.post(route('inventory.uoms.store')); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Inventory' }, { title: 'UOMs', href: route('inventory.uoms.index') }, { title: 'Create' }]"><Head title="Create UOM"/><PageHeader title="Add Unit of Measure" description="Complete the form — required *" :icon="Package">
    <div class="max-w-4xl"><form @submit.prevent="submit" class="space-y-4"><p v-if="Object.keys(form.errors).length" class="rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive">{{ Object.values(form.errors)[0] }}</p>
        <FormSection title="Details" :cols="2">
<FormField label="Name" required>
    <template #default="{ id, invalid }">
        <Input :id="id" :aria-invalid="invalid" v-model="form.name" placeholder="Piece"/>
    </template>
</FormField><div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4"><FormField label="Symbol" required>
    <template #default="{ id, invalid }">
        <Input :id="id" :aria-invalid="invalid" v-model="form.symbol" placeholder="PCS"/>
    </template>
</FormField><FormField label="Category">
    <template #default="{ id, invalid }">
        <FormSelect :id="id" :invalid="invalid" v-model="form.category" ><option value="unit">Unit</option><option value="weight">Weight</option><option value="length">Length</option><option value="volume">Volume</option><option value="area">Area</option></FormSelect>
    </template>
</FormField></div><FormField label="Ratio to Base">
    <template #default="{ id, invalid }">
        <Input :id="id" :aria-invalid="invalid" v-model.number="form.ratio_to_base" type="number" step="0.0001"/>
    </template>
</FormField><FormCheckbox v-model="form.is_active" label="Active" /><FormActions submit-label="Create" :processing="form.processing" :dirty="form.isDirty" cancel-href="route('inventory.uoms.index')" />
        </FormSection>
</form></div>    </PageHeader>
  </AdminLayout>
</template>
