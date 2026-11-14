<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import SearchableSelect from '@core/Components/SearchableSelect.vue';
import { useUnsavedGuard } from '@core/Composables/useUnsavedGuard';
const props=defineProps<{ designation:{id:number;name:string;code?:string;description?:string;department_id:number|null;grade?:string;level:number;is_active:boolean}, departmentOptions:{value:number;label:string}[] }>();
const form=useForm({name:props.designation.name,code:props.designation.code||'',description:props.designation.description||'',department_id:props.designation.department_id,grade:props.designation.grade||'',level:props.designation.level,is_active:props.designation.is_active});
useUnsavedGuard(() => form.isDirty);
function submit(){ form.put(route('hrm.designations.update',props.designation.id)); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'HRM'},{title:'Designations',href:route('hrm.designations.index')},{title:'Edit'}]">
    <Head title="Edit Designation"/><PageHeader title="Edit Designation" description="Complete the form — required *" :icon="Package">
    <div class="max-w-4xl bg-card border rounded-xl shadow-sm p-6"><form @submit.prevent="submit" class="space-y-4">
      <div class="space-y-2"><Label>Name *</Label><Input v-model="form.name"/><p v-if="form.errors.name" class="text-sm text-destructive">{{ form.errors.name }}</p></div>
      <div class="grid grid-cols-2 gap-4"><div class="space-y-2"><Label>Code</Label><Input v-model="form.code"/></div><div class="space-y-2"><Label>Grade</Label><Input v-model="form.grade"/></div></div>
      <div class="space-y-2"><Label>Level</Label><Input v-model.number="form.level" type="number"/></div>
      <div class="space-y-2"><Label>Department</Label><SearchableSelect v-model="form.department_id" :options="departmentOptions"/></div>
      <div class="space-y-2"><Label>Description</Label><Input v-model="form.description"/></div>
      <div class="flex items-center gap-2"><input type="checkbox" v-model="form.is_active" id="ac"/><Label for="ac">Active</Label></div>
      <Button type="submit" :disabled="form.processing">Update Designation</Button>
    </form></div>
      </PageHeader>
  </AdminLayout>
</template>
