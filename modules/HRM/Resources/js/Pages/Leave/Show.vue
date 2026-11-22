<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useToast } from '@core/Composables/useToast';
import {
  ArrowLeft,
  CalendarCheck2,
  CalendarDays,
  Check,
  CircleCheck,
  CircleDot,
  History,
  Info,
  Pencil,
  Printer,
  Trash2,
  TriangleAlert,
  X,
} from 'lucide-vue-next';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import EnterpriseCard from '@core/Components/EnterpriseCard.vue';
import ModuleNav from './ModuleNav.vue';
import { Button } from '@/components/ui/button';
import { formatDate, formatDateTime, formatNumber } from '@core/Utils/format';
import { printDocument } from '@core/Composables/usePrint';
import { useReportPdf } from '@core/Composables/useReportPdf';
import { confirmDialog } from '@core/Composables/useConfirm';

/**
 * One leave application.
 *
 * The old detail page said "Pages Detail / Employee" in its header — a copy-pasted placeholder — and
 * printed the decision date as a raw ISO string under an approver of "—". It showed the balance as
 * four numbers and never said whether the application was still affordable, nor who approved it, nor
 * on what grounds a rejection stood.
 *
 * This page is the one an approver reads before deciding: the request, the balance position before and
 * after, the charged dates, the stage the workflow is at, and the whole trail of who decided what.
 */

interface DateCell { date: string; weekday: number; excluded: 'weekend' | 'holiday' | null }

interface Balance {
  leave_type: string;
  code: string | null;
  color: string | null;
  is_paid: boolean;
  year: number;
  opening: number;
  entitlement: number;
  carry_forward: number;
  approved: number;
  pending: number;
  available: number;
  total: number;
  frozen: boolean;
  day_count_mode: string;
  accrual_mode: string;
  proration_mode: string;
  approval_steps: string[];
}

interface Detail {
  request: {
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
    approved_at: string | null;
  };
  balance: Balance;
  previous_year_balance: Balance;
  history: Array<{ stage: string | null; stage_index: number; decision: string; actor: string | null; comments: string | null; decided_at: string | null }>;
  next_stage: string | null;
  stages: string[];
  calendar: { days: number; chargeable: number; excluded: number; dates: DateCell[]; day_count_mode: string } | null;
  position: { available_now: number; after_approval: number; sufficient: boolean; shortfall: number; reserved: number };
}

const props = defineProps<{ detail: Detail; canApprove: boolean; canManage: boolean }>();

const toast = useToast();
const rejecting = ref(false);
const busy = ref(false);

// Days of this type taken by someone else, so the balance line can say which part is this application.
const otherApproved = computed(() => Math.max(0, Number(props.detail.balance.approved) - Number(props.detail.request.days)));

const approveForm = useForm({ comments: '' });
const rejectForm = useForm({ comments: '' });

const request = computed(() => props.detail.request);
const pending = computed(() => request.value.status === 'Pending');
const weekdayName = (i: number): string => ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'][i] ?? '';

const statusTone = computed(() => ({
  Approved: 'bg-success/10 text-success ring-success/25',
  Pending: 'bg-warning/10 text-warning ring-warning/25',
  Rejected: 'bg-danger/10 text-danger ring-danger/25',
}[request.value.status] ?? 'bg-muted text-muted-foreground ring-border'));

const approve = (): void => {
  busy.value = true;
  approveForm.post(route('hrm.leaves.approve', request.value.id), {
    preserveScroll: true,
    onSuccess: () => toast.success('Approved. The attendance calendar now shows these days as leave.'),
    onError: () => toast.error('Could not approve this application.'),
    onFinish: () => (busy.value = false),
  });
};

const submitReject = (): void => {
  if (rejectForm.comments.trim().length < 3) {
    toast.error('A rejection must say why — the reason is recorded on the application trail.');
    return;
  }
  busy.value = true;
  rejectForm.post(route('hrm.leaves.reject', request.value.id), {
    preserveScroll: true,
    onSuccess: () => {
      rejecting.value = false;
      toast.success('Rejected. The reason is on the trail; any attendance days it had marked were released.');
    },
    onError: () => toast.error('Could not reject this application.'),
    onFinish: () => (busy.value = false),
  });
};

