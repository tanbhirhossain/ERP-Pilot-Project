<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {ArrowLeft, Timer, Check, X, Clock3, User, CalendarDays, FileText} from 'lucide-vue-next';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import PageHeader from '../../../../../CORE/Resources/js/Components/PageHeader.vue';
const props=defineProps<{ overtime: { id: number; hours: number; source_attendance_hours: number; [key: string]: any }; canApprove: boolean }>();
const processing=ref(false);
const hours=ref(Number(props.overtime.hours));
function approve(){
  if (!Number.isFinite(hours.value) || hours.value <= 0 || hours.value > Number(props.overtime.source_attendance_hours) || hours.value > 24) {
    window.alert(`Approved hours must be positive and no more than the source attendance amount (${Number(props.overtime.source_attendance_hours).toFixed(2)} h).`);
    return;
  }
  processing.value=true;
  router.post(route('hrm.overtimes.approve', props.overtime.id),{hours:hours.value},{onFinish(){processing.value=false;}});
}
function reject(){
  const reason=window.prompt('Enter a rejection reason (required for the audit trail):');
  if(reason===null || reason.trim().length<3) return;
  processing.value=true;
  router.post(route('hrm.overtimes.reject', props.overtime.id),{reason:reason.trim()},{onFinish(){processing.value=false;}});
}
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'OT & ED' }, { title: 'Overtime Approval', href: route('hrm.overtimes.index') }, { title: `#${overtime.id}` }]">
    <Head :title="`Overtime #${overtime.id}`"/>
    <PageHeader title="Pages Detail" description="Employee" :icon="FileText">
    <div class="space-y-4">
      <div class="flex items-center justify-between">
        <h1 class="text-xl font-bold flex items-center gap-2"><Timer class="size-5"/> Overtime #{{ overtime.id }} <span class="px-2.5 py-0.5 rounded-full text-xs font-medium" :class="overtime.status==='Pending'?'bg-amber-100 text-amber-700': overtime.status==='Approved'?'bg-emerald-100 text-emerald-700': overtime.status==='Rejected'?'bg-red-100 text-red-700':'bg-muted'">{{ overtime.status }}</span></h1>
        <Link :href="route('hrm.overtimes.index')"><Button variant="outline" size="sm"><ArrowLeft class="size-4"/> Back</Button></Link>
      </div>
      <div class="bg-card border rounded-xl shadow-sm p-6 space-y-4">
        <div class="grid grid-cols-2 gap-4 text-sm">
          <div><p class="text-muted-foreground flex items-center gap-1"><User class="size-3"/> Employee</p><p class="font-semibold font-mono">{{ overtime.employee_id }} <span class="text-muted-foreground font-normal">{{ overtime.employee }}</span></p></div>
          <div><p class="text-muted-foreground flex items-center gap-1"><CalendarDays class="size-3"/> Date</p><p class="font-mono font-semibold">{{ overtime.overtime_date }}</p></div>
          <div><p class="text-muted-foreground">Type</p><p class="font-semibold"><span class="px-2 py-0.5 rounded-full text-xs border" :class="overtime.overtime_type==='Overtime'?'bg-blue-50 text-blue-700 border-blue-200': overtime.overtime_type==='Extra Duty'?'bg-violet-50 text-violet-700':'bg-orange-50 text-orange-700'">{{ overtime.overtime_type }}</span></p></div>
          <div><p class="text-muted-foreground">Shift</p><p class="font-semibold">{{ overtime.shift || '—' }}</p></div>
          <div><p class="text-muted-foreground flex items-center gap-1"><Clock3 class="size-3"/> Time</p><p class="font-mono font-semibold">{{ overtime.start_time?.slice(0,5) || '—' }} → {{ overtime.end_time?.slice(0,5) || '—' }} <span class="text-muted-foreground">{{ overtime.hours }}h requested</span></p><p class="text-xs text-muted-foreground">Attendance source: {{ Number(overtime.source_attendance_hours || 0).toFixed(2) }}h</p></div>
          <div><p class="text-muted-foreground">Status</p><p class="font-semibold">{{ overtime.status }} <span v-if="overtime.approver" class="text-muted-foreground font-normal">by {{ overtime.approver }}</span></p></div>
          <div class="col-span-2"><p class="text-muted-foreground">Reason</p><p class="whitespace-pre-wrap">{{ overtime.reason || '—' }}</p></div>
        </div>
        <div v-if="overtime.status==='Pending' && canApprove" class="flex flex-wrap items-end gap-2 pt-2 border-t">
          <label class="grid gap-1 text-xs text-muted-foreground">Approved hours
            <input v-model.number="hours" type="number" min="0.01" :max="Number(overtime.source_attendance_hours)" step="0.01" class="h-9 w-32 rounded-md border bg-background px-2 text-sm font-mono text-foreground" />
          </label>
          <Button @click="approve" :disabled="processing" class="bg-emerald-600 hover:bg-emerald-700"><Check class="size-4"/> Approve</Button>
          <Button variant="destructive" @click="reject" :disabled="processing"><X class="size-4"/> Reject</Button>
          <span class="ml-auto text-xs text-muted-foreground">Attendance-derived request</span>
        </div>
        <p v-else-if="overtime.status==='Pending'" class="text-xs text-muted-foreground border-t pt-2">You do not have permission to approve or reject this request.</p>
        <p v-else class="text-xs text-muted-foreground border-t pt-2">Decision {{ overtime.approved_at ? 'on '+overtime.approved_at : '' }} by {{ overtime.approver || '—' }}</p>
      </div>
    </div>
      </PageHeader>
  </AdminLayout>
</template>
