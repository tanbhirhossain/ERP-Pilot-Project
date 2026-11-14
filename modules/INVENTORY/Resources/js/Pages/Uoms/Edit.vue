<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import type { Uom } from '@inventory/types';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import { useUnsavedGuard } from '@core/Composables/useUnsavedGuard';
const props=defineProps<{ uom:Uom }>();
const form=useForm({ name:props.uom.name, symbol:props.uom.symbol, category:props.uom.category, ratio_to_base:props.uom.ratio_to_base, is_active:props.uom.is_active });
useUnsavedGuard(() => form.isDirty);
function submit(){ form.put(route('inventory.uoms.update', props.uom.id)); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Inventory' }, { title: 'UOMs', href: route('inventory.uoms.index') }, { title: uom.name }, { title: 'Edit' }]"><Head :title="`Edit ${uom.name}`"/><PageHeader title="Edit {{ uom.name }}" description="Complete the form — required *" :icon="Package">
    <div class="max-w-4xl"><form @submit.prevent="submit" class="bg-card border rounded-xl shadow-sm p-4 space-y-4"><div class="space-y-2"><Label>Name</Label><Input v-model="form.name"/></div><div class="space-y-2"><Label>Symbol</Label><Input v-model="form.symbol"/></div><Button type="submit">Update</Button></form></div>    </PageHeader>
  </AdminLayout>
</template>
