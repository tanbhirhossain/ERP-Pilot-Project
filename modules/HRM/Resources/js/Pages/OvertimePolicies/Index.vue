<script setup lang="ts">
import { Head, router, Link } from '@inertiajs/vue3';
import { ref, reactive } from 'vue';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Timer } from 'lucide-vue-next';
defineProps<{ policies:any }>();
const showCreate=ref(false);
const form=reactive({name:'Standard OT Policy 2026',employment_type:'All',max_ot_hours_daily:3,max_ot_hours_monthly:36,ot_rate_multiplier:1,holiday_ot_rate:2,extra_duty_rate:1,requires_approval:true,approval_levels:1});
function create(){ router.post(route('hrm.overtime-policies.store'), form as any, {onSuccess:()=> showCreate.value=false}); }
function toggle(id:number){ router.post(route('hrm.overtime-policies.toggle', id)); }
function seed(){ router.post(route('hrm.overtime-policies.seed')); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'Attendance'},{title:'OT Policies'}]">
    <Head title="Overtime Policies — HRM"/>
    <div class="max-w-6xl mx-auto space-y-6">
      <div class="flex items-center justify-between"><div><h1 class="text-2xl font-bold flex items-center gap-2"><Timer class="size-6 text-primary"/> Overtime Policies</h1><p class="text-sm text-muted-foreground">Caps (daily/monthly), multipliers (normal/holiday/extra duty), approval tiers. Guard-rail before <code>roster_overtimes</code> can be approved and paid.</p></div><div class="flex gap-2"><Button variant="outline" @click="seed">Seed Default</Button><Button @click="showCreate=!showCreate" class="bg-primary">{{ showCreate?'Close':'+ New Policy' }}</Button></div></div>
      <div v-if="showCreate" class="bg-card border rounded-xl p-5 space-y-4">
        <div class="grid md:grid-cols-3 gap-4">
          <div class="space-y-1"><Label>Name *</Label><Input v-model="form.name"/></div>
          <div class="space-y-1"><Label>Employment Type</Label><Input v-model="form.employment_type" placeholder="All / Permanent"/></div>
          <div class="space-y-1"><Label>Max Daily (h)</Label><Input type="number" v-model.number="form.max_ot_hours_daily" step="0.5"/></div>
          <div class="space-y-1"><Label>Max Monthly (h)</Label><Input type="number" v-model.number="form.max_ot_hours_monthly"/></div>
          <div class="space-y-1"><Label>OT Rate ×</Label><Input type="number" v-model.number="form.ot_rate_multiplier" step="0.25"/></div>
          <div class="space-y-1"><Label>Holiday Rate ×</Label><Input type="number" v-model.number="form.holiday_ot_rate" step="0.25"/></div>
          <div class="space-y-1"><Label>Extra Duty Rate ×</Label><Input type="number" v-model.number="form.extra_duty_rate" step="0.25"/></div>
        </div>
        <div class="flex justify-end"><Button @click="create" class="bg-emerald-600 hover:bg-emerald-700">Create</Button></div>
      </div>
      <div class="bg-card border rounded-xl overflow-hidden">
        <div class="p-4 border-b"><h3 class="font-semibold text-sm">Policies — {{ policies.total }}</h3></div>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-muted/50 text-xs text-muted-foreground"><tr><th class="text-left p-3">Policy</th><th class="p-3">Type</th><th class="p-3">Caps D/M</th><th class="p-3">Rates (OT/Hol/ED)</th><th class="p-3">Approval</th><th class="p-3">Active</th></tr></thead>
            <tbody>
              <tr v-for="p in policies.data" :key="p.id" class="border-t hover:bg-muted/30">
                <td class="p-3 font-medium">{{ p.name }}</td>
                <td class="p-3 text-center"><span class="px-2 py-1 rounded bg-muted text-xs">{{ p.employment_type }}</span></td>
                <td class="p-3 text-center text-xs">{{ p.max_ot_hours_daily }}h / {{ p.max_ot_hours_monthly }}h</td>
                <td class="p-3 text-center text-xs font-mono">{{ p.ot_rate_multiplier }}× / {{ p.holiday_ot_rate }}× / {{ p.extra_duty_rate }}×</td>
                <td class="p-3 text-center text-xs">{{ p.requires_approval ? `Yes (L${p.approval_levels})` : 'No' }}</td>
                <td class="p-3 text-center"><button @click="toggle(p.id)" :class="['px-3 py-1 rounded-full text-xs font-bold', p.is_active?'bg-emerald-500 text-white':'bg-zinc-400 text-white']">{{ p.is_active?'Active':'Inactive' }}</button></td>
              </tr>
              <tr v-if="!policies.data.length"><td colspan="6" class="p-8 text-center text-sm text-muted-foreground">No OT policies — seed default (3h/d, 36h/m, 1× normal, 2× holiday) to start.</td></tr>
            </tbody>
          </table>
        </div>
        <div v-if="policies.links" class="p-3 border-t flex gap-1 flex-wrap"><Link v-for="l in policies.links" :key="l.label" :href="l.url||'#'" v-html="l.label" :class="['px-3 py-1 rounded text-xs border', l.active?'bg-primary text-primary-foreground':'bg-card']"/></div>
      </div>
      <p class="text-xs text-muted-foreground">Enforcement: before approving OT in <code>RosterOvertimeController::approve</code>, service checks daily/monthly caps from active policy; violation blocked with reason. Future: payroll cutlist uses multipliers to compute <code>overtime_pay = hours × hourlyRate × multiplier</code>.</p>
    </div>
  </AdminLayout>
</template>
