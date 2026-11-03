<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import PageHeader from '../../../../../CORE/Resources/js/Components/PageHeader.vue';
import SearchableSelect from '../../../../../CORE/Resources/js/Components/SearchableSelect.vue';
const props=defineProps<{ duty:{id:number;employee_id:string;shift_id:number|null;roster_date:string;status:string;remarks?:string}, shiftOptions:any[], employeeOptions:any[] }>();
const form=useForm({employee_id:props.duty.employee_id,shift_id:props.duty.shift_id,roster_date:props.duty.roster_date,status:props.duty.status,remarks:props.duty.remarks||''});
function submit(){ form.put(route('hrm.duties.update',props.duty.id)); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'HRM'},{title:'Duties',href:route('hrm.duties.index')},{title:'Edit'}]">
    <Head title="Edit Duty"/><PageHeader title="Edit Duty" description="Complete the form — required *" :icon="Package">
    <div class="max-w-2xl mx-auto bg-card border rounded-2xl shadow-sm p-6"><h1 class="text-lg font-bold mb-4">Edit Duty</h1>
    <form @submit.prevent="submit" class="space-y-4">
      <div class="space-y-2"><Label>Employee</Label><SearchableSelect v-model="form.employee_id" :options="employeeOptions" endpoint="/hrm/api/options/employees"/></div>
      <div class="space-y-2"><Label>Shift</Label><SearchableSelect v-model="form.shift_id" :options="shiftOptions"/></div>
      <div class="space-y-2"><Label>Roster Date</Label><Input v-model="form.roster_date" type="date"/></div>
      <div class="space-y-2"><Label>Status</Label><select v-model="form.status" class="w-full h-9 rounded-md border bg-background px-3 text-sm"><option>Scheduled</option><option>Working</option><option>Completed</option><option>Absent</option><option>Off</option><option>Swapped</option></select></div>
      <div class="space-y-2"><Label>Remarks</Label><Input v-model="form.remarks"/></div>
      <Button type="submit" :disabled="form.processing">Update Duty</Button>
    </form></div>
      </PageHeader>
  </AdminLayout>
</template>
