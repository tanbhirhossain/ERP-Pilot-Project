<script setup lang="ts">
import { Head, router} from '@inertiajs/vue3';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import { ShieldCheck } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import Pagination from '@core/Components/Pagination.vue';
import StaticTable from '@core/Components/StaticTable.vue';
defineProps<{ tasks:any }>();
function clear(id:number, status:string){ const remarks=prompt(`Remarks for ${status} (optional):`)||''; router.post(route('hrm.clearance.clear', id), {status, remarks}); }
const tone=(s:string)=>({Pending:'bg-amber-500 text-white',Cleared:'bg-emerald-500 text-white',Rejected:'bg-red-500 text-white',Waived:'bg-slate-500 text-white'}[s]||'bg-muted');
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'HRM' }, { title: 'Exit Clearance' }]">
    <Head title="Exit Clearance — HRM"/>
    <div class="max-w-7xl mx-auto space-y-6">
      <div><h1 class="text-2xl font-bold flex items-center gap-2"><ShieldCheck class="size-6 text-primary"/> Exit Clearance — Offboarding Gate</h1><p class="text-sm text-muted-foreground">When a resignation is <b>Approved</b>, HR auto-creates 10 clearance tasks (HR 2 + IT 2 + Admin 1 + Finance 2 + Payroll 2). All must be Cleared/Waived before PAYROLL settlement is released. Mirrors enterprise separation audit.</p></div>
      <div class="bg-card border rounded-xl overflow-hidden">
        <div class="p-4 border-b"><h3 class="font-semibold text-sm">All Clearance Tasks — {{ tasks.total }}</h3></div>
        <StaticTable flush>
            <thead class="bg-muted/50 text-xs text-muted-foreground"><tr><th class="text-left p-3">Employee</th><th class="p-3">Dept</th><th class="text-left p-3">Task</th><th class="p-3">Status</th><th class="p-3 text-right">Action</th></tr></thead>
            <tbody>
              <tr v-for="t in tasks.data" :key="t.id" class="border-t hover:bg-muted/30">
                <td class="p-3"><div class="font-medium">{{ t.employee?.full_name||t.employee_id }}</div><div class="text-xs text-muted-foreground font-mono">{{ t.employee_id }}</div></td>
                <td class="p-3 text-center"><span class="px-2 py-1 rounded bg-muted text-xs font-medium">{{ t.department }}</span></td>
                <td class="p-3">{{ t.task_name }}</td>
                <td class="p-3 text-center"><span :class="['px-2 py-1 rounded-full text-xs font-medium', tone(t.status)]">{{ t.status }}</span></td>
                <td class="p-3"><div class="flex justify-end gap-1">
                  <Button v-if="t.status==='Pending'" size="sm" class="h-7 bg-emerald-600 hover:bg-emerald-700 text-xs" @click="clear(t.id,'Cleared')">✓ Cleared</Button>
                  <Button v-if="t.status==='Pending'" size="sm" variant="ghost" class="h-7 text-xs" @click="clear(t.id,'Waived')">Waive</Button>
                  <Button v-if="t.status==='Pending'" size="sm" variant="ghost" class="h-7 text-xs text-red-600" @click="clear(t.id,'Rejected')">Reject</Button>
                </div></td>
              </tr>
              <tr v-if="!tasks.data.length"><td colspan="5" class="p-12 text-center"><p class="text-sm text-muted-foreground">No clearance tasks — approve a resignation to auto-generate.</p><p class="text-xs text-muted-foreground mt-1">Flow: Resignations → Approve → 10 tasks → each dept clears → Settlement gate passes.</p></td></tr>
            </tbody>
          </StaticTable>
        <Pagination :links="tasks.links" :total="tasks.total" />
      </div>
      <p class="text-xs text-muted-foreground">Gate logic: <code>ClearanceService::isFullyCleared(employee_id, resignationId)</code> checks no Pending/Rejected remain. PAYROLL settlement approve button should be disabled until this passes (enforce in next payroll iteration).</p>
    </div>
  </AdminLayout>
</template>
