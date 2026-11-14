<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import { useUnsavedGuard } from '@core/Composables/useUnsavedGuard';
const form=useForm({ name:'', code:'', description:'', website:'', is_active:true });
useUnsavedGuard(() => form.isDirty);
function submit(){ form.post(route('inventory.brands.store')); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Inventory' }, { title: 'Brands', href: route('inventory.brands.index') }, { title: 'Create' }]">
    <Head title="Create Brand"/><PageHeader title="Add Brand" description="Complete the form — required *" :icon="Package">
    <div class="max-w-4xl"><form @submit.prevent="submit" class="bg-card border rounded-xl shadow-sm p-4 space-y-4"><p v-if="Object.keys(form.errors).length" class="rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive">{{ Object.values(form.errors)[0] }}</p><div class="space-y-2"><Label>Name *</Label><Input v-model="form.name" placeholder="Hammer"/></div><div class="space-y-2"><Label>Code</Label><Input v-model="form.code"/></div><div class="space-y-2"><Label>Website</Label><Input v-model="form.website"/></div><label class="flex items-center gap-2 text-sm"><input type="checkbox" v-model="form.is_active" class="rounded"/> Active</label><Button type="submit">Create</Button></form></div>
      </PageHeader>
  </AdminLayout>
</template>
