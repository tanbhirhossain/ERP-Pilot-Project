<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
defineProps<{ supervisor:{id:number;employee_id:string;employee:{employee_id:string;full_name:string}|null;supervisor_kind:string;supervisor_id:number|null;supervisor_employee_id:string|null;supervisor:{kind:string;id:string;name:string;email?:string};supervisor_type:string;is_active:boolean;is_primary:boolean;effective_from:string|null;effective_to:string|null;remarks:string|null;created_at:string} }>();
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'HRM'},{title:'Supervisors',href:route('hrm.supervisors.index')},{title:`#${supervisor.id}`}]">
    <Head :title="`Supervisor #${supervisor.id}`"/>
    <div style="max-width:720px;margin:0 auto;display:flex;flex-direction:column;gap:14px">
      <div style="background:white;border:1px solid #e2e8f0;border-radius:12px;padding:18px">
        <div style="display:flex;align-items:center;justify-content:space-between">
          <h1 style="font-size:18px;font-weight:700">Assignment #{{ supervisor.id }}</h1>
          <Link :href="route('hrm.supervisors.edit',supervisor.id)"><Button size="sm">Edit</Button></Link>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:14px;font-size:13px">
          <div><p style="color:#64748b;font-size:11px">EMPLOYEE</p><p style="font-weight:600">{{ supervisor.employee?.full_name|| supervisor.employee_id}}</p><p style="color:#64748b;font-size:12px">{{ supervisor.employee_id}}</p></div>
          <div><p style="color:#64748b;font-size:11px">SUPERVISOR</p>
            <span :style="supervisor.supervisor.kind==='employee' ? 'background:#e0f2fe;color:#0369a1;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700' : 'background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700'">{{ supervisor.supervisor.kind==='employee' ? 'EMPLOYEE' : 'USER' }}</span>
            <p style="font-weight:600;margin-top:4px">{{ supervisor.supervisor.name }}</p><p style="color:#64748b;font-size:12px">{{ supervisor.supervisor.email||'' }}</p>
          </div>
          <div><p style="color:#64748b;font-size:11px">TYPE</p><p style="font-weight:600">{{ supervisor.supervisor_type}}</p></div>
          <div><p style="color:#64748b;font-size:11px">STATUS</p><p><span :style="supervisor.is_active ? 'background:#dcfce7;color:#15803d;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:600' : 'background:#e2e8f0;color:#475569;padding:3px 10px;border-radius:999px;font-size:11px'">{{ supervisor.is_active ? 'Active':'Inactive' }}</span> <span v-if="supervisor.is_primary" style="background:#fef9c3;color:#854d0e;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:700;margin-left:6px">★ Primary</span></p></div>
          <div><p style="color:#64748b;font-size:11px">EFFECTIVE FROM</p><p style="font-weight:600">{{ supervisor.effective_from||'—' }}</p></div>
          <div><p style="color:#64748b;font-size:11px">EFFECTIVE TO</p><p style="font-weight:600">{{ supervisor.effective_to||'—' }}</p></div>
          <div style="grid-column:span 2 / span 2"><p style="color:#64748b;font-size:11px">REMARKS</p><p>{{ supervisor.remarks||'—' }}</p></div>
          <div><p style="color:#64748b;font-size:11px">CREATED</p><p>{{ supervisor.created_at}}</p></div>
        </div>
      </div>
      <div style="background:linear-gradient(135deg,#1e40af 0%,#06b6d4 100%);border-radius:10px;padding:12px 16px;color:white;font-size:13px">
        <b>Flexibility:</b> This assignment uses <b>{{ supervisor.supervisor_kind==='employee' ? 'Employee-as-Supervisor' : 'User-as-Supervisor' }}</b>. Change kind anytime from Edit.
      </div>
    </div>
  </AdminLayout>
</template>
