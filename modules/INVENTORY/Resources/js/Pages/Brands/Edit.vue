<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import PageHeader from '../../../../../CORE/Resources/js/Components/PageHeader.vue';
const props=defineProps<{ brand:any }>();
const form=useForm({ name:props.brand.name, code:props.brand.code??'', description:props.brand.description??'', website:props.brand.website??'', is_active:props.brand.is_active });
function submit(){ form.put(route('inventory.brands.update', props.brand.id)); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Inventory' }, { title: 'Brands', href: route('inventory.brands.index') }, { title: brand.name }, { title: 'Edit' }]"><Head :title="`Edit ${brand.name}`"/><PageHeader title="Edit {{ brand.name }}" description="Complete the form — required *" :icon="Package">
    <div class="max-w-4xl"><form @submit.prevent="submit" class="bg-card border rounded-xl shadow-sm p-4 space-y-4"><div class="space-y-2"><Label>Name</Label><Input v-model="form.name"/></div><div class="space-y-2"><Label>Code</Label><Input v-model="form.code"/></div><Button type="submit">Update</Button></form></div>    </PageHeader>
  </AdminLayout>
</template>
