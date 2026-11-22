<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import type { Warehouse } from '@inventory/types';
import type { SelectOption } from '@core/types';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import FormSection from '@core/Components/FormSection.vue';
import FormField from '@core/Components/FormField.vue';
import FormActions from '@core/Components/FormActions.vue';
import FormCheckbox from '@core/Components/FormCheckbox.vue';
import SearchableSelect from '@core/Components/SearchableSelect.vue';
import { useUnsavedGuard } from '@core/Composables/useUnsavedGuard';
const props=defineProps<{ warehouse:Warehouse; userOptions:SelectOption[] }>();
const form=useForm({ name:props.warehouse.name, code:props.warehouse.code, type:props.warehouse.type, address_line:props.warehouse.address_line??'', city:props.warehouse.city??'', district:props.warehouse.district??'', phone:props.warehouse.phone??'', email:props.warehouse.email??'', manager_user_id:props.warehouse.manager_user_id, is_default:props.warehouse.is_default, is_active:props.warehouse.is_active, allow_negative_stock:props.warehouse.allow_negative_stock });
useUnsavedGuard(() => form.isDirty);
function submit(){ form.put(route('inventory.warehouses.update', props.warehouse.id)); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Inventory' }, { title: 'Warehouses', href: route('inventory.warehouses.index') }, { title: warehouse.code }, { title: 'Edit' }]"><Head :title="`Edit ${warehouse.code}`"/><PageHeader title="Edit {{ warehouse.code }}" description="Complete the form — required *" :icon="Package">
    <div class="max-w-4xl"><form @submit.prevent="submit" class="space-y-4">
        <FormSection title="Details" :cols="2">
<div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4"><FormField label="Name">
    <template #default="{ id, invalid }">
        <Input :id="id" :aria-invalid="invalid" v-model="form.name"/>
    </template>
</FormField><FormField label="Code">
    <template #default="{ id, invalid }">
        <Input :id="id" :aria-invalid="invalid" v-model="form.code"/>
    </template>
</FormField></div><FormField label="Manager">
    <template #default="{ id, invalid }">
        <SearchableSelect :id="id" v-model="form.manager_user_id" :options="userOptions" placeholder="Select" clearable />
    </template>
</FormField><div class="flex gap-4"><FormCheckbox v-model="form.is_default" label="Default" /><FormCheckbox v-model="form.is_active" label="Active" /></div><FormActions submit-label="Update" :processing="form.processing" :dirty="form.isDirty" cancel-href="route('inventory.warehouses.index')" />
        </FormSection>
</form></div>    </PageHeader>
  </AdminLayout>
</template>
