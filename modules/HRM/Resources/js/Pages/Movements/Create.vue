<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import PageHeader from '../../../../../CORE/Resources/js/Components/PageHeader.vue';
import SearchableSelect from '../../../../../CORE/Resources/js/Components/SearchableSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
defineProps<{ departments:any[]; designations:any[] }>();
const form=reactive({employee_id:null as any, movement_type:'Transfer', effective_date:new Date().toISOString().slice(0,10), to_department_id:null as any, to_designation_id:null as any, to_grade:'', to_salary:null as any, reason:''});
const types=['Transfer','Promotion','Demotion','Department Change','Designation Change','Grade Change','Location Transfer'];
function submit(){ router.post(route('hrm.movements.store'), form as any); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'HRM' }, { title: 'Movements', href: route('hrm.movements.index') }, { title: 'Create' }]">
    <Head title="New Movement — HRM"/>
    <div class="space-y-6">
      <PageHeader title="New Transfer / Promotion" description="Creates a versioned career event. After draft, submit → manager/HR approve → employee master + salary history auto-updated." />
      <div class="bg-card border rounded-xl p-6 space-y-4">
        <div class="grid md:grid-cols-2 gap-4">
          <div class="space-y-1"><Label>Employee *</Label><SearchableSelect v-model="form.employee_id" endpoint="/hrm/api/options/employees" placeholder="Select employee"/></div>
          <div class="space-y-1"><Label>Movement Type *</Label><select v-model="form.movement_type" class="h-9 w-full rounded-md border bg-background px-3 text-sm"><option v-for="t in types" :key="t" :value="t">{{ t }}</option></select></div>
          <div class="space-y-1"><Label>Effective Date *</Label><Input type="date" v-model="form.effective_date"/></div>
          <div class="space-y-1"><Label>To Department</Label><SearchableSelect v-model="form.to_department_id" endpoint="/hrm/api/options/departments" placeholder="Keep current if blank"/></div>
          <div class="space-y-1"><Label>To Designation</Label><SearchableSelect v-model="form.to_designation_id" endpoint="/hrm/api/options/designations" placeholder="Keep current if blank"/></div>
          <div class="space-y-1"><Label>To Grade</Label><Input v-model="form.to_grade" placeholder="e.g., Grade-5"/></div>
          <div class="space-y-1"><Label>To Gross Salary (BDT)</Label><Input type="number" v-model.number="form.to_salary" placeholder="e.g., 45000"/></div>
          <div class="md:col-span-2 space-y-1"><Label>Reason</Label><Input v-model="form.reason" placeholder="Annual promotion cycle 2026"/></div>
        </div>
        <div class="flex justify-end gap-2"><a :href="route('hrm.movements.index')" class="px-4 py-2 rounded border text-sm">Cancel</a><Button @click="submit" class="bg-primary">Create Draft</Button></div>
      </div>
      <p class="text-xs text-muted-foreground">From values (dept/designation/salary) are auto-snapshot from employee current record. Increment = to_salary – from_salary. Approval creates salary history + PAYROLL increment audit.</p>
    </div>
  </AdminLayout>
</template>
