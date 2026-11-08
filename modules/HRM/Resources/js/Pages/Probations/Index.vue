<script setup lang="ts">
import { Head, router, Link } from '@inertiajs/vue3';
import { ref, reactive } from 'vue';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import PageHeader from '../../../../../CORE/Resources/js/Components/PageHeader.vue';
import SearchableSelect from '../../../../../CORE/Resources/js/Components/SearchableSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { BadgeCent, Clock, AlertTriangle, CheckCircle, Timer } from 'lucide-vue-next';
import Pagination from '../../../../../CORE/Resources/js/Components/Pagination.vue';
import { confirmDialog, promptDialog } from '../../../../../CORE/Resources/js/Composables/useConfirm';
import { useToast } from '../../../../../CORE/Resources/js/Composables/useToast';
import StaticTable from '../../../../../CORE/Resources/js/Components/StaticTable.vue';
defineProps<{ probations: any; stats: any }>();
const showCreate = ref(false);
const form = reactive({ employee_id: null as any, probation_start: '', probation_end: '', remarks: '' });
function create(){ router.post(route('hrm.probations.store'), form, { onSuccess: ()=> showCreate.value=false }); }
const toast = useToast();
async function extend(id:number){
  const extendedTill = await promptDialog({ title: 'Extend probation', description: 'The probation end date moves to the new date.', inputLabel: 'New extended till', inputPlaceholder: 'YYYY-MM-DD', confirmLabel: 'Extend probation', danger: false });
  if(!extendedTill) return;
  router.post(route('hrm.probations.extend', id), { extended_till: extendedTill });
}
// Was `function confirm()`, which shadowed window.confirm: the call inside it resolved to itself,
// so clicking ✓ Confirm recursed until the stack overflowed and the probation was never confirmed.
async function confirmProbation(id:number){
  const proceed = await confirmDialog({ title: 'Confirm probation', description: 'The employee becomes Permanent.', confirmLabel: 'Confirm probation', danger: false });
  if(!proceed) return;
  router.post(route('hrm.probations.confirm', id));
}
async function fail(id:number){
  const reason = await promptDialog({ title: 'Fail probation', description: 'The reason is written to the audit trail.', inputLabel: 'Reason for fail', inputType: 'textarea', confirmLabel: 'Fail probation' });
  if(reason === null) return;
  if(reason.trim().length < 3){ toast.warning('A reason needs at least three characters.'); return; }
  router.post(route('hrm.probations.fail', id), { remarks: reason.trim() });
}
const statusTone=(s:string)=>({Active:'bg-blue-500 text-white',Extended:'bg-amber-500 text-white',Confirmed:'bg-emerald-500 text-white',Failed:'bg-red-500 text-white',Terminated:'bg-slate-600 text-white'}[s]||'bg-muted');
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'HRM' }, { title: 'Probation & Confirmation' }]">
    <Head title="Probation & Confirmation — HRM" />
    <div class="max-w-7xl mx-auto space-y-6">
      <PageHeader title="Probation & Confirmation" description="Track every probation (Active → Extended → Confirmed/Failed), auto-promote to Permanent, flag overdue. Linked to employment_type." :icon="BadgeCent">
          <template #actions>
              <Button @click="showCreate=!showCreate" class="bg-primary"><Timer class="size-4 mr-2"/>{{ showCreate?'Close':'New Probation'}}</Button>
          </template>
      </PageHeader>
      <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="bg-card border rounded-xl p-4 flex items-center gap-3"><div class="size-10 rounded-lg bg-blue-500/10 flex items-center justify-center"><Timer class="size-5 text-blue-600"/></div><div><p class="text-xs text-muted-foreground">Active</p><p class="text-xl font-bold">{{ stats.active }}</p></div></div>
        <div class="bg-card border rounded-xl p-4 flex items-center gap-3"><div class="size-10 rounded-lg bg-amber-500/10 flex items-center justify-center"><Clock class="size-5 text-amber-600"/></div><div><p class="text-xs text-muted-foreground">Due ≤7d</p><p class="text-xl font-bold text-amber-600">{{ stats.due_7d }}</p></div></div>
        <div class="bg-card border rounded-xl p-4 flex items-center gap-3"><div class="size-10 rounded-lg bg-red-500/10 flex items-center justify-center"><AlertTriangle class="size-5 text-red-600"/></div><div><p class="text-xs text-muted-foreground">Overdue</p><p class="text-xl font-bold text-red-600">{{ stats.overdue }}</p></div></div>
        <div class="bg-card border rounded-xl p-4 flex items-center gap-3"><div class="size-10 rounded-lg bg-emerald-500/10 flex items-center justify-center"><CheckCircle class="size-5 text-emerald-600"/></div><div><p class="text-xs text-muted-foreground">Total Records</p><p class="text-xl font-bold">{{ probations.total }}</p></div></div>
      </div>
      <div v-if="showCreate" class="bg-card border rounded-xl p-5 space-y-4">
        <h3 class="font-semibold">New Probation — usually 3 or 6 months from joining_date</h3>
        <div class="grid md:grid-cols-4 gap-4">
          <div class="space-y-1"><Label>Employee *</Label><SearchableSelect v-model="form.employee_id" endpoint="/hrm/api/options/employees" placeholder="Select employee"/></div>
          <div class="space-y-1"><Label>Start *</Label><Input type="date" v-model="form.probation_start"/></div>
          <div class="space-y-1"><Label>End *</Label><Input type="date" v-model="form.probation_end"/></div>
          <div class="space-y-1"><Label>Remarks</Label><Input v-model="form.remarks" placeholder="3 months probation"/></div>
        </div>
        <div class="flex justify-end gap-2"><Button variant="outline" @click="showCreate=false">Cancel</Button><Button @click="create" class="bg-emerald-600 hover:bg-emerald-700">Create</Button></div>
      </div>
      <div class="bg-card border rounded-xl overflow-hidden">
        <div class="p-4 border-b flex items-center justify-between"><h3 class="font-semibold text-sm">All Probations — {{ probations.total }}</h3><span class="text-xs text-muted-foreground">Overdue = probation_end/extended_till &lt; today & still Active</span></div>
        <StaticTable flush>
            <thead class="bg-muted/50 text-xs text-muted-foreground"><tr><th class="text-left p-3">Employee</th><th class="p-3">Period</th><th class="p-3">Status</th><th class="p-3">Days Left</th><th class="p-3 text-right">Actions</th></tr></thead>
            <tbody>
              <tr v-for="p in probations.data" :key="p.id" class="border-t hover:bg-muted/30">
                <td class="p-3"><div class="font-medium">{{ p.employee?.full_name || p.employee_id }}</div><div class="text-xs text-muted-foreground font-mono">{{ p.employee_id }}</div></td>
                <td class="p-3 text-center text-xs">{{ p.probation_start?.slice(0,10) }} → {{ (p.extended_till||p.probation_end)?.slice(0,10) }} <span v-if="p.extended_till" class="text-amber-600 font-semibold">(ext)</span></td>
                <td class="p-3 text-center"><span :class="['px-2 py-1 rounded-full text-xs font-medium', statusTone(p.status)]">{{ p.status }}</span></td>
                <td class="p-3 text-center text-xs" :class="p.status==='Active'||p.status==='Extended' ? (new Date(p.extended_till||p.probation_end) < new Date() ? 'text-red-600 font-bold' : 'text-emerald-600') : 'text-muted-foreground'">{{ p.status==='Active'||p.status==='Extended' ? Math.ceil((new Date(p.extended_till||p.probation_end).getTime()-Date.now())/86400000) + 'd' : '—' }}</td>
                <td class="p-3"><div class="flex justify-end gap-1 flex-wrap">
                  <Button v-if="p.status==='Active'||p.status==='Extended'" size="sm" variant="outline" class="h-7 text-xs" @click="extend(p.id)">Extend</Button>
                  <Button v-if="p.status==='Active'||p.status==='Extended'" size="sm" class="h-7 bg-emerald-600 hover:bg-emerald-700 text-xs" @click="confirmProbation(p.id)">✓ Confirm</Button>
                  <Button v-if="p.status==='Active'||p.status==='Extended'" size="sm" variant="ghost" class="h-7 text-xs text-red-600" @click="fail(p.id)">Fail</Button>
                  <Link :href="route('hrm.employees.show', p.employee?.id)" v-if="p.employee?.id"><Button size="sm" variant="ghost" class="h-7 text-xs">View</Button></Link>
                </div></td>
              </tr>
              <tr v-if="!probations.data.length"><td colspan="5" class="p-8 text-center text-sm text-muted-foreground">No probations — create for every new Probation-type hire. 3–6 months typical.</td></tr>
            </tbody>
          </StaticTable>
        <Pagination :links="probations.links" :total="probations.total" />
      </div>
      <p class="text-xs text-muted-foreground">On confirm, employee <code>employment_type</code> flips Probation→Permanent and audit is written (<code>hrm_probation</code>). Extend keeps Active with new deadline; fail/terminate handled separately. Pair with Onboarding checklist for Day-1 tasks.</p>
    </div>
  </AdminLayout>
</template>
