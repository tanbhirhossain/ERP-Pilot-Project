<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import SearchableSelect from '@core/Components/SearchableSelect.vue';
import { useUnsavedGuard } from '@core/Composables/useUnsavedGuard';
const props=defineProps<{
  employeeOptions:{value:string;label:string}[];
  userOptions:{value:number;label:string;description?:string}[];
  supervisorTypes:string[];
  preselected_employee_id?:string;
}>();
const form=useForm({
  employee_id: (props.preselected_employee_id||'') as string,
  supervisor_kind:'user' as 'user'|'employee',
  supervisor_id:null as number|null,
  supervisor_employee_id:null as string|null,
  supervisor_type:'Supervisor' as string,
  is_active:true as boolean,
  is_primary:false as boolean,
  effective_from:'' as string,
  effective_to:'' as string,
  remarks:'' as string,
});
useUnsavedGuard(() => form.isDirty);
const kind=ref<'user'|'employee'>('user');
watch(kind,(v)=>{ form.supervisor_kind=v; if(v==='user') form.supervisor_employee_id=null; else form.supervisor_id=null; });
function submit(){ form.post(route('hrm.supervisors.store')); }
const empOpts=computed(()=> props.employeeOptions);
const userOpts=computed(()=> props.userOptions);
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'HRM'},{title:'Supervisors',href:route('hrm.supervisors.index')},{title:'Assign'}]">
    <Head title="Assign Supervisor"/>
    <div style="max-width:720px;margin:0 auto;background:white;border:1px solid #e2e8f0;border-radius:12px;padding:20px">
      <PageHeader title="Assign Supervisor" />
      <p style="font-size:12px;color:#64748b;margin-bottom:16px">Flexibility: choose whether the supervisor is a <b>User</b> account or another <b>Employee</b>.</p>
      <form @submit.prevent="submit" style="display:flex;flex-direction:column;gap:16px">
        <div style="display:flex;flex-direction:column;gap:6px">
          <Label>Employee *</Label>
          <SearchableSelect v-model="form.employee_id" :options="empOpts" placeholder="Select employee..." />
          <p v-if="form.errors.employee_id" style="font-size:12px;color:#dc2626">{{ form.errors.employee_id }}</p>
        </div>

        <!-- Kind toggle -->
        <div style="display:flex;gap:8px;background:#f1f5f9;border-radius:999px;padding:4px;width:fit-content">
          <button type="button" @click="kind='user'" :style="kind==='user' ? 'background:#1e40af;color:white;border-radius:999px;padding:6px 14px;font-size:13px;font-weight:600' : 'padding:6px 14px;font-size:13px;color:#475569'">👤 User Account</button>
          <button type="button" @click="kind='employee'" :style="kind==='employee' ? 'background:#0e7490;color:white;border-radius:999px;padding:6px 14px;font-size:13px;font-weight:600' : 'padding:6px 14px;font-size:13px;color:#475569'">🧑 Employee</button>
        </div>
        <p style="font-size:11px;color:#64748b">{{ kind==='user' ? 'Supervisor is a system User (login account).' : 'Supervisor is another Employee — employee can supervise employee.' }}</p>

        <div v-if="kind==='user'" style="display:flex;flex-direction:column;gap:6px">
          <Label>Supervisor (User) *</Label>
          <SearchableSelect v-model="form.supervisor_id" :options="userOpts" placeholder="Search user by name/email..." />
          <p v-if="form.errors.supervisor_id" style="font-size:12px;color:#dc2626">{{ form.errors.supervisor_id }}</p>
        </div>
        <div v-else style="display:flex;flex-direction:column;gap:6px">
          <Label>Supervisor (Employee) *</Label>
          <SearchableSelect v-model="form.supervisor_employee_id" :options="empOpts" placeholder="Select employee as supervisor..." />
          <p v-if="form.errors.supervisor_employee_id" style="font-size:12px;color:#dc2626">{{ form.errors.supervisor_employee_id }}</p>
          <p style="font-size:11px;color:#0e7490;background:#ecfeff;padding:6px 10px;border-radius:6px">Employee-as-supervisor: the selected employee will be recorded as supervisor for {{ form.employee_id || 'the employee' }}.</p>
        </div>

        <div style="display:flex;flex-direction:column;gap:6px">
          <Label>Supervisor Type *</Label>
          <select v-model="form.supervisor_type" style="border:1px solid #e2e8f0;border-radius:8px;padding:8px 10px;font-size:14px">
            <option v-for="t in supervisorTypes" :key="t" :value="t">{{ t }}</option>
          </select>
          <p v-if="form.errors.supervisor_type" style="font-size:12px;color:#dc2626">{{ form.errors.supervisor_type }}</p>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
          <div style="display:flex;flex-direction:column;gap:6px"><Label>Effective From</Label><Input type="date" v-model="form.effective_from"/><p v-if="form.errors.effective_from" style="font-size:12px;color:#dc2626">{{ form.errors.effective_from }}</p></div>
          <div style="display:flex;flex-direction:column;gap:6px"><Label>Effective To</Label><Input type="date" v-model="form.effective_to"/><p v-if="form.errors.effective_to" style="font-size:12px;color:#dc2626">{{ form.errors.effective_to }}</p></div>
        </div>
        <div style="display:flex;flex-direction:column;gap:6px"><Label>Remarks</Label><Input v-model="form.remarks" placeholder="Optional note..."/><p v-if="form.errors.remarks" style="font-size:12px;color:#dc2626">{{ form.errors.remarks }}</p></div>
        <div style="display:flex;gap:16px;align-items:center">
          <label style="display:flex;align-items:center;gap:6px;font-size:13px"><input type="checkbox" v-model="form.is_active"/> Active</label>
          <label style="display:flex;align-items:center;gap:6px;font-size:13px"><input type="checkbox" v-model="form.is_primary"/> ★ Primary (demotes others)</label>
        </div>
        <Button type="submit" :disabled="form.processing" style="width:fit-content">Assign Supervisor</Button>
      </form>
    </div>
  </AdminLayout>
</template>
