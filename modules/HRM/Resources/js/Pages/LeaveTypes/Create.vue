<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import {ArrowLeft, Package} from 'lucide-vue-next';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import { useUnsavedGuard } from '@core/Composables/useUnsavedGuard';
const props=defineProps<{ leaveType?: any; policy?: any }>();
const isEdit=computed(()=> !!props.leaveType);
const form=useForm({ name: props.leaveType?.name ?? '', code: props.leaveType?.code ?? '', days_per_year: props.leaveType?.days_per_year ?? 12, is_paid: props.leaveType?.is_paid ?? true, color: props.leaveType?.color ?? '#6366f1', description: props.leaveType?.description ?? '', is_active: props.leaveType?.is_active ?? true, day_count_mode:props.policy?.day_count_mode??'calendar_days', accrual_mode:props.policy?.accrual_mode??'annual', proration_mode:props.policy?.proration_mode??'none', working_days_csv:props.policy?.working_days_csv??'0,1,2,3,4', approval_steps_text:props.policy?.approval_steps_text??'HR' });
useUnsavedGuard(() => form.isDirty);
function submit(){ if(isEdit.value) form.put(route('hrm.leave-types.update', props.leaveType.id)); else form.post(route('hrm.leave-types.store')); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Leave' }, { title: 'Leave Types', href: route('hrm.leave-types.index') }, { title: isEdit ? 'Edit' : 'New' }]">
    <Head :title="isEdit ? 'Edit Leave Type' : 'New Leave Type'"/>
    <PageHeader :title="isEdit ? 'Edit Leave Type' : 'New Leave Type' " description="Complete the form — required *" :icon="Package">
    <div class="space-y-4"><div class="flex items-center justify-between"><Link :href="route('hrm.leave-types.index')"><Button variant="outline" size="sm"><ArrowLeft class="size-4"/> Back</Button></Link></div>
      <form @submit.prevent="submit" class="bg-card border rounded-xl shadow-sm p-6 space-y-4">
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
          <div class="space-y-1"><Label>Name *</Label><Input v-model="form.name" placeholder="e.g. Casual Leave"/><p v-if="form.errors.name" class="text-xs text-destructive">{{ form.errors.name }}</p></div>
          <div class="space-y-1"><Label>Code</Label><Input v-model="form.code" placeholder="CL, SL, EL"/><p v-if="form.errors.code" class="text-xs text-destructive">{{ form.errors.code }}</p></div>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-3 2xl:grid-cols-4 gap-4">
          <div class="space-y-1"><Label>Days / Year *</Label><Input v-model.number="form.days_per_year" type="number" min="0" max="365"/></div>
          <div class="space-y-1"><Label>Paid?</Label><select v-model="form.is_paid" class="w-full rounded-md border bg-background px-3 py-2 text-sm"><option :value="true">Paid</option><option :value="false">Unpaid</option></select></div>
          <div class="space-y-1"><Label>Color</Label><Input v-model="form.color" type="color" class="h-10 p-1"/></div>
        </div>
        <div class="rounded-lg border bg-muted/20 p-4 space-y-3"><div><h2 class="font-semibold">Balance & accrual policy</h2><p class="text-xs text-muted-foreground">Choose both supported day-count bases and whether entitlement accrues annually or monthly.</p></div><div class="grid sm:grid-cols-3 2xl:grid-cols-4 gap-3"><div class="space-y-1"><Label>Day counting</Label><select v-model="form.day_count_mode" class="h-10 w-full rounded-md border bg-background px-3 text-sm"><option value="calendar_days">Calendar days</option><option value="working_days">Working days (holidays excluded)</option></select></div><div class="space-y-1"><Label>Accrual</Label><select v-model="form.accrual_mode" class="h-10 w-full rounded-md border bg-background px-3 text-sm"><option value="annual">Annual grant</option><option value="monthly">Monthly accrual</option></select></div><div class="space-y-1"><Label>Pro-rating</Label><select v-model="form.proration_mode" class="h-10 w-full rounded-md border bg-background px-3 text-sm"><option value="none">No pro-rating</option><option value="monthly">By remaining service months</option><option value="daily">By active days in year</option></select></div><div class="space-y-1"><Label>Working weekdays (0=Sun … 6=Sat)</Label><Input v-model="form.working_days_csv" placeholder="0,1,2,3,4"/><p class="text-xs text-muted-foreground">Used only for working-day leave types; defaults Sun–Thu.</p></div><div class="space-y-1 sm:col-span-2"><Label>Sequential approval stages</Label><Input v-model="form.approval_steps_text" placeholder="HR"/><p class="text-xs text-muted-foreground">Comma-separated labels. Use “HR” for today's single-stage workflow; add more stages when needed.</p></div></div></div>
        <div class="space-y-1"><Label>Description</Label><Textarea v-model="form.description" rows="2" placeholder="Policy note"/><p v-if="form.errors.description" class="text-xs text-destructive">{{ form.errors.description }}</p></div>
        <div class="flex items-center gap-2"><input type="checkbox" v-model="form.is_active" id="active" class="rounded"/><Label for="active">Active</Label></div>
        <div class="flex justify-end gap-2 pt-2"><Link :href="route('hrm.leave-types.index')"><Button type="button" variant="outline">Cancel</Button></Link><Button type="submit" :disabled="form.processing" class="bg-indigo-600 hover:bg-indigo-700">{{ form.processing ? 'Saving...' : isEdit ? 'Update' : 'Create' }}</Button></div>
      </form>
    </div>
      </PageHeader>
  </AdminLayout>
</template>
