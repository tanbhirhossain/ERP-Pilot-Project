<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import SearchableSelect from '@core/Components/SearchableSelect.vue';
import { useUnsavedGuard } from '@core/Composables/useUnsavedGuard';
const props=defineProps<{
  supervisor:{id:number;employee_id:string;supervisor_kind:string;supervisor_id:number|null;supervisor_employee_id:string|null;supervisor_type:string;is_active:boolean;is_primary:boolean;effective_from:string|null;effective_to:string|null;remarks:string|null};
  employeeOptions:{value:string;label:string}[];
  userOptions:{value:number;label:string;description?:string}[];
  currentUser:{value:number;label:string;description?:string}|null;
  currentEmployee:{value:string;label:string}|null;
  supervisorTypes:string[];
}>();
const kind=ref<'user'|'employee'>(props.supervisor.supervisor_kind==='employee' ? 'employee':'user');
const form=useForm({
  employee_id: props.supervisor.employee_id,
  supervisor_kind: kind.value as 'user'|'employee',
  supervisor_id: props.supervisor.supervisor_id,
  supervisor_employee_id: props.supervisor.supervisor_employee_id,
  supervisor_type: props.supervisor.supervisor_type,
  is_active: props.supervisor.is_active,
  is_primary: props.supervisor.is_primary,
  effective_from: props.supervisor.effective_from||'',
  effective_to: props.supervisor.effective_to||'',
  remarks: props.supervisor.remarks||'',
});
useUnsavedGuard(() => form.isDirty);
watch(kind,(v)=>{ form.supervisor_kind=v; if(v==='user') form.supervisor_employee_id=null; else form.supervisor_id=null; });
function submit(){ form.put(route('hrm.supervisors.update',props.supervisor.id)); }
// Ensure current selection is in options if not already
const empOpts = props.currentEmployee && !props.employeeOptions.find(o=>o.value===props.currentEmployee!.value) ? [...props.employeeOptions, props.currentEmployee] : props.employeeOptions;
const userOpts = props.currentUser && !props.userOptions.find(o=>o.value===props.currentUser!.value) ? [...props.userOptions, props.currentUser] : props.userOptions;
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'HRM'},{title:'Supervisors',href:route('hrm.supervisors.index')},{title:'Edit'}]">
    <Head title="Edit Supervisor"/>
    <div style="max-width:720px;margin:0 auto;background:white;border:1px solid #e2e8f0;border-radius:12px;padding:20px">
      <PageHeader :title="`Edit Assignment #${supervisor.id}`" />
      <p style="font-size:12px;color:#64748b;margin-bottom:16px">Change supervisor kind (User ⇄ Employee) at any time.</p>
      <form @submit.prevent="submit" style="display:flex;flex-direction:column;gap:16px">
        <div style="display:flex;flex-direction:column;gap:6px"><Label>Employee *</Label><SearchableSelect v-model="form.employee_id" :options="empOpts" placeholder="Select employee..."/><p v-if="form.errors.employee_id" style="font-size:12px;color:#dc2626">{{ form.errors.employee_id }}</p></div>
        <div style="display:flex;gap:8px;background:#f1f5f9;border-radius:999px;padding:4px;width:fit-content">
          <button type="button" @click="kind='user'" :style="kind==='user' ? 'background:#1e40af;color:white;border-radius:999px;padding:6px 14px;font-size:13px;font-weight:600' : 'padding:6px 14px;font-size:13px;color:#475569'">👤 User Account</button>
          <button type="button" @click="kind='employee'" :style="kind==='employee' ? 'background:#0e7490;color:white;border-radius:999px;padding:6px 14px;font-size:13px;font-weight:600' : 'padding:6px 14px;font-size:13px;color:#475569'">🧑 Employee</button>
        </div>
        <div v-if="kind==='user'" style="display:flex;flex-direction:column;gap:6px"><Label>Supervisor (User) *</Label><SearchableSelect v-model="form.supervisor_id" :options="userOpts" placeholder="Search user..."/><p v-if="form.errors.supervisor_id" style="font-size:12px;color:#dc2626">{{ form.errors.supervisor_id }}</p></div>
        <div v-else style="display:flex;flex-direction:column;gap:6px"><Label>Supervisor (Employee) *</Label><SearchableSelect v-model="form.supervisor_employee_id" :options="empOpts" placeholder="Select employee as supervisor..."/><p v-if="form.errors.supervisor_employee_id" style="font-size:12px;color:#dc2626">{{ form.errors.supervisor_employee_id }}</p></div>
        <div style="display:flex;flex-direction:column;gap:6px"><Label>Supervisor Type *</Label><select v-model="form.supervisor_type" style="border:1px solid #e2e8f0;border-radius:8px;padding:8px 10px;font-size:14px"><option v-for="t in supervisorTypes" :key="t" :value="t">{{ t }}</option></select></div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
          <div style="display:flex;flex-direction:column;gap:6px"><Label>Effective From</Label><Input type="date" v-model="form.effective_from"/></div>
          <div style="display:flex;flex-direction:column;gap:6px"><Label>Effective To</Label><Input type="date" v-model="form.effective_to"/></div>
        </div>
        <div style="display:flex;flex-direction:column;gap:6px"><Label>Remarks</Label><Input v-model="form.remarks"/></div>
        <div style="display:flex;gap:16px"><label style="display:flex;align-items:center;gap:6px;font-size:13px"><input type="checkbox" v-model="form.is_active"/> Active</label><label style="display:flex;align-items:center;gap:6px;font-size:13px"><input type="checkbox" v-model="form.is_primary"/> ★ Primary</label></div>
        <Button type="submit" :disabled="form.processing" style="width:fit-content">Update</Button>
      </form>
    </div>
  </AdminLayout>
</template>
