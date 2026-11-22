<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import type { Uom } from '@inventory/types';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import FormSection from '@core/Components/FormSection.vue';
import FormField from '@core/Components/FormField.vue';
import FormActions from '@core/Components/FormActions.vue';
import { useUnsavedGuard } from '@core/Composables/useUnsavedGuard';
const props=defineProps<{ uom:Uom }>();
const form=useForm({ name:props.uom.name, symbol:props.uom.symbol, category:props.uom.category, ratio_to_base:props.uom.ratio_to_base, is_active:props.uom.is_active });
useUnsavedGuard(() => form.isDirty);
function submit(){ form.put(route('inventory.uoms.update', props.uom.id)); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Inventory' }, { title: 'UOMs', href: route('inventory.uoms.index') }, { title: uom.name }, { title: 'Edit' }]"><Head :title="`Edit ${uom.name}`"/><PageHeader title="Edit {{ uom.name }}" description="Complete the form — required *" :icon="Package">
    <div class="max-w-4xl"><form @submit.prevent="submit" class="space-y-4">
        <FormSection title="Details" :cols="2">
<FormField label="Name">
    <template #default="{ id, invalid }">
        <Input :id="id" :aria-invalid="invalid" v-model="form.name"/>
    </template>
</FormField><FormField label="Symbol">
    <template #default="{ id, invalid }">
        <Input :id="id" :aria-invalid="invalid" v-model="form.symbol"/>
    </template>
</FormField><FormActions submit-label="Update" :processing="form.processing" :dirty="form.isDirty" cancel-href="route('inventory.uoms.index')" />
        </FormSection>
</form></div>    </PageHeader>
  </AdminLayout>
</template>
