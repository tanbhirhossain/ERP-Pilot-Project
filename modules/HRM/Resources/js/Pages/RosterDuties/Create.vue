<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import PageHeader from '../../../../../CORE/Resources/js/Components/PageHeader.vue';
import SearchableSelect from '../../../../../CORE/Resources/js/Components/SearchableSelect.vue';
defineProps<{ shiftOptions:any[]; employeeOptions:any[] }>();
const form=useForm({employee_id:'',shift_id:null as number|null,roster_date:'',status:'Scheduled',remarks:''});
function submit(){ form.post(route('hrm.duties.store')); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'HRM'},{title:'Duties',href:route('hrm.duties.index')},{title:'Create'}]">
    <Head title="Create Duty"/><PageHeader title="Assign Duty" description="Complete the form — required *" :icon="Package">
    <div class="max-w-2xl mx-auto bg-card border rounded-2xl shadow-sm p-6"><h1 class="text-lg font-bold mb-4">Assign Duty</h1>
    <form @submit.prevent="submit" class="space-y-4">
      <div class="space-y-2"><Label>Employee *</Label><SearchableSelect v-model="form.employee_id" :options="employeeOptions" endpoint="/hrm/api/options/employees" placeholder="Select employee"/><p v-if="form.errors.employee_id" class="text-sm text-destructive">{{ form.errors.employee_id }}</p></div>
      <div class="space-y-2"><Label>Shift</Label><SearchableSelect v-model="form.shift_id" :options="shiftOptions" placeholder="Select shift"/></div>
      <div class="space-y-2"><Label>Roster Date *</Label><Input v-model="form.roster_date" type="date"/><p v-if="form.errors.roster_date" class="text-sm text-destructive">{{ form.errors.roster_date }}</p></div>
      <div class="space-y-2"><Label>Status</Label><select v-model="form.status" class="w-full h-9 rounded-md border bg-background px-3 text-sm"><option>Scheduled</option><option>Working</option><option>Completed</option><option>Absent</option><option>Off</option><option>Swapped</option></select></div>
      <div class="space-y-2"><Label>Remarks</Label><Input v-model="form.remarks"/></div>
      <Button type="submit" :disabled="form.processing">Assign Duty</Button>
    </form></div>
      </PageHeader>
  </AdminLayout>
</template>
