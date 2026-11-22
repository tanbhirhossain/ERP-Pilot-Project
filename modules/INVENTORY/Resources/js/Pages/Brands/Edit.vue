<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import type { Brand } from '@inventory/types';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import FormSection from '@core/Components/FormSection.vue';
import FormField from '@core/Components/FormField.vue';
import FormActions from '@core/Components/FormActions.vue';
import { useUnsavedGuard } from '@core/Composables/useUnsavedGuard';
const props=defineProps<{ brand:Brand }>();
const form=useForm({ name:props.brand.name, code:props.brand.code??'', description:props.brand.description??'', website:props.brand.website??'', is_active:props.brand.is_active });
useUnsavedGuard(() => form.isDirty);
function submit(){ form.put(route('inventory.brands.update', props.brand.id)); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Inventory' }, { title: 'Brands', href: route('inventory.brands.index') }, { title: brand.name }, { title: 'Edit' }]"><Head :title="`Edit ${brand.name}`"/><PageHeader title="Edit {{ brand.name }}" description="Complete the form — required *" :icon="Package">
    <div class="max-w-4xl"><form @submit.prevent="submit" class="space-y-4">
        <FormSection title="Details" :cols="2">
<FormField label="Name">
    <template #default="{ id, invalid }">
        <Input :id="id" :aria-invalid="invalid" v-model="form.name"/>
    </template>
</FormField><FormField label="Code">
    <template #default="{ id, invalid }">
        <Input :id="id" :aria-invalid="invalid" v-model="form.code"/>
    </template>
</FormField><FormActions submit-label="Update" :processing="form.processing" :dirty="form.isDirty" cancel-href="route('inventory.brands.index')" />
        </FormSection>
</form></div>    </PageHeader>
  </AdminLayout>
</template>
