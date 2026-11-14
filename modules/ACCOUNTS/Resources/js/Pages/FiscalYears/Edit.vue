<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import type { FiscalYear } from '@accounts/types';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import { useUnsavedGuard } from '@core/Composables/useUnsavedGuard';
const props=defineProps<{ fiscalYear:FiscalYear }>();
const form=useForm({ name:props.fiscalYear.name, code:props.fiscalYear.code, start_date:props.fiscalYear.start_date.slice(0,10), end_date:props.fiscalYear.end_date.slice(0,10), notes:props.fiscalYear.notes??'' });
useUnsavedGuard(() => form.isDirty);
function submit(){ form.put(route('accounts.fiscal-years.update', props.fiscalYear.id)); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Accounts' }, { title: 'Fiscal Years', href: route('accounts.fiscal-years.index') }, { title: fiscalYear.code }, { title: 'Edit' }]"><Head :title="`Edit ${fiscalYear.code}`"/><div class="max-w-3xl"><PageHeader :title="`Edit ${fiscalYear.code}`" /><form @submit.prevent="submit" class="bg-card border rounded-xl p-4 space-y-4"><div class="space-y-2"><Label>Name</Label><Input v-model="form.name"/></div><div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4"><div class="space-y-2"><Label>Start</Label><Input v-model="form.start_date" type="date"/></div><div class="space-y-2"><Label>End</Label><Input v-model="form.end_date" type="date"/></div></div><Button type="submit">Update</Button></form></div></AdminLayout>
</template>
