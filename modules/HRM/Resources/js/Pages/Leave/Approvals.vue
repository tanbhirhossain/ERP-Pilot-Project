<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useToast } from '@core/Composables/useToast';
import {
  CalendarClock,
  Check,
  CheckCircle2,
  ClipboardList,
  Clock,
  TriangleAlert,
  X,
} from 'lucide-vue-next';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import EnterpriseCard from '@core/Components/EnterpriseCard.vue';
import EmptyState from '@core/Components/EmptyState.vue';
import ModuleNav from './ModuleNav.vue';
import { Button } from '@/components/ui/button';
import { formatDate, formatNumber } from '@core/Utils/format';

/**
 * The approval queue.
 *
 * Before this page, approving was a button in a table row on the list screen: no balance, no reason,
 * no history, and a rejection recorded no comment at all. A decision about somebody's leave is not a
 * row action, so it gets a page that shows what the decision costs before it is taken.
 *
 * The queue is ordered by start date, because the application that starts soonest is the one that
 * hurts most if it waits.
 */

interface QueueRow {
  id: number;
  employee_id: string;
  employee: string;
  department: string | null;
  leave_type: string | null;
  leave_type_code: string | null;
  leave_type_color: string | null;
  from_date: string | null;
  to_date: string | null;
  days: number;
  is_half_day: boolean;
  reason: string | null;
  status: string;
  created_at: string | null;
  waiting_days: number;
  starts_in_days: number;
}

const props = defineProps<{
  queue: { rows: QueueRow[]; total: number; days_at_stake: number; overdue: number; already_started: number };
  canApprove: boolean;
}>();

const toast = useToast();
const busyId = ref<number | null>(null);
const rejectingId = ref<number | null>(null);

const approveForm = useForm({ comments: '' });
const rejectForm = useForm({ comments: '' });

const days = (value: number): string => `${formatNumber(Math.round(value * 10) / 10)}d`;

const approve = (row: QueueRow): void => {
  busyId.value = row.id;
  approveForm.post(route('hrm.leaves.approve', row.id), {
    preserveScroll: true,
    onSuccess: () => toast.success(`Approved ${row.employee}'s ${row.leave_type} for ${days(row.days)}. Attendance now shows the leave.`),
    onError: () => toast.error('Could not approve — the application may no longer be pending.'),
    onFinish: () => (busyId.value = null),
  });
};

const startReject = (row: QueueRow): void => {
  rejectingId.value = row.id;
  rejectForm.reset();
};

const cancelReject = (): void => {
  rejectingId.value = null;
  rejectForm.reset();
};

const submitReject = (row: QueueRow): void => {
  if (rejectForm.comments.trim().length < 3) {
    toast.error('A rejection must say why. Add a short reason before rejecting.');
    return;
  }
  busyId.value = row.id;
  rejectForm.post(route('hrm.leaves.reject', row.id), {
    preserveScroll: true,
    onSuccess: () => {
      rejectingId.value = null;
      toast.success(`${row.employee}'s application was rejected and the reason is on its trail.`);
    },
    onError: () => toast.error('Could not reject this application.'),
    onFinish: () => (busyId.value = null),
  });
};

/** Triage: already started, waiting too long, then the rest. */
const alreadyStarted = computed(() => props.queue.rows.filter((r) => r.starts_in_days < 0));
const overdue = computed(() => props.queue.rows.filter((r) => r.starts_in_days >= 0 && r.waiting_days >= 3));
const rest = computed(() => props.queue.rows.filter((r) => r.starts_in_days >= 0 && r.waiting_days < 3));
</script>

