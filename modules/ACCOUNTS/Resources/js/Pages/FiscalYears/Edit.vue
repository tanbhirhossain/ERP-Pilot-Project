<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
const props=defineProps<{ fiscalYear:any }>();
const form=useForm({ name:props.fiscalYear.name, code:props.fiscalYear.code, start_date:props.fiscalYear.start_date.slice(0,10), end_date:props.fiscalYear.end_date.slice(0,10), notes:props.fiscalYear.notes??'' });
function submit(){ form.put(route('accounts.fiscal-years.update', props.fiscalYear.id)); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'Fiscal Years',href:route('accounts.fiscal-years.index')},{title:fiscalYear.code},{title:'Edit'}]"><Head :title="`Edit ${fiscalYear.code}`"/><div class="max-w-xl mx-auto"><h1 class="text-xl font-bold mb-4">Edit {{ fiscalYear.code }}</h1><form @submit.prevent="submit" class="bg-card border rounded-xl p-4 space-y-4"><div class="space-y-2"><Label>Name</Label><Input v-model="form.name"/></div><div class="grid grid-cols-1 sm:grid-cols-2 gap-4"><div class="space-y-2"><Label>Start</Label><Input v-model="form.start_date" type="date"/></div><div class="space-y-2"><Label>End</Label><Input v-model="form.end_date" type="date"/></div></div><Button type="submit">Update</Button></form></div></AdminLayout>
</template>
