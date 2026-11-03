<script setup lang="ts">
import { Head, router, Link } from '@inertiajs/vue3';
import { ref, reactive } from 'vue';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import SearchableSelect from '../../../../../CORE/Resources/js/Components/SearchableSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { FileClock } from 'lucide-vue-next';
defineProps<{ regularizations:any }>();
const showCreate=ref(false);
const form=reactive({employee_id:null as any, duty_date:'', regularization_type:'Missing In', assigned_shift_id:null as any, requested_in_time:'', requested_out_time:'', reason:''});
const types=['Missing In','Missing Out','Wrong Shift','Late Correction','Early Out Correction','On Duty','Work From Home','Remote Punch'];
function submit(){ router.post(route('hrm.regularizations.store'), form as any, {onSuccess:()=> showCreate.value=false}); }
function review(id:number, d:string){ const r=prompt(`Remarks for ${d}:`)||''; router.post(route('hrm.regularizations.review', id), {decision:d, review_remarks:r}); }
const tone=(s:string)=>({Pending:'bg-amber-500 text-white',Approved:'bg-emerald-500 text-white',Rejected:'bg-red-500 text-white',Cancelled:'bg-zinc-500 text-white'}[s]||'bg-muted');
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'Attendance',href:route('hrm.attendance.logs.index')},{title:'Regularizations'}]">
    <Head title="Attendance Regularizations — HRM"/>
    <div class="max-w-7xl mx-auto space-y-6">
      <div class="flex items-center justify-between"><div><h1 class="text-2xl font-bold flex items-center gap-2"><FileClock class="size-6 text-primary"/> Attendance Regularization</h1><p class="text-sm text-muted-foreground">Employee disputes Missing In/Out, Wrong Shift → Manager/HR approves → daily record patched + marked regularized (no more manual DB edits).</p></div><Button @click="showCreate=!showCreate" class="bg-primary">{{ showCreate?'Close':'+ Request Correction' }}</Button></div>
      <div v-if="showCreate" class="bg-card border rounded-xl p-5 space-y-4">
        <h3 class="font-semibold">New Regularization Request</h3>
        <div class="grid md:grid-cols-3 gap-4">
          <div class="space-y-1"><Label>Employee *</Label><SearchableSelect v-model="form.employee_id" api-url="/hrm/api/options/employees" placeholder="Employee"/></div>
          <div class="space-y-1"><Label>Duty Date *</Label><Input type="date" v-model="form.duty_date"/></div>
          <div class="space-y-1"><Label>Type *</Label><select v-model="form.regularization_type" class="h-9 w-full rounded-md border bg-background px-3 text-sm"><option v-for="t in types" :key="t" :value="t">{{ t }}</option></select></div>
          <div class="space-y-1"><Label>Shift (if Wrong Shift)</Label><SearchableSelect v-model="form.assigned_shift_id" api-url="/hrm/api/options/shifts" placeholder="Roster shift"/></div>
          <div class="space-y-1"><Label>Requested In (HH:mm)</Label><Input v-model="form.requested_in_time" placeholder="09:00"/></div>
          <div class="space-y-1"><Label>Requested Out (HH:mm)</Label><Input v-model="form.requested_out_time" placeholder="18:00"/></div>
          <div class="md:col-span-3 space-y-1"><Label>Reason *</Label><Input v-model="form.reason" placeholder="Biometric malfunction / on-duty visit"/></div>
        </div>
        <div class="flex justify-end"><Button @click="submit" class="bg-emerald-600 hover:bg-emerald-700">Submit for Approval</Button></div>
      </div>
      <div class="bg-card border rounded-xl overflow-hidden">
        <div class="p-4 border-b"><h3 class="font-semibold text-sm">Requests — {{ regularizations.total }}</h3></div>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-muted/50 text-xs text-muted-foreground"><tr><th class="text-left p-3">Reg No</th><th class="text-left p-3">Employee</th><th class="text-left p-3">Date/Type</th><th class="p-3">Requested</th><th class="p-3">Status</th><th class="p-3 text-right">Action</th></tr></thead>
            <tbody>
              <tr v-for="r in regularizations.data" :key="r.id" class="border-t hover:bg-muted/30">
                <td class="p-3 font-mono font-bold text-xs">{{ r.regularization_no }}</td>
                <td class="p-3"><div class="font-medium text-sm">{{ r.employee?.full_name||r.employee_id }}</div><div class="text-xs text-muted-foreground font-mono">{{ r.employee_id }}</div></td>
                <td class="p-3"><div class="text-xs font-medium">{{ r.duty_date?.slice(0,10) }}</div><div class="text-xs"><span class="px-2 py-0.5 rounded bg-muted">{{ r.regularization_type }}</span></div></td>
                <td class="p-3 text-center text-xs">{{ r.requested_in_time||'—' }} → {{ r.requested_out_time||'—' }}</td>
                <td class="p-3 text-center"><span :class="['px-2 py-1 rounded-full text-xs font-medium', tone(r.status)]">{{ r.status }}</span></td>
                <td class="p-3"><div class="flex justify-end gap-1">
                  <Button v-if="r.status==='Pending'" size="sm" class="h-7 bg-emerald-600 hover:bg-emerald-700 text-xs" @click="review(r.id,'Approved')">Approve</Button>
                  <Button v-if="r.status==='Pending'" size="sm" variant="ghost" class="h-7 text-xs text-red-600" @click="review(r.id,'Rejected')">Reject</Button>
                </div></td>
              </tr>
              <tr v-if="!regularizations.data.length"><td colspan="6" class="p-8 text-center text-sm text-muted-foreground">No regularization requests — employee disputes go here instead of DB manual fix.</td></tr>
            </tbody>
          </table>
        </div>
        <div v-if="regularizations.links" class="p-3 border-t flex gap-1 flex-wrap"><Link v-for="l in regularizations.links" :key="l.label" :href="l.url||'#'" v-html="l.label" :class="['px-3 py-1 rounded text-xs border', l.active?'bg-primary text-primary-foreground':'bg-card']"/></div>
      </div>
      <p class="text-xs text-muted-foreground">On <b>Approved</b>, <code>attendance_dailies</code> is patched: punch times, <code>status=Present</code>, <code>is_regularized=true</code> + audit. Prevents payroll disputes and keeps cutlist clean.</p>
    </div>
  </AdminLayout>
</template>