<template>
  <AdminLayout :breadcrumbs="[{ title: 'Leave' }, { title: 'Approvals' }]">
    <Head title="Leave approvals" />

    <div class="space-y-4">
      <ModuleNav />

      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 class="flex items-center gap-2.5 text-xl font-bold tracking-tight">
            <span class="grid size-9 place-items-center rounded-xl bg-gradient-to-br from-primary to-primary/70 text-primary-foreground shadow-sm">
              <ClipboardList class="size-5" />
            </span>
            Approval queue
          </h1>
          <p class="mt-1 max-w-3xl text-sm text-muted-foreground">
            {{ formatNumber(queue.total) }}
            {{ queue.total === 1 ? 'application is' : 'applications are' }} waiting,
            worth {{ days(queue.days_at_stake) }} of leave. Approving writes the days onto the attendance
            calendar and draws them from the employee's balance; rejecting needs a reason.
          </p>
        </div>
      </div>

      <div v-if="queue.already_started > 0" class="flex items-start gap-3 rounded-xl border border-danger/30 bg-danger/[0.06] px-4 py-3">
        <TriangleAlert class="mt-0.5 size-4 shrink-0 text-danger" />
        <p class="text-[13px]">
          <span class="font-semibold">{{ queue.already_started }}</span>
          {{ queue.already_started === 1 ? 'application has' : 'applications have' }} already passed its start date.
          Approving now marks past attendance days as leave — check whether the employee was actually absent first.
        </p>
      </div>

      <EmptyState
        v-if="queue.rows.length === 0"
        :icon="CheckCircle2"
        title="The queue is empty"
        description="Every leave application has been decided. New applications appear here the moment they are submitted."
        action-label="See all applications"
        action-route="hrm.leaves.requests"
      />

      <template v-else>
        <EnterpriseCard
          v-if="alreadyStarted.length"
          title="Already started"
          description="The leave date has passed and nobody decided."
          :icon="Clock"
        >
          <ul class="divide-y">
            <li v-for="row in alreadyStarted" :key="row.id" class="py-3 first:pt-0 last:pb-0">
              <!-- row body -->
              <div class="flex flex-wrap items-start gap-3">
                <span class="mt-1 size-2 shrink-0 rounded-full" :style="{ backgroundColor: row.leave_type_color ?? '#94a3b8' }" />
                <div class="min-w-0 flex-1">
                  <p class="text-[13px] font-semibold">
                    {{ row.employee }}
                    <span class="font-normal text-muted-foreground">· {{ row.employee_id }}<template v-if="row.department"> · {{ row.department }}</template></span>
                  </p>
                  <p class="mt-0.5 text-xs text-muted-foreground">
                    {{ row.leave_type }} · {{ formatDate(row.from_date) }} → {{ formatDate(row.to_date) }} ·
                    <span class="font-semibold text-foreground">{{ days(row.days) }}</span>
                    <span v-if="row.is_half_day"> · half day</span>
                  </p>
                  <p v-if="row.reason" class="mt-1 text-xs italic text-muted-foreground">“{{ row.reason }}”</p>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                  <span class="rounded-full bg-muted px-2 py-0.5 text-[11px] tabular-nums">waiting {{ row.waiting_days }}d</span>
                  <Button size="sm" variant="ghost" class="h-7 px-2 text-[12px]" @click="router.visit(route('hrm.leaves.show', row.id))">Open</Button>
                  <Button size="sm" variant="outline" class="h-7 gap-1 px-2.5 text-[12px] text-danger" :disabled="busyId === row.id" @click="startReject(row)">
                    <X class="size-3" /> Reject
                  </Button>
                  <Button size="sm" class="h-7 gap-1 px-2.5 text-[12px]" :disabled="busyId === row.id || !canApprove" @click="approve(row)">
                    <Check class="size-3" /> Approve
                  </Button>
                </div>
              </div>
              <!-- reject reason -->
              <div v-if="rejectingId === row.id" class="mt-2 rounded-lg border border-danger/30 bg-danger/[0.04] p-3">
                <label :for="`reject-${row.id}`" class="text-[11px] font-semibold uppercase tracking-wide text-danger">Reason for rejecting (required)</label>
                <textarea
                  :id="`reject-${row.id}`"
                  v-model="rejectForm.comments"
                  rows="2"
                  class="mt-1 w-full rounded-md border bg-background px-3 py-2 text-sm"
                  placeholder="e.g. Sick leave certificate not provided; balance already exhausted for March."
                />
                <div class="mt-2 flex justify-end gap-2">
                  <Button size="sm" variant="ghost" @click="cancelReject">Cancel</Button>
                  <Button size="sm" variant="destructive" :disabled="busyId === row.id" @click="submitReject(row)">Record rejection</Button>
                </div>
              </div>
            </li>
          </ul>
        </EnterpriseCard>

        <EnterpriseCard
          v-if="overdue.length"
          title="Waiting three days or more"
          description="Long enough that the employee has had to plan around the answer."
          :icon="CalendarClock"
        >
          <ul class="divide-y">
            <li v-for="row in overdue" :key="row.id" class="py-3 first:pt-0 last:pb-0">
              <div class="flex flex-wrap items-start gap-3">
                <span class="mt-1 size-2 shrink-0 rounded-full" :style="{ backgroundColor: row.leave_type_color ?? '#94a3b8' }" />
                <div class="min-w-0 flex-1">
                  <p class="text-[13px] font-semibold">
                    {{ row.employee }}
                    <span class="font-normal text-muted-foreground">· {{ row.employee_id }}<template v-if="row.department"> · {{ row.department }}</template></span>
                  </p>
                  <p class="mt-0.5 text-xs text-muted-foreground">
                    {{ row.leave_type }} · {{ formatDate(row.from_date) }} → {{ formatDate(row.to_date) }} ·
                    <span class="font-semibold text-foreground">{{ days(row.days) }}</span>
                    <span v-if="row.is_half_day"> · half day</span>
                  </p>
                  <p v-if="row.reason" class="mt-1 text-xs italic text-muted-foreground">“{{ row.reason }}”</p>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                  <span class="rounded-full bg-warning/10 px-2 py-0.5 text-[11px] tabular-nums text-warning">waiting {{ row.waiting_days }}d</span>
                  <Button size="sm" variant="ghost" class="h-7 px-2 text-[12px]" @click="router.visit(route('hrm.leaves.show', row.id))">Open</Button>
                  <Button size="sm" variant="outline" class="h-7 gap-1 px-2.5 text-[12px] text-danger" :disabled="busyId === row.id" @click="startReject(row)">
                    <X class="size-3" /> Reject
                  </Button>
                  <Button size="sm" class="h-7 gap-1 px-2.5 text-[12px]" :disabled="busyId === row.id || !canApprove" @click="approve(row)">
                    <Check class="size-3" /> Approve
                  </Button>
                </div>
              </div>
              <div v-if="rejectingId === row.id" class="mt-2 rounded-lg border border-danger/30 bg-danger/[0.04] p-3">
                <label :for="`reject-${row.id}`" class="text-[11px] font-semibold uppercase tracking-wide text-danger">Reason for rejecting (required)</label>
                <textarea :id="`reject-${row.id}`" v-model="rejectForm.comments" rows="2" class="mt-1 w-full rounded-md border bg-background px-3 py-2 text-sm" placeholder="Why is this being rejected?" />
                <div class="mt-2 flex justify-end gap-2">
                  <Button size="sm" variant="ghost" @click="cancelReject">Cancel</Button>
                  <Button size="sm" variant="destructive" :disabled="busyId === row.id" @click="submitReject(row)">Record rejection</Button>
                </div>
              </div>
            </li>
          </ul>
        </EnterpriseCard>

        <EnterpriseCard v-if="rest.length" title="New requests" description="Submitted in the last three days." :icon="ClipboardList">
          <ul class="divide-y">
            <li v-for="row in rest" :key="row.id" class="py-3 first:pt-0 last:pb-0">
              <div class="flex flex-wrap items-start gap-3">
                <span class="mt-1 size-2 shrink-0 rounded-full" :style="{ backgroundColor: row.leave_type_color ?? '#94a3b8' }" />
                <div class="min-w-0 flex-1">
                  <p class="text-[13px] font-semibold">
                    {{ row.employee }}
                    <span class="font-normal text-muted-foreground">· {{ row.employee_id }}<template v-if="row.department"> · {{ row.department }}</template></span>
                  </p>
                  <p class="mt-0.5 text-xs text-muted-foreground">
                    {{ row.leave_type }} · {{ formatDate(row.from_date) }} → {{ formatDate(row.to_date) }} ·
                    <span class="font-semibold text-foreground">{{ days(row.days) }}</span>
                    <span v-if="row.is_half_day"> · half day</span>
                  </p>
                  <p v-if="row.reason" class="mt-1 text-xs italic text-muted-foreground">“{{ row.reason }}”</p>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                  <span class="rounded-full bg-muted px-2 py-0.5 text-[11px] tabular-nums">waiting {{ row.waiting_days }}d</span>
                  <Button size="sm" variant="ghost" class="h-7 px-2 text-[12px]" @click="router.visit(route('hrm.leaves.show', row.id))">Open</Button>
                  <Button size="sm" variant="outline" class="h-7 gap-1 px-2.5 text-[12px] text-danger" :disabled="busyId === row.id" @click="startReject(row)">
                    <X class="size-3" /> Reject
                  </Button>
                  <Button size="sm" class="h-7 gap-1 px-2.5 text-[12px]" :disabled="busyId === row.id || !canApprove" @click="approve(row)">
                    <Check class="size-3" /> Approve
                  </Button>
                </div>
              </div>
              <div v-if="rejectingId === row.id" class="mt-2 rounded-lg border border-danger/30 bg-danger/[0.04] p-3">
                <label :for="`reject-${row.id}`" class="text-[11px] font-semibold uppercase tracking-wide text-danger">Reason for rejecting (required)</label>
                <textarea :id="`reject-${row.id}`" v-model="rejectForm.comments" rows="2" class="mt-1 w-full rounded-md border bg-background px-3 py-2 text-sm" placeholder="Why is this being rejected?" />
                <div class="mt-2 flex justify-end gap-2">
                  <Button size="sm" variant="ghost" @click="cancelReject">Cancel</Button>
                  <Button size="sm" variant="destructive" :disabled="busyId === row.id" @click="submitReject(row)">Record rejection</Button>
                </div>
              </div>
            </li>
          </ul>
        </EnterpriseCard>
      </template>

      <p class="text-xs text-muted-foreground">
        Approving at the final stage re-checks the balance. If the employee no longer has the days — because the
        policy changed or other leave was approved first — the approval is refused and the application stays in
        the queue, instead of pushing the balance negative.
      </p>
    </div>
  </AdminLayout>
</template>