/** Deletion says what it will actually undo, which is different per status. */
const deleteDescription = computed(() => {
  const r = request.value;
  const period = `${formatDate(r.from_date)}${r.from_date !== r.to_date ? ` to ${formatDate(r.to_date)}` : ''}`;
  const tail = r.status === 'Approved'
    ? 'The attendance days it marked as "On Leave" will be put back to what they said before.'
    : r.status === 'Pending'
      ? 'The balance it was reserving becomes available again.'
      : 'It is a rejected application, so nothing on the attendance calendar or the balance moves.';
  return `This removes ${r.employee}'s ${r.leave_type} application for ${period} from the record. ${tail}`;
});

const remove = async (): Promise<void> => {
  const proceed = await confirmDialog({
    title: `Delete application #${request.value.id}?`,
    description: deleteDescription.value,
    danger: true,
  });
  if (!proceed) return;
  router.delete(route('hrm.leaves.destroy', request.value.id));
};

const { downloadPdf } = useReportPdf();
const pdfPayload = computed(() => {
    const r = props.detail.request;
    return {
        title: `Leave application #${r.id}`,
        subtitle: `${r.employee} · ${r.leave_type ?? 'Leave'}`,
        permission: 'hrm.leaves.view',
        orientation: 'portrait',
        meta: {
            'Employee': r.employee,
            'Employee ID': r.employee_id,
            'Leave type': r.leave_type ?? '—',
            'Status': r.status,
            'Days': String(r.days),
        },
        headings: ['Field', 'Value'],
        rows: [
            ['Department', r.department ?? '—'],
            ['From', r.from_date ?? '—'],
            ['To', r.to_date ?? '—'],
            ['Days', String(r.days)],
            ['Half day', r.is_half_day ? 'Yes' : 'No'],
            ['Reason', r.reason ?? '—'],
            ['Status', r.status],
            ['Created', r.created_at ?? '—'],
            ['Approved', r.approved_at ?? '—'],
        ],
        footer: `Leave application #${r.id} · HR`,
    };
});
const printSlip = (): void => printDocument({ portrait: true });
</script>

