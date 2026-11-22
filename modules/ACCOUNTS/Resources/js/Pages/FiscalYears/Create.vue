<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import FormSection from '@core/Components/FormSection.vue';
import FormField from '@core/Components/FormField.vue';
import FormActions from '@core/Components/FormActions.vue';
import { useUnsavedGuard } from '@core/Composables/useUnsavedGuard';
const form=useForm({ name:'FY 2025-2026', code:'FY2526', start_date:'2025-07-01', end_date:'2026-06-30', notes:'' });
useUnsavedGuard(() => form.isDirty);
function submit(){ form.post(route('accounts.fiscal-years.store')); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Accounts' }, { title: 'Fiscal Years', href: route('accounts.fiscal-years.index') }, { title: 'Create' }]">
    <Head title="Create Fiscal Year"/>
    <div class="max-w-3xl"><PageHeader title="New Fiscal Year" description="12 periods auto-created" /><form @submit.prevent="submit" class="space-y-4"><p v-if="Object.keys(form.errors).length" class="rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive">{{ Object.values(form.errors)[0] }}</p>
        <FormSection title="Details" :cols="2">
<FormField label="Name" required>
    <template #default="{ id, invalid }">
        <Input :id="id" :aria-invalid="invalid" v-model="form.name"/>
    </template>
</FormField><FormField label="Code" required>
    <template #default="{ id, invalid }">
        <Input :id="id" :aria-invalid="invalid" v-model="form.code"/>
    </template>
</FormField><div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4"><FormField label="Start" required>
    <template #default="{ id, invalid }">
        <Input :id="id" :aria-invalid="invalid" v-model="form.start_date" type="date"/>
    </template>
</FormField><FormField label="End" required>
    <template #default="{ id, invalid }">
        <Input :id="id" :aria-invalid="invalid" v-model="form.end_date" type="date"/>
    </template>
</FormField></div><FormField label="Notes">
    <template #default="{ id, invalid }">
        <Textarea :id="id" :aria-invalid="invalid" v-model="form.notes" rows="2"/>
    </template>
</FormField><FormActions submit-label="Create" :processing="form.processing" :dirty="form.isDirty" cancel-href="route('accounts.fiscal-years.index')" />
        </FormSection>
</form></div>
  </AdminLayout>
</template>
