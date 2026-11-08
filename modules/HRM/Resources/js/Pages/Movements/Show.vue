<script setup lang="ts">
import { Head, router, Link } from '@inertiajs/vue3';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import { Button } from '@/components/ui/button';
defineProps<{ movement:any }>();
function submit(id:number){ router.post(route('hrm.movements.submit', id)); }
function approve(id:number){ if(confirm('Approve? Employee master + salary history will be updated.')) router.post(route('hrm.movements.approve', id)); }
function reject(id:number){ const r=prompt('Reject reason:'); if(r) router.post(route('hrm.movements.reject', id), {reason:r}); }
function cancel(id:number){ if(confirm('Cancel this movement?')) router.post(route('hrm.movements.cancel', id)); }
const tone=(s:string)=>({Draft:'bg-slate-400 text-white',Pending:'bg-amber-500 text-white',Approved:'bg-emerald-500 text-white',Rejected:'bg-red-500 text-white',Cancelled:'bg-zinc-500 text-white'}[s]||'bg-muted');
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'HRM' }, { title: 'Movements', href: route('hrm.movements.index') }, { title: movement.movement_no }]">
    <Head :title="movement.movement_no"/>
    <div class="space-y-6">
      <div class="flex items-center justify-between"><h1 class="text-xl font-bold">{{ movement.movement_no }} <span :class="['ml-2 px-3 py-1 rounded-full text-xs font-bold', tone(movement.status)]">{{ movement.status }}</span></h1><Link :href="route('hrm.employees.show', movement.employee?.id)" v-if="movement.employee?.id" class="text-sm text-primary hover:underline">{{ movement.employee.full_name }} →</Link></div>
      <div class="bg-card border rounded-xl p-6 space-y-4 text-sm">
        <div class="grid md:grid-cols-2 gap-4">
          <div><span class="text-muted-foreground">Employee:</span> <b>{{ movement.employee?.full_name }} ({{ movement.employee_id }})</b></div>
          <div><span class="text-muted-foreground">Type:</span> <b>{{ movement.movement_type }}</b></div>
          <div><span class="text-muted-foreground">Effective:</span> {{ movement.effective_date?.slice(0,10) }}</div>
          <div><span class="text-muted-foreground">Increment:</span> <span class="font-bold text-emerald-600">{{ movement.increment_amount }} BDT</span> ({{ movement.from_salary }} → {{ movement.to_salary }})</div>
          <div><span class="text-muted-foreground">From:</span> {{ movement.from_department?.name||'—' }} / {{ movement.from_designation?.name||'—' }}</div>
          <div><span class="text-muted-foreground">To:</span> <b>{{ movement.to_department?.name||'—' }} / {{ movement.to_designation?.name||'—' }}</b></div>
          <div class="md:col-span-2"><span class="text-muted-foreground">Reason:</span> {{ movement.reason||'—' }}</div>
        </div>
        <div class="flex gap-2 pt-4 border-t flex-wrap">
          <Button v-if="movement.status==='Draft'" @click="submit(movement.id)" class="bg-amber-500 hover:bg-amber-600">Submit for Approval</Button>
          <Button v-if="movement.status==='Pending'" @click="approve(movement.id)" class="bg-emerald-600 hover:bg-emerald-700">✓ Approve & Apply</Button>
          <Button v-if="movement.status==='Pending'" variant="outline" @click="reject(movement.id)" class="text-red-600">Reject</Button>
          <Button v-if="['Draft','Pending'].includes(movement.status)" variant="ghost" @click="cancel(movement.id)">Cancel</Button>
        </div>
      </div>
      <p class="text-xs text-muted-foreground">Approved movements are immutable audit records. From-values are snapshots; to-values are the new truth. Salary change creates a new <code>employee_salaries</code> row (is_current) and a PAYROLL <code>salary_increments</code> audit for retro-pay.</p>
    </div>
  </AdminLayout>
</template>
