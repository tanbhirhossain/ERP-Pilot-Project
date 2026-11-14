<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import type { SelectOption } from '@core/types';
import { Button } from '@/components/ui/button';

import { Label } from '@/components/ui/label';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import SearchableSelect from '@core/Components/SearchableSelect.vue';
import { useUnsavedGuard } from '@core/Composables/useUnsavedGuard';
defineProps<{ warehouseOptions:SelectOption[] }>();
const form=useForm({ warehouse_id:null as number|null, notes:'', items:[{ product_id:null as number|null, system_qty:null as number|null, counted_qty:null as number|null }] });
useUnsavedGuard(() => form.isDirty);
function submit(){ form.post(route('inventory.counts.store')); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Inventory' }, { title: 'Physical Counts', href: route('inventory.counts.index') }, { title: 'Create' }]"><Head title="Create Count"/><PageHeader title="New Physical Count" description="Complete the form — required *" :icon="Package">
    <div class="max-w-4xl"><form @submit.prevent="submit" class="bg-card border rounded-xl shadow-sm p-4 space-y-4"><div class="space-y-2"><Label>Warehouse *</Label><SearchableSelect v-model="form.warehouse_id" :options="warehouseOptions" placeholder="Select warehouse"/></div><Button type="submit">Create</Button></form></div>    </PageHeader>
  </AdminLayout>
</template>
