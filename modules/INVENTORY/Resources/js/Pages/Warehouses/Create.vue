<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
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
import FormCheckbox from '@core/Components/FormCheckbox.vue';
import SearchableSelect from '@core/Components/SearchableSelect.vue';
import { useUnsavedGuard } from '@core/Composables/useUnsavedGuard';
defineProps<{ userOptions:SelectOption[] }>();
const form=useForm({ name:'', code:'', short_code:'', type:'main', address_line:'', city:'', district:'', division:'', phone:'', email:'', manager_user_id:null as number|null, is_default:false, is_active:true, allow_negative_stock:false, notes:'' });
useUnsavedGuard(() => form.isDirty);
function submit(){ form.post(route('inventory.warehouses.store')); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Inventory' }, { title: 'Warehouses', href: route('inventory.warehouses.index') }, { title: 'Create' }]">
    <Head title="Create Warehouse" />
    <PageHeader title="Add Warehouse" description="Complete the form — required *" :icon="Package">
    <div class="space-y-4">
      <form @submit.prevent="submit" class="space-y-4">
        <p v-if="Object.keys(form.errors).length" class="rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive">{{ Object.values(form.errors)[0] }}</p>
        <FormSection title="Details" :cols="2">

        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4"><FormField label="Name" required>
    <template #default="{ id, invalid }">
        <Input :id="id" :aria-invalid="invalid" v-model="form.name" placeholder="Main Warehouse"/>
    </template>
</FormField><FormField label="Code" required>
    <template #default="{ id, invalid }">
        <Input :id="id" :aria-invalid="invalid" v-model="form.code" placeholder="WH-001"/>
    </template>
</FormField></div>
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4"><FormField label="Type" required>
    <template #default="{ id, invalid }">
        <FormSelect :id="id" :invalid="invalid" v-model="form.type" ><option value="main">Main</option><option value="sub">Sub</option><option value="transit">Transit</option><option value="virtual">Virtual</option></FormSelect>
    </template>
</FormField><FormField label="Manager">
    <template #default="{ id, invalid }">
        <SearchableSelect :id="id" v-model="form.manager_user_id" :options="userOptions" placeholder="Select manager" clearable />
    </template>
</FormField></div>
        <FormField label="Address">
    <template #default="{ id, invalid }">
        <Input :id="id" :aria-invalid="invalid" v-model="form.address_line"/>
    </template>
</FormField>
        <div class="grid grid-cols-1 sm:grid-cols-3 2xl:grid-cols-4 gap-4"><FormField label="City">
    <template #default="{ id, invalid }">
        <Input :id="id" :aria-invalid="invalid" v-model="form.city"/>
    </template>
</FormField><FormField label="District">
    <template #default="{ id, invalid }">
        <Input :id="id" :aria-invalid="invalid" v-model="form.district"/>
    </template>
</FormField><FormField label="Division">
    <template #default="{ id, invalid }">
        <Input :id="id" :aria-invalid="invalid" v-model="form.division"/>
    </template>
</FormField></div>
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4"><FormField label="Phone">
    <template #default="{ id, invalid }">
        <Input :id="id" :aria-invalid="invalid" v-model="form.phone"/>
    </template>
</FormField><FormField label="Email">
    <template #default="{ id, invalid }">
        <Input :id="id" :aria-invalid="invalid" v-model="form.email" type="email"/>
    </template>
</FormField></div>
        <FormField label="Notes">
    <template #default="{ id, invalid }">
        <Textarea :id="id" :aria-invalid="invalid" v-model="form.notes" rows="2"/>
    </template>
</FormField>
        <div class="flex gap-4"><FormCheckbox v-model="form.is_default" label="Default" /><FormCheckbox v-model="form.is_active" label="Active" /><FormCheckbox v-model="form.allow_negative_stock" label="Allow Negative" /></div>
        <FormActions submit-label="Create" :processing="form.processing" :dirty="form.isDirty" cancel-href="route('inventory.warehouses.index')" />
          </FormSection>
    </form>
    </div>
      </PageHeader>
  </AdminLayout>
</template>
