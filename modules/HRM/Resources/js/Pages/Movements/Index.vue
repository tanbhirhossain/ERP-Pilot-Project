<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import { ArrowLeftRight } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
defineProps<{ movements:any }>();
const tone=(s:string)=>({Draft:'bg-slate-400 text-white',Pending:'bg-amber-500 text-white',Approved:'bg-emerald-500 text-white',Rejected:'bg-red-500 text-white',Cancelled:'bg-zinc-500 text-white'}[s]||'bg-muted');
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'HRM',href:route('hrm.employees.index')},{title:'Transfers & Promotions'}]">
    <Head title="Movements — HRM"/>
    <div class="max-w-7xl mx-auto space-y-6">
      <div class="flex items-center justify-between"><div><h1 class="text-2xl font-bold flex items-center gap-2"><ArrowLeftRight class="size-6 text-primary"/> Transfers / Promotions</h1><p class="text-sm text-muted-foreground">Every department/designation/grade/salary change is a versioned movement (Draft→Pending→Approved). Approved auto-updates employee master + salary history + optional PAYROLL increment.</p></div><Link :href="route('hrm.movements.create')"><Button class="bg-primary">+ New Movement</Button></Link></div>
      <div class="bg-card border rounded-xl overflow-hidden">
        <div class="p-4 border-b"><h3 class="font-semibold text-sm">Movements — {{ movements.total }} records</h3></div>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-muted/50 text-xs text-muted-foreground"><tr><th class="text-left p-3">Movement</th><th class="text-left p-3">Employee</th><th class="p-3">Type</th><th class="p-3">Effective</th><th class="p-3">Change</th><th class="p-3">Status</th></tr></thead>
            <tbody>
              <tr v-for="m in movements.data" :key="m.id" class="border-t hover:bg-muted/30">
                <td class="p-3 font-mono font-bold"><Link :href="route('hrm.movements.show', m.id)" class="text-primary hover:underline">{{ m.movement_no }}</Link></td>
                <td class="p-3"><div class="font-medium">{{ m.employee?.full_name||m.employee_id }}</div><div class="text-xs text-muted-foreground font-mono">{{ m.employee_id }}</div></td>
                <td class="p-3 text-center"><span class="px-2 py-1 rounded bg-muted text-xs font-medium">{{ m.movement_type }}</span></td>
                <td class="p-3 text-center text-xs">{{ m.effective_date?.slice(0,10) }}</td>
                <td class="p-3 text-center text-xs"><span v-if="m.from_department_id!==m.to_department_id || m.from_designation_id!==m.to_designation_id">{{ m.from_department?.name||m.from_designation?.name||'—' }} → {{ m.to_department?.name||m.to_designation?.name||'—' }}</span><span v-else class="text-muted-foreground">—</span><span v-if="m.increment_amount>0" class="ml-1 text-emerald-600 font-bold">+{{ m.increment_amount }}</span></td>
                <td class="p-3 text-center"><span :class="['px-2 py-1 rounded-full text-xs font-medium', tone(m.status)]">{{ m.status }}</span></td>
              </tr>
              <tr v-if="!movements.data.length"><td colspan="6" class="p-8 text-center text-sm text-muted-foreground">No movements — transfers, promotions, grade changes all start here.</td></tr>
            </tbody>
          </table>
        </div>
        <div v-if="movements.links" class="p-3 border-t flex gap-1 flex-wrap"><Link v-for="l in movements.links" :key="l.label" :href="l.url||'#'" v-html="l.label" :class="['px-3 py-1 rounded text-xs border', l.active?'bg-primary text-primary-foreground':'bg-card']"/></div>
      </div>
      <p class="text-xs text-muted-foreground">Statuses: Draft (editable) → Pending (await HR/Management) → Approved (applied) / Rejected / Cancelled. On approve: employee <code>department_id/designation_id</code> updated + <code>employee_salaries</code> row added + <code>salary_increments</code> audit row for PAYROLL traceability.</p>
    </div>
  </AdminLayout>
</template>
