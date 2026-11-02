<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import SearchableSelect from '../../../../../CORE/Resources/js/Components/SearchableSelect.vue';
defineProps<{ departmentOptions:{value:number;label:string}[] }>();
const form=useForm({name:'',code:'',description:'',department_id:null as number|null,grade:'',level:0,is_active:true});
function submit(){ form.post(route('hrm.designations.store')); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'HRM'},{title:'Designations',href:route('hrm.designations.index')},{title:'Create'}]">
    <Head title="Create Designation"/><div class="max-w-2xl mx-auto bg-card border rounded-xl p-6"><h1 class="text-lg font-bold mb-4">New Designation</h1><form @submit.prevent="submit" class="space-y-4">
      <div class="space-y-2"><Label>Name *</Label><Input v-model="form.name"/><p v-if="form.errors.name" class="text-sm text-destructive">{{ form.errors.name }}</p></div>
      <div class="grid grid-cols-2 gap-4"><div class="space-y-2"><Label>Code</Label><Input v-model="form.code"/></div><div class="space-y-2"><Label>Grade</Label><Input v-model="form.grade" placeholder="e.g. G-09"/></div></div>
      <div class="space-y-2"><Label>Level</Label><Input v-model.number="form.level" type="number"/></div>
      <div class="space-y-2"><Label>Department</Label><SearchableSelect v-model="form.department_id" :options="departmentOptions" placeholder="Select department"/></div>
      <div class="space-y-2"><Label>Description</Label><Input v-model="form.description"/></div>
      <div class="flex items-center gap-2"><input type="checkbox" v-model="form.is_active" id="ac"/><Label for="ac">Active</Label></div>
      <Button type="submit" :disabled="form.processing">Create Designation</Button>
    </form></div>
  </AdminLayout>
</template>
