<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import type { SelectOption } from '@core/types';
import { Button } from '@/components/ui/button';

import { Label } from '@/components/ui/label';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import FormSection from '@core/Components/FormSection.vue';
import FormField from '@core/Components/FormField.vue';
import FormActions from '@core/Components/FormActions.vue';
import SearchableSelect from '@core/Components/SearchableSelect.vue';
import { useUnsavedGuard } from '@core/Composables/useUnsavedGuard';
defineProps<{ warehouseOptions:SelectOption[] }>();
const form=useForm({ warehouse_id:null as number|null, notes:'', items:[{ product_id:null as number|null, system_qty:null as number|null, counted_qty:null as number|null }] });
useUnsavedGuard(() => form.isDirty);
function submit(){ form.post(route('inventory.counts.store')); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Inventory' }, { title: 'Physical Counts', href: route('inventory.counts.index') }, { title: 'Create' }]"><Head title="Create Count"/><PageHeader title="New Physical Count" description="Complete the form — required *" :icon="Package">
    <div class="max-w-4xl"><form @submit.prevent="submit" class="space-y-4">
        <FormSection title="Details" :cols="2">
<FormField label="Warehouse" required>
    <template #default="{ id, invalid }">
        <SearchableSelect :id="id" v-model="form.warehouse_id" :options="warehouseOptions" placeholder="Select warehouse"/>
    </template>
</FormField><FormActions submit-label="Create" :processing="form.processing" :dirty="form.isDirty" cancel-href="route('inventory.counts.index')" />
        </FormSection>
</form></div>    </PageHeader>
  </AdminLayout>
</template>
