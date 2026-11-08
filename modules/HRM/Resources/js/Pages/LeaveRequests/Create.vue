<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import {ArrowLeft, CalendarDays, Package} from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import PageHeader from '../../../../../CORE/Resources/js/Components/PageHeader.vue';
import SearchableSelect from '../../../../../CORE/Resources/js/Components/SearchableSelect.vue';
const props=defineProps<{ employeeOptions:{value:string,label:string}[]; leaveTypeOptions:{value:number,label:string,color?:string}[]; leave?: any }>();
const isEdit=computed(()=> !!props.leave);
const form=useForm({ employee_id: props.leave?.employee_id ?? '', leave_type_id: props.leave?.leave_type_id ?? null as number|null, from_date: props.leave?.from_date ?? new Date().toISOString().slice(0,10), to_date: props.leave?.to_date ?? new Date().toISOString().slice(0,10), is_half_day: props.leave?.is_half_day ?? false, reason: props.leave?.reason ?? '' });
const liveBalance=ref<any>(null), balanceLoading=ref(false), balanceError=ref('');
let balanceController:AbortController|null=null;
watch(()=>[form.employee_id,form.leave_type_id,form.from_date,form.to_date,form.is_half_day],async()=>{
  liveBalance.value=null; balanceError.value='';
  if(!form.employee_id||!form.leave_type_id)return;
  balanceController?.abort(); balanceController=new AbortController(); balanceLoading.value=true;
  const params=new URLSearchParams({employee_id:String(form.employee_id),leave_type_id:String(form.leave_type_id),from_date:form.from_date,to_date:form.to_date,is_half_day:form.is_half_day?'1':'0'});if(props.leave?.id)params.set('ignore_leave_id',String(props.leave.id));
  try{const res=await fetch(`${route('hrm.leaves.balance')}?${params.toString()}`,{headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'},credentials:'same-origin',signal:balanceController.signal});const payload=await res.json();if(!res.ok)throw new Error(payload.message||'Could not load leave balance');liveBalance.value=payload;balanceError.value=payload.quote_error||'';}catch(e:any){if(e?.name!=='AbortError')balanceError.value=e?.message||'Could not load leave balance';}finally{balanceLoading.value=false;}
},{immediate:true});
const calcDays=computed(()=>{
  if(liveBalance.value?.requested_days!=null) return String(liveBalance.value.requested_days);
  if(!form.from_date||!form.to_date) return null;
  try{ const s=new Date(form.from_date), e=new Date(form.to_date); const d=Math.round((e.getTime()-s.getTime())/86400000)+1; if(form.is_half_day && d===1) return '0.5'; return String(d); }catch{ return null}
});
function submit(){ if(isEdit.value) form.put(route('hrm.leaves.update', props.leave.id)); else form.post(route('hrm.leaves.store')); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Leave' }, { title: 'Leaves', href: route('hrm.leaves.index') }, { title: isEdit ? 'Edit' : 'New' }]">
    <Head :title="isEdit ? 'Edit Leave' : 'New Leave Request'"/>
    <PageHeader :title="isEdit ? 'Edit Leave' : 'New Leave Request' " description="Complete the form — required *" :icon="Package">
    <div class="max-w-5xl space-y-4"><div class="flex items-center justify-between"><Link :href="route('hrm.leaves.index')"><Button variant="outline" size="sm"><ArrowLeft class="size-4"/> Back</Button></Link></div>
      <p class="text-sm text-muted-foreground">Leave rules vary by type: calendar or working days, accrual and pro-rating are configured in Leave Types. A printable application slip is generated after submission.</p>
      <form @submit.prevent="submit" class="bg-card border rounded-xl shadow-sm p-6 space-y-4">
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
          <div class="space-y-1"><Label>Employee *</Label><SearchableSelect v-model="form.employee_id" :options="employeeOptions" endpoint="/hrm/api/options/employees" placeholder="Select employee" :invalid="!!(form.errors.employee_id)"/><p v-if="form.errors.employee_id" class="text-xs text-destructive">{{ form.errors.employee_id }}</p></div>
          <div class="space-y-1"><Label>Leave Type *</Label><SearchableSelect v-model="form.leave_type_id" :options="leaveTypeOptions" placeholder="Select type" :invalid="!!(form.errors.leave_type_id)"/><p v-if="form.errors.leave_type_id" class="text-xs text-destructive">{{ form.errors.leave_type_id }}</p></div>
        </div>
        <div v-if="form.employee_id&&form.leave_type_id" class="rounded-lg border bg-indigo-50/60 p-4 space-y-2"><div class="flex items-center justify-between"><h3 class="font-semibold text-sm">Leave Balance Preview</h3><span v-if="balanceLoading" class="text-xs text-muted-foreground">Refreshing…</span></div><p v-if="balanceError" class="text-sm text-destructive">{{balanceError}}</p><div v-else-if="liveBalance" class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm"><div><p class="text-xs text-muted-foreground">Entitlement {{liveBalance.year}}</p><p class="font-mono font-semibold">{{liveBalance.entitlement}}d</p></div><div><p class="text-xs text-muted-foreground">Approved / Pending</p><p class="font-mono">{{liveBalance.approved}}d / {{liveBalance.pending}}d</p></div><div><p class="text-xs text-muted-foreground">Available before</p><p class="font-mono font-semibold">{{liveBalance.available}}d</p></div><div><p class="text-xs text-muted-foreground">After this request</p><p class="font-mono font-semibold">{{liveBalance.available_after_request??'—'}}d</p></div></div><p v-if="liveBalance?.calculation_mode" class="text-xs text-muted-foreground">{{liveBalance.calculation_mode.replace('_',' ')}} · {{liveBalance.accrual_mode}} accrual · {{liveBalance.proration_mode}} pro-rating <span v-if="liveBalance.requested_days!=null">· Request counts as {{liveBalance.requested_days}} day(s)</span></p></div>
        <div class="grid grid-cols-1 sm:grid-cols-3 2xl:grid-cols-4 gap-4">
          <div class="space-y-1"><Label>From Date *</Label><Input v-model="form.from_date" type="date"/><p v-if="form.errors.from_date" class="text-xs text-destructive">{{ form.errors.from_date }}</p></div>
          <div class="space-y-1"><Label>To Date *</Label><Input v-model="form.to_date" type="date"/><p v-if="form.errors.to_date" class="text-xs text-destructive">{{ form.errors.to_date }}</p></div>
          <div class="flex items-end gap-2 pb-1"><label class="flex items-center gap-2 text-sm"><input type="checkbox" v-model="form.is_half_day" class="rounded"/> Half day (0.5d)</label><span class="ml-auto font-mono text-sm font-semibold bg-muted px-3 py-2 rounded-lg">{{ calcDays ?? '—' }} days</span></div>
        </div>
        <div class="space-y-1"><Label>Reason</Label><Textarea v-model="form.reason" rows="3" placeholder="Reason for leave"/></div>
        <div class="flex justify-end gap-2 pt-2"><Link :href="route('hrm.leaves.index')"><Button type="button" variant="outline">Cancel</Button></Link><Button type="submit" :disabled="form.processing" class="bg-indigo-600 hover:bg-indigo-700"><CalendarDays class="size-4"/>{{ form.processing ? 'Saving...' : isEdit ? 'Update' : 'Submit Request' }}</Button></div>
      </form>
    </div>
      </PageHeader>
  </AdminLayout>
</template>
