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
const form=useForm({ name:'', code:'', description:'', website:'', is_active:true });
useUnsavedGuard(() => form.isDirty);
function submit(){ form.post(route('inventory.brands.store')); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Inventory' }, { title: 'Brands', href: route('inventory.brands.index') }, { title: 'Create' }]">
    <Head title="Create Brand"/><PageHeader title="Add Brand" description="Complete the form — required *" :icon="Package">
    <div class="max-w-4xl"><form @submit.prevent="submit" class="space-y-4"><p v-if="Object.keys(form.errors).length" class="rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive">{{ Object.values(form.errors)[0] }}</p>
        <FormSection title="Details" :cols="2">
<FormField label="Name" required>
    <template #default="{ id, invalid }">
        <Input :id="id" :aria-invalid="invalid" v-model="form.name" placeholder="Hammer"/>
    </template>
</FormField><FormField label="Code">
    <template #default="{ id, invalid }">
        <Input :id="id" :aria-invalid="invalid" v-model="form.code"/>
    </template>
</FormField><FormField label="Website">
    <template #default="{ id, invalid }">
        <Input :id="id" :aria-invalid="invalid" v-model="form.website"/>
    </template>
</FormField><FormCheckbox v-model="form.is_active" label="Active" /><FormActions submit-label="Create" :processing="form.processing" :dirty="form.isDirty" cancel-href="route('inventory.brands.index')" />
        </FormSection>
</form></div>
      </PageHeader>
  </AdminLayout>
</template>
