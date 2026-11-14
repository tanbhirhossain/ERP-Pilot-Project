<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import SearchableSelect from '@core/Components/SearchableSelect.vue';
import { useUnsavedGuard } from '@core/Composables/useUnsavedGuard';
defineProps<{ parentOptions: {value:number;label:string}[] }>();
const form=useForm({name:'',code:'',description:'',parent_id:null as number|null,is_active:true});
useUnsavedGuard(() => form.isDirty);
function submit(){ form.post(route('hrm.departments.store')); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'HRM'},{title:'Departments',href:route('hrm.departments.index')},{title:'Create'}]">
    <Head title="Create Department"/>
    <PageHeader title="New Department" description="Complete the form — required *" :icon="Package">
    <div class="max-w-4xl bg-card border rounded-xl shadow-sm p-6">
      <form @submit.prevent="submit" class="space-y-4">
        <div class="space-y-2"><Label>Name *</Label><Input v-model="form.name"/><p v-if="form.errors.name" class="text-sm text-destructive">{{ form.errors.name }}</p></div>
        <div class="space-y-2"><Label>Code</Label><Input v-model="form.code" placeholder="e.g. HR-01"/><p v-if="form.errors.code" class="text-sm text-destructive">{{ form.errors.code }}</p></div>
        <div class="space-y-2"><Label>Description</Label><Input v-model="form.description"/><p v-if="form.errors.description" class="text-sm text-destructive">{{ form.errors.description }}</p></div>
        <div class="space-y-2"><Label>Parent Department</Label><SearchableSelect v-model="form.parent_id" :options="parentOptions" placeholder="Top-level (no parent)"/></div>
        <div class="flex items-center gap-2"><input type="checkbox" v-model="form.is_active" id="active"/><Label for="active">Active</Label></div>
        <Button type="submit" :disabled="form.processing">Create Department</Button>
      </form>
    </div>
      </PageHeader>
  </AdminLayout>
</template>
