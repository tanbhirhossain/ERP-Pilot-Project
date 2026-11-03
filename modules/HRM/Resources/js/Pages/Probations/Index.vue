<script setup lang="ts">
import { Head, router, Link } from '@inertiajs/vue3';
import { ref, reactive } from 'vue';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import SearchableSelect from '../../../../../CORE/Resources/js/Components/SearchableSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { BadgeCent, Clock, AlertTriangle, CheckCircle, Timer } from 'lucide-vue-next';
const props = defineProps<{ probations: any; stats: any }>();
const showCreate = ref(false);
const form = reactive({ employee_id: null as any, probation_start: '', probation_end: '', remarks: '' });
function create(){ router.post(route('hrm.probations.store'), form, { onSuccess: ()=> showCreate.value=false }); }
function extend(id:number){ const d=prompt('New extended till (YYYY-MM-DD):'); if(d) router.post(route('hrm.probations.extend', id), { extended_till:d }); }
function confirm(id:number){ if(confirm('Confirm probation? Employee will become Permanent.')) router.post(route('hrm.probations.confirm', id)); }
function fail(id:number){ const r=prompt('Reason for fail (required):'); if(r) router.post(route('hrm.probations.fail', id), { remarks:r }); }
const statusTone=(s:string)=>({Active:'bg-blue-500 text-white',Extended:'bg-amber-500 text-white',Confirmed:'bg-emerald-500 text-white',Failed:'bg-red-500 text-white',Terminated:'bg-slate-600 text-white'}[s]||'bg-muted');
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'HRM',href:route('hrm.employees.index')},{title:'Probation & Confirmation'}]">
    <Head title="Probation & Confirmation — HRM" />
    <div class="max-w-7xl mx-auto space-y-6">
      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div><h1 class="text-2xl font-bold flex items-center gap-2"><BadgeCent class="size-6 text-primary"/> Probation & Confirmation</h1><p class="text-sm text-muted-foreground">Track every probation (Active → Extended → Confirmed/Failed), auto-promote to Permanent, flag overdue. Linked to employment_type.</p></div>
        <Button @click="showCreate=!showCreate" class="bg-primary"><Timer class="size-4 mr-2"/>{{ showCreate?'Close':'New Probation'}}</Button>
      </div>
      <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="bg-card border rounded-xl p-4 flex items-center gap-3"><div class="size-10 rounded-lg bg-blue-500/10 flex items-center justify-center"><Timer class="size-5 text-blue-600"/></div><div><p class="text-xs text-muted-foreground">Active</p><p class="text-xl font-bold">{{ stats.active }}</p></div></div>
        <div class="bg-card border rounded-xl p-4 flex items-center gap-3"><div class="size-10 rounded-lg bg-amber-500/10 flex items-center justify-center"><Clock class="size-5 text-amber-600"/></div><div><p class="text-xs text-muted-foreground">Due ≤7d</p><p class="text-xl font-bold text-amber-600">{{ stats.due_7d }}</p></div></div>
        <div class="bg-card border rounded-xl p-4 flex items-center gap-3"><div class="size-10 rounded-lg bg-red-500/10 flex items-center justify-center"><AlertTriangle class="size-5 text-red-600"/></div><div><p class="text-xs text-muted-foreground">Overdue</p><p class="text-xl font-bold text-red-600">{{ stats.overdue }}</p></div></div>
        <div class="bg-card border rounded-xl p-4 flex items-center gap-3"><div class="size-10 rounded-lg bg-emerald-500/10 flex items-center justify-center"><CheckCircle class="size-5 text-emerald-600"/></div><div><p class="text-xs text-muted-foreground">Total Records</p><p class="text-xl font-bold">{{ probations.total }}</p></div></div>
      </div>
      <div v-if="showCreate" class="bg-card border rounded-xl p-5 space-y-4">
        <h3 class="font-semibold">New Probation — usually 3 or 6 months from joining_date</h3>
        <div class="grid md:grid-cols-4 gap-4">
          <div class="space-y-1"><Label>Employee *</Label><SearchableSelect v-model="form.employee_id" api-url="/hrm/api/options/employees" placeholder="Select employee"/></div>
          <div class="space-y-1"><Label>Start *</Label><Input type="date" v-model="form.probation_start"/></div>
          <div class="space-y-1"><Label>End *</Label><Input type="date" v-model="form.probation_end"/></div>
          <div class="space-y-1"><Label>Remarks</Label><Input v-model="form.remarks" placeholder="3 months probation"/></div>
        </div>
        <div class="flex justify-end gap-2"><Button variant="outline" @click="showCreate=false">Cancel</Button><Button @click="create" class="bg-emerald-600 hover:bg-emerald-700">Create</Button></div>
      </div>
      <div class="bg-card border rounded-xl overflow-hidden">
        <div class="p-4 border-b flex items-center justify-between"><h3 class="font-semibold text-sm">All Probations — {{ probations.total }}</h3><span class="text-xs text-muted-foreground">Overdue = probation_end/extended_till &lt; today & still Active</span></div>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-muted/50 text-xs text-muted-foreground"><tr><th class="text-left p-3">Employee</th><th class="p-3">Period</th><th class="p-3">Status</th><th class="p-3">Days Left</th><th class="p-3 text-right">Actions</th></tr></thead>
            <tbody>
              <tr v-for="p in probations.data" :key="p.id" class="border-t hover:bg-muted/30">
                <td class="p-3"><div class="font-medium">{{ p.employee?.full_name || p.employee_id }}</div><div class="text-xs text-muted-foreground font-mono">{{ p.employee_id }}</div></td>
                <td class="p-3 text-center text-xs">{{ p.probation_start?.slice(0,10) }} → {{ (p.extended_till||p.probation_end)?.slice(0,10) }} <span v-if="p.extended_till" class="text-amber-600 font-semibold">(ext)</span></td>
                <td class="p-3 text-center"><span :class="['px-2 py-1 rounded-full text-xs font-medium', statusTone(p.status)]">{{ p.status }}</span></td>
                <td class="p-3 text-center text-xs" :class="p.status==='Active'||p.status==='Extended' ? (new Date(p.extended_till||p.probation_end) < new Date() ? 'text-red-600 font-bold' : 'text-emerald-600') : 'text-muted-foreground'">{{ p.status==='Active'||p.status==='Extended' ? Math.ceil((new Date(p.extended_till||p.probation_end).getTime()-Date.now())/86400000) + 'd' : '—' }}</td>
                <td class="p-3"><div class="flex justify-end gap-1 flex-wrap">
                  <Button v-if="p.status==='Active'||p.status==='Extended'" size="sm" variant="outline" class="h-7 text-xs" @click="extend(p.id)">Extend</Button>
                  <Button v-if="p.status==='Active'||p.status==='Extended'" size="sm" class="h-7 bg-emerald-600 hover:bg-emerald-700 text-xs" @click="confirm(p.id)">✓ Confirm</Button>
                  <Button v-if="p.status==='Active'||p.status==='Extended'" size="sm" variant="ghost" class="h-7 text-xs text-red-600" @click="fail(p.id)">Fail</Button>
                  <Link :href="route('hrm.employees.show', p.employee?.id)" v-if="p.employee?.id"><Button size="sm" variant="ghost" class="h-7 text-xs">View</Button></Link>
                </div></td>
              </tr>
              <tr v-if="!probations.data.length"><td colspan="5" class="p-8 text-center text-sm text-muted-foreground">No probations — create for every new Probation-type hire. 3–6 months typical.</td></tr>
            </tbody>
          </table>
        </div>
        <div v-if="probations.links" class="p-3 border-t flex gap-1 flex-wrap"><Link v-for="l in probations.links" :key="l.label" :href="l.url||'#'" v-html="l.label" :class="['px-3 py-1 rounded text-xs border', l.active?'bg-primary text-primary-foreground':'bg-card']" /></div>
      </div>
      <p class="text-xs text-muted-foreground">On confirm, employee <code>employment_type</code> flips Probation→Permanent and audit is written (<code>hrm_probation</code>). Extend keeps Active with new deadline; fail/terminate handled separately. Pair with Onboarding checklist for Day-1 tasks.</p>
    </div>
  </AdminLayout>
</template>