<template>
  <AdminLayout :breadcrumbs="[{ title: 'Leave' }, { title: 'Requests', href: route('hrm.leaves.requests') }, { title: `#${request.id}` }]">
    <Head :title="`Leave application #${request.id}`" />

    <div class="space-y-4">
      <ModuleNav />

      <PageHeader
        :title="`Leave application #${request.id}`"
        :icon="CalendarCheck2"
        :description="`${request.employee} · ${request.employee_id}${request.department ? ' · ' + request.department : ''} · submitted ${formatDateTime(request.created_at, 'short')}`"
      >
        <template #badge>
          <span class="inline-flex items-center rounded-full px-2.5 py-1 text-[11px] font-semibold ring-1 ring-inset" :class="statusTone">
            {{ request.status }}
          </span>
          <span v-if="pending && detail.next_stage" class="inline-flex items-center gap-1 rounded-full bg-info/10 px-2.5 py-1 text-[11px] font-medium text-info">
            <CircleDot class="size-3" /> awaiting {{ detail.next_stage }}
          </span>
        </template>
        <template #actions>
          <Button size="sm" variant="outline" @click="router.visit(route('hrm.leaves.requests'))">
            <ArrowLeft class="size-4" /> Back
          </Button>
          <Button size="sm" class="rounded-full" @click="downloadPdf(pdfPayload)">
            <FileDown class="size-4" /> Download PDF
          </Button>
          <Button size="sm" variant="outline" @click="printSlip">
            <Printer class="size-4" /> Print slip
          </Button>
          <Button v-if="pending && canManage" size="sm" variant="outline" @click="router.visit(route('hrm.leaves.edit', request.id))">
            <Pencil class="size-4" /> Edit
          </Button>
          <Button v-if="canManage" size="sm" variant="ghost" class="text-danger" @click="remove">
            <Trash2 class="size-4" /> Delete
          </Button>
        </template>
      </PageHeader>

      <div class="grid gap-4 lg:grid-cols-3">
        <!-- The application -->
        <EnterpriseCard class="lg:col-span-2" title="The application" :description="`${request.leave_type ?? 'Leave'}${request.is_half_day ? ' · half day' : ''}`" :icon="CalendarDays">
          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <p class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Period</p>
              <p class="mt-0.5 text-sm font-medium tabular-nums">
                {{ formatDate(request.from_date) }}
                <template v-if="request.from_date !== request.to_date"> → {{ formatDate(request.to_date) }}</template>
              </p>
            </div>
            <div>
              <p class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Charged</p>
              <p class="mt-0.5 text-sm font-medium tabular-nums">
                {{ formatNumber(request.days) }} day(s)
                <span v-if="detail.calendar && detail.calendar.excluded > 0" class="font-normal text-muted-foreground">
                  — {{ detail.calendar.excluded }} date(s) in the range not charged
                </span>
              </p>
            </div>
            <div>
              <p class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Leave type</p>
              <p class="mt-0.5 flex items-center gap-1.5 text-sm font-medium">
                <span class="size-2 rounded-full" :style="{ backgroundColor: request.leave_type_color ?? '#94a3b8' }" />
                {{ request.leave_type }}
                <span class="rounded bg-muted px-1.5 py-0.5 text-[10px] font-semibold text-muted-foreground">{{ request.leave_type_code }}</span>
                <span v-if="!detail.balance.is_paid" class="rounded bg-danger/10 px-1.5 py-0.5 text-[10px] font-semibold text-danger">unpaid</span>
              </p>
            </div>
            <div>
              <p class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                {{ request.status === 'Rejected' ? 'Decided' : request.status === 'Approved' ? 'Approved on' : 'Decision' }}
              </p>
              <p class="mt-0.5 text-sm font-medium">
                <template v-if="request.approved_at">{{ formatDateTime(request.approved_at, 'short') }}</template>
                <template v-else><span class="text-muted-foreground">Not decided yet</span></template>
              </p>
            </div>
          </div>

          <div class="mt-4 rounded-lg border bg-muted/20 p-3">
            <p class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Reason given</p>
            <p class="mt-1 whitespace-pre-line text-sm">{{ request.reason || 'No reason was recorded with this application.' }}</p>
          </div>

          <!-- Day strip: which dates the policy charges -->
          <div v-if="detail.calendar?.dates?.length" class="mt-4">
            <p class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Dates in the range</p>
            <div class="mt-1.5 flex flex-wrap gap-1">
              <span
                v-for="cell in detail.calendar.dates"
                :key="cell.date"
                class="flex min-w-[42px] flex-col items-center rounded-md border px-1.5 py-1 text-center"
                :class="cell.excluded ? 'border-dashed bg-muted/40 text-muted-foreground' : 'border-primary/30 bg-primary/[0.07]'"
                :title="cell.excluded ? `${cell.date} — not charged (${cell.excluded})` : `${cell.date} — charged`"
              >
                <span class="text-[10px] uppercase leading-none text-muted-foreground">{{ weekdayName(cell.weekday) }}</span>
                <span class="text-[13px] font-semibold leading-tight tabular-nums">{{ cell.date.slice(8) }}</span>
              </span>
            </div>
            <p class="mt-1.5 text-[11px] text-muted-foreground">
              {{ detail.calendar.day_count_mode === 'working_days'
                ? 'This type counts working days: weekends and holidays in the range are not charged.'
                : 'This type counts calendar days: every date in the range is charged.' }}
            </p>
          </div>
        </EnterpriseCard>

        <!-- What it costs the balance -->
        <div class="space-y-4">
          <EnterpriseCard title="Balance position" :description="`${detail.balance.leave_type} · ${detail.balance.year}`">
            <dl class="space-y-1.5 text-sm">
              <div class="flex items-center justify-between">
                <dt class="text-muted-foreground">Entitlement</dt>
                <dd class="font-mono tabular-nums">{{ formatNumber(detail.balance.entitlement) }}d</dd>
              </div>
              <div v-if="detail.balance.carry_forward > 0" class="flex items-center justify-between">
                <dt class="text-muted-foreground">Carried from {{ detail.balance.year - 1 }}</dt>
                <dd class="font-mono tabular-nums text-info">+{{ formatNumber(detail.balance.carry_forward) }}d</dd>
              </div>
              <div v-if="detail.balance.opening > 0" class="flex items-center justify-between">
                <dt class="text-muted-foreground">Opening balance</dt>
                <dd class="font-mono tabular-nums">+{{ formatNumber(detail.balance.opening) }}d</dd>
              </div>
              <div class="flex items-center justify-between gap-4">
                <dt class="text-muted-foreground">
                  Taken (approved)
                  <span v-if="detail.balance.approved <= otherApproved" class="block text-[11px]">
                    of which {{ formatNumber(detail.request.days) }}d is this application
                  </span>
                </dt>
                <dd class="shrink-0 font-mono tabular-nums">−{{ formatNumber(detail.balance.approved) }}d</dd>
              </div>
              <div v-if="detail.balance.pending > 0" class="flex items-center justify-between">
                <dt class="text-muted-foreground">Reserved (pending)</dt>
                <dd class="font-mono tabular-nums">−{{ formatNumber(detail.balance.pending) }}d</dd>
              </div>
              <div class="flex items-center justify-between border-t pt-1.5 font-semibold">
                <dt>Available</dt>
                <dd class="font-mono tabular-nums">{{ formatNumber(detail.balance.available) }}d</dd>
              </div>
            </dl>

            <div v-if="pending" class="mt-3 rounded-lg border p-3" :class="detail.position.sufficient ? 'bg-success/[0.06] border-success/30' : 'bg-danger/[0.06] border-danger/30'">
              <p class="flex items-center gap-1.5 text-[13px] font-semibold" :class="detail.position.sufficient ? 'text-success' : 'text-danger'">
                <component :is="detail.position.sufficient ? CircleCheck : TriangleAlert" class="size-3.5" />
                {{ detail.position.sufficient ? 'Covered by the balance' : 'Exceeds the balance' }}
              </p>
              <p class="mt-0.5 text-xs text-muted-foreground">
                <template v-if="detail.position.sufficient">
                  These {{ formatNumber(detail.position.reserved) }} day(s) are already reserved against the balance
                  above, so approving leaves {{ formatNumber(detail.position.after_approval) }} day(s) free.
                </template>
                <template v-else>
                  The application asks for more than the balance holds — {{ formatNumber(detail.position.shortfall) }}
                  day(s) short. Approval will be refused until the balance or the request changes.
                </template>
              </p>
            </div>

            <p v-else-if="request.status === 'Approved'" class="mt-3 flex items-start gap-1.5 text-[11px] text-muted-foreground">
              <Info class="mt-0.5 size-3 shrink-0" />
              These days are already deducted: they sit in “Taken”, not in the balance above.
            </p>
            <p v-if="detail.balance.frozen" class="mt-2 flex items-start gap-1.5 text-[11px] text-info">
              <Info class="mt-0.5 size-3 shrink-0" /> Frozen by the {{ detail.balance.year }} year-end close.
            </p>
          </EnterpriseCard>

          <!-- Decision -->
          <EnterpriseCard v-if="pending" title="Decide" description="This is a decision on somebody's time off — it is recorded against your name." :icon="Check">
            <template v-if="!rejecting">
              <div class="space-y-1.5">
                <label for="approve-note" class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Note (optional on approval)</label>
                <textarea id="approve-note" v-model="approveForm.comments" rows="2" class="w-full rounded-md border bg-background px-3 py-2 text-sm" placeholder="Anything the record should carry." />
              </div>
              <div class="mt-3 flex gap-2">
                <Button class="flex-1" :disabled="busy || !canApprove" @click="approve">
                  <Check class="size-4" /> Approve {{ formatNumber(request.days) }}d
                </Button>
                <Button variant="outline" class="text-danger" :disabled="busy || !canApprove" @click="rejecting = true">
                  <X class="size-4" /> Reject
                </Button>
              </div>
              <p v-if="!canApprove" class="mt-2 text-[11px] text-muted-foreground">You do not have the leave approval permission.</p>
            </template>

            <template v-else>
              <div class="rounded-lg border border-danger/30 bg-danger/[0.04] p-3">
                <label for="reject-note" class="text-[11px] font-semibold uppercase tracking-wide text-danger">Reason for rejecting (required)</label>
                <textarea id="reject-note" v-model="rejectForm.comments" rows="3" class="mt-1 w-full rounded-md border bg-background px-3 py-2 text-sm" placeholder="e.g. No medical certificate attached; department cannot spare the days in that week." />
                <p v-if="rejectForm.errors.comments" class="mt-1 text-xs text-destructive">{{ rejectForm.errors.comments }}</p>
              </div>
              <div class="mt-2 flex justify-end gap-2">
                <Button variant="ghost" :disabled="busy" @click="rejecting = false">Cancel</Button>
                <Button variant="destructive" :disabled="busy" @click="submitReject">Record rejection</Button>
              </div>
            </template>
          </EnterpriseCard>

          <EnterpriseCard v-else title="Decision" :icon="Check">
            <p class="text-sm">
              <template v-if="request.status === 'Approved'">
                <span class="font-semibold text-success">Approved</span>
                <span class="text-muted-foreground"> on {{ formatDateTime(request.approved_at, 'short') }}</span>
              </template>
              <template v-else>
                <span class="font-semibold text-danger">Rejected</span>
                <span class="text-muted-foreground"> on {{ formatDateTime(request.approved_at, 'short') }}</span>
              </template>
            </p>
            <p v-if="detail.history.length" class="mt-1 text-xs text-muted-foreground">
              Decided at stage {{ detail.history[detail.history.length - 1]?.stage ?? 'HR' }}
              by {{ detail.history[detail.history.length - 1]?.actor ?? 'an unnamed user' }}.
            </p>
          </EnterpriseCard>
        </div>
      </div>

      <!-- Trail -->
      <EnterpriseCard title="Approval trail" description="Every stage, in order, with who decided and what they said." :icon="History">
        <ol v-if="detail.history.length" class="relative space-y-3 border-l pl-5">
          <li v-for="entry in detail.history" :key="entry.stage_index + entry.decision" class="relative">
            <span
              class="absolute -left-[25px] mt-1 grid size-4 place-items-center rounded-full ring-4 ring-card"
              :class="entry.decision === 'Approved' ? 'bg-success/15 text-success' : 'bg-danger/15 text-danger'"
            >
              <component :is="entry.decision === 'Approved' ? Check : X" class="size-2.5" stroke-width="3" />
            </span>
            <p class="text-[13px]">
              <span class="font-semibold">{{ entry.decision }}</span>
              <span class="text-muted-foreground"> at stage {{ entry.stage_index }} ({{ entry.stage }}) · {{ entry.actor ?? 'unnamed user' }} · {{ formatDateTime(entry.decided_at, 'short') }}</span>
            </p>
            <p v-if="entry.comments" class="mt-0.5 text-xs italic text-muted-foreground">“{{ entry.comments }}”</p>
          </li>
        </ol>
        <p v-else-if="detail.request.status === 'Approved' || detail.request.status === 'Rejected'"
           class="rounded-lg border border-dashed px-4 py-3 text-[13px] text-muted-foreground">
          <span class="font-medium text-foreground">This application is {{ detail.request.status.toLowerCase() }},
          but no stage was recorded.</span>
          <template v-if="detail.request.approved_at">
            The decision date is {{ formatDateTime(detail.request.approved_at, 'short') }}.
          </template>
          It was set outside the approval workflow — seeded, imported, or decided before the trail existed —
          so there is no name and no comment to show. Application #{{ detail.request.id }} of
          {{ detail.request.employee.name }} is the record. The workflow for this type is
          {{ detail.stages.join(' → ') }}.
        </p>
        <p v-else class="py-4 text-center text-sm text-muted-foreground">
          Nothing has been decided yet. The workflow runs {{ detail.stages.join(' → ') }}.
        </p>
      </EnterpriseCard>

      <!-- Last year, for context -->
      <EnterpriseCard v-if="detail.previous_year_balance.total > 0" :title="`Same type in ${detail.balance.year - 1}`" :description="detail.previous_year_balance.approved > 0 ? 'Whether the employee is drawing on a pattern or this is unusual.' : `The type granted days in ${detail.balance.year - 1}, but none were taken — so there is no pattern to compare against.`">
        <div class="flex flex-wrap gap-6 text-sm">
          <div>
            <p class="text-[11px] uppercase tracking-wider text-muted-foreground">Entitlement</p>
            <p class="font-mono font-semibold tabular-nums">{{ formatNumber(detail.previous_year_balance.entitlement) }}d</p>
          </div>
          <div>
            <p class="text-[11px] uppercase tracking-wider text-muted-foreground">Taken</p>
            <p class="font-mono font-semibold tabular-nums">{{ formatNumber(detail.previous_year_balance.approved) }}d</p>
          </div>
          <div>
            <p class="text-[11px] uppercase tracking-wider text-muted-foreground">Carried forward</p>
            <p class="font-mono font-semibold tabular-nums">{{ formatNumber(detail.previous_year_balance.carry_forward) }}d</p>
          </div>
          <div>
            <p class="text-[11px] uppercase tracking-wider text-muted-foreground">Closed</p>
            <p class="font-semibold">{{ detail.previous_year_balance.frozen ? 'Yes' : 'No' }}</p>
          </div>
        </div>
      </EnterpriseCard>
    </div>
  </AdminLayout>
</template>
