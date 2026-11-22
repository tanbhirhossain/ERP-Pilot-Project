<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import type { Category } from '@inventory/types';
import type { SelectOption } from '@core/types';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import FormSection from '@core/Components/FormSection.vue';
import FormField from '@core/Components/FormField.vue';
import FormActions from '@core/Components/FormActions.vue';
import SearchableSelect from '@core/Components/SearchableSelect.vue';
import { useUnsavedGuard } from '@core/Composables/useUnsavedGuard';
const props=defineProps<{ category:Category; parentOptions:SelectOption[] }>();
const form=useForm({ name:props.category.name, code:props.category.code??'', parent_id:props.category.parent_id, description:props.category.description??'', sort_order:props.category.sort_order??0, is_active:props.category.is_active });
useUnsavedGuard(() => form.isDirty);
function submit(){ form.put(route('inventory.categories.update', props.category.id)); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Inventory' }, { title: 'Categories', href: route('inventory.categories.index') }, { title: category.name }, { title: 'Edit' }]"><Head :title="`Edit ${category.name}`"/><PageHeader title="Edit {{ category.name }}" description="Complete the form — required *" :icon="Package">
    <div class="max-w-4xl"><form @submit.prevent="submit" class="space-y-4">
        <FormSection title="Details" :cols="2">
<FormField label="Name">
    <template #default="{ id, invalid }">
        <Input :id="id" :aria-invalid="invalid" v-model="form.name"/>
    </template>
</FormField><div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4"><FormField label="Code">
    <template #default="{ id, invalid }">
        <Input :id="id" :aria-invalid="invalid" v-model="form.code"/>
    </template>
</FormField><FormField label="Parent">
    <template #default="{ id, invalid }">
        <SearchableSelect :id="id" v-model="form.parent_id" :options="parentOptions" placeholder="Top" clearable />
    </template>
</FormField></div><FormActions submit-label="Update" :processing="form.processing" :dirty="form.isDirty" cancel-href="route('inventory.categories.index')" />
        </FormSection>
</form></div>    </PageHeader>
  </AdminLayout>
</template>
