<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import type { LeaveRequest } from '@hrm/types';
import {ArrowLeft, CalendarDays, Check, X, Clock, User, Printer, FileText} from 'lucide-vue-next';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import { printDocument } from '@core/Composables/usePrint';
import PageHeader from '@core/Components/PageHeader.vue';
import { useConfirm } from '@core/Composables/useConfirm';
const { confirm } = useConfirm();
const props=defineProps<{ leave:LeaveRequest; canApprove:boolean }>();
const processing=ref(false);
async function approve(){ processing.value=true; router.post(route('hrm.leaves.approve', props.leave.id),{},{onFinish(){processing.value=false;}}); }
async function reject(){ if(!await confirm({ description: 'Reject?', danger: true })) return; processing.value=true; router.post(route('hrm.leaves.reject', props.leave.id),{},{onFinish(){processing.value=false;}}); }
async function printSlip(){ printDocument({ portrait: true }); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Leave' }, { title: 'Leaves', href: route('hrm.leaves.index') }, { title: `#${leave.id}` }]">
    <Head :title="`Leave #${leave.id}`"/>
    <PageHeader title="Pages Detail" description="Employee" :icon="FileText">
    <div class="space-y-4 print-portrait" data-core-print>
      <div class="flex items-center justify-between"><h1 class="text-xl font-bold flex items-center gap-2"><CalendarDays class="size-5"/> Leave Application #{{ leave.id }} <span class="px-2.5 py-0.5 rounded-full text-xs font-medium" :class="leave.status==='Pending'?'bg-amber-100 text-amber-700': leave.status==='Approved'?'bg-emerald-100 text-emerald-700': leave.status==='Rejected'?'bg-red-100 text-red-700':'bg-muted'">{{ leave.status }}</span></h1><div class="no-print flex gap-2"><Button variant="outline" size="sm" @click="printSlip"><Printer class="size-4"/> Print Slip</Button><Link :href="route('hrm.leaves.index')"><Button variant="outline" size="sm"><ArrowLeft class="size-4"/> Back</Button></Link></div></div>
      <div class="bg-card border rounded-xl shadow-sm p-6 space-y-4">
        <div class="grid grid-cols-2 gap-4 text-sm">
          <div><p class="text-muted-foreground flex items-center gap-1"><User class="size-3"/> Employee</p><p class="font-semibold font-mono">{{ leave.employee_id }} <span class="text-muted-foreground font-normal">{{ leave.employee }}</span></p></div>
          <div><p class="text-muted-foreground">Leave Type</p><p class="font-semibold">{{ leave.leave_type || '—' }}</p></div>
          <div><p class="text-muted-foreground flex items-center gap-1"><Clock class="size-3"/> Period</p><p class="font-mono font-semibold">{{ leave.from_date }} → {{ leave.to_date }} <span class="text-muted-foreground">{{ leave.days }}d</span> <span v-if="leave.is_half_day" class="bg-amber-100 text-amber-700 px-1.5 py-0.5 rounded text-xs">½ day</span></p></div>
          <div><p class="text-muted-foreground">Status</p><p class="font-semibold">{{ leave.status }} <span v-if="leave.next_approval_stage" class="text-muted-foreground font-normal">· Next: {{leave.next_approval_stage}}</span><span v-if="leave.approver" class="text-muted-foreground font-normal">by {{ leave.approver }}</span></p></div>
          <div class="col-span-2"><p class="text-muted-foreground">Reason</p><p class="whitespace-pre-wrap">{{ leave.reason || '—' }}</p></div>
        </div>
        <div v-if="leave.status==='Pending'" class="flex gap-2 pt-2 border-t">
          <Button v-if="canApprove" @click="approve" :disabled="processing" class="bg-emerald-600 hover:bg-emerald-700"><Check class="size-4"/> Approve</Button>
          <Button v-if="canApprove" variant="destructive" @click="reject" :disabled="processing"><X class="size-4"/> Reject</Button>
          <Link :href="route('hrm.leaves.edit', leave.id)" class="ml-auto"><Button variant="outline">Edit</Button></Link>
        </div>
        <p v-else class="text-xs text-muted-foreground border-t pt-2">Decision {{ leave.approved_at ? 'on '+leave.approved_at : '' }} by {{ leave.approver || '—' }}</p>
      </div>
      <div class="bg-indigo-50/70 border border-indigo-200 rounded-xl p-5"><h2 class="font-semibold mb-3">Leave balance statement · {{leave.leave_balance.year}}</h2><div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm"><div><p class="text-xs text-muted-foreground">Annual entitlement</p><p class="font-mono font-semibold">{{leave.leave_balance.entitlement}}d</p></div><div><p class="text-xs text-muted-foreground">Used (approved)</p><p class="font-mono">{{leave.leave_balance.approved}}d</p></div><div><p class="text-xs text-muted-foreground">Available before application</p><p class="font-mono">{{(Number(leave.leave_balance.available)+Number(leave.days)).toFixed(2)}}d</p></div><div><p class="text-xs text-muted-foreground">Balance after this request</p><p class="font-mono font-semibold">{{leave.leave_balance.available}}d</p></div></div><p class="mt-3 text-xs text-muted-foreground">Request is {{leave.status.toLowerCase()}} for {{leave.days}} day(s). Calculation: {{leave.leave_balance.calculation_mode.replace('_',' ')}} · {{leave.leave_balance.accrual_mode}} accrual · {{leave.leave_balance.proration_mode}} pro-rating. Pending applications reserve available balance.</p></div>
      <div v-if="leave.approval_history?.length" class="bg-card border rounded-xl shadow-sm p-5"><h2 class="font-semibold mb-3">Approval history</h2><ol class="space-y-3"><li v-for="item in leave.approval_history" :key="item.stage_index" class="flex gap-3 text-sm"><span class="mt-0.5 size-6 rounded-full bg-emerald-100 text-emerald-700 grid place-items-center">{{item.stage_index}}</span><div><p class="font-medium">{{item.stage}} — {{item.decision}} <span class="font-normal text-muted-foreground">by {{item.actor||'—'}}</span></p><p v-if="item.decided_at" class="text-xs text-muted-foreground">{{item.decided_at}}</p><p v-if="item.comments" class="text-xs">{{item.comments}}</p></div></li></ol></div>
    </div>
      </PageHeader>
  </AdminLayout>
</template>
