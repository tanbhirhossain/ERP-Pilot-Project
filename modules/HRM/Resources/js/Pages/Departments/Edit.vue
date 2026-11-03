<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import PageHeader from '../../../../../CORE/Resources/js/Components/PageHeader.vue';
import SearchableSelect from '../../../../../CORE/Resources/js/Components/SearchableSelect.vue';
const props=defineProps<{ department:{id:number;name:string;code?:string;description?:string;parent_id:number|null;is_active:boolean}, parentOptions:{value:number;label:string}[] }>();
const form=useForm({name:props.department.name,code:props.department.code||'',description:props.department.description||'',parent_id:props.department.parent_id,is_active:props.department.is_active});
function submit(){ form.put(route('hrm.departments.update',props.department.id)); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'HRM'},{title:'Departments',href:route('hrm.departments.index')},{title:'Edit'}]">
    <Head title="Edit Department"/>
    <PageHeader title="Edit Department" description="Complete the form — required *" :icon="Package">
    <div class="max-w-2xl mx-auto bg-card border rounded-2xl shadow-sm p-6">
      <h1 class="text-lg font-bold mb-4">Edit Department</h1>
      <form @submit.prevent="submit" class="space-y-4">
        <div class="space-y-2"><Label>Name *</Label><Input v-model="form.name"/><p v-if="form.errors.name" class="text-sm text-destructive">{{ form.errors.name }}</p></div>
        <div class="space-y-2"><Label>Code</Label><Input v-model="form.code"/><p v-if="form.errors.code" class="text-sm text-destructive">{{ form.errors.code }}</p></div>
        <div class="space-y-2"><Label>Description</Label><Input v-model="form.description"/></div>
        <div class="space-y-2"><Label>Parent</Label><SearchableSelect v-model="form.parent_id" :options="parentOptions" placeholder="Top-level"/></div>
        <div class="flex items-center gap-2"><input type="checkbox" v-model="form.is_active" id="active"/><Label for="active">Active</Label></div>
        <Button type="submit" :disabled="form.processing">Update Department</Button>
      </form>
    </div>
      </PageHeader>
  </AdminLayout>
</template>
