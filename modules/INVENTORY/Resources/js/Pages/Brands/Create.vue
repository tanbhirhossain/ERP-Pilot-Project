<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import PageHeader from '../../../../../CORE/Resources/js/Components/PageHeader.vue';
const form=useForm({ name:'', code:'', description:'', website:'', is_active:true });
function submit(){ form.post(route('inventory.brands.store')); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'Brands',href:route('inventory.brands.index')},{title:'Create'}]">
    <Head title="Create Brand"/><PageHeader title="Add Brand" description="Complete the form — required *" :icon="Package">
    <div class="max-w-2xl mx-auto"><h1 class="text-xl font-bold mb-4">Add Brand</h1><form @submit.prevent="submit" class="bg-card border rounded-2xl shadow-sm p-4 space-y-4"><p v-if="Object.keys(form.errors).length" class="rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive">{{ Object.values(form.errors)[0] }}</p><div class="space-y-2"><Label>Name *</Label><Input v-model="form.name" placeholder="Hammer"/></div><div class="space-y-2"><Label>Code</Label><Input v-model="form.code"/></div><div class="space-y-2"><Label>Website</Label><Input v-model="form.website"/></div><label class="flex items-center gap-2 text-sm"><input type="checkbox" v-model="form.is_active" class="rounded"/> Active</label><Button type="submit">Create</Button></form></div>
      </PageHeader>
  </AdminLayout>
</template>
