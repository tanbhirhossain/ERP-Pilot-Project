<script setup lang="ts">
/**
 * Probation & confirmation.
 *
 * The page this replaces answered HTTP 500: it asked the service for `stats()`, which does not exist
 * (the service calls it `dueCounts()`), so nobody had ever seen it render. Its create form was equally
 * dead — the service signature is create(employeeId, data) and the controller handed it one array, so
 * every submission would have been a TypeError had the page loaded at all.
 *
 * Two things this page has to get right, because probation is a deadline screen:
 *   1. How long is left, and which of those have already run out.
 *   2. What the next action is — extend, confirm or fail — with the consequence stated on the button.
 */
import { computed, reactive, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { BadgeCheck, CalendarClock, Timer, UserX } from 'lucide-vue-next';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import EnterpriseCard from '@core/Components/EnterpriseCard.vue';
import EnterpriseStat from '@core/Components/EnterpriseStat.vue';
import EmptyState from '@core/Components/EmptyState.vue';
import SearchableSelect from '@core/Components/SearchableSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useToast } from '@core/Composables/useToast';
import { useConfirm, promptDialog } from '@core/Composables/useConfirm';
import { formatDate } from '@core/Utils/format';

interface Probation {
  id: number;
  employee_id: string;
  probation_start: string | null;
  probation_end: string | null;
  extended_till: string | null;
  status: string;
  remarks: string | null;
  employee?: { id: number; full_name: string | null; employee_id: string; department?: { name: string } | null } | null;
}

const props = defineProps<{
  probations: { data: Probation[]; total: number; links: unknown[] };
  stats: { active: number; overdue: number; due_7d: number };
  filters: { status?: string | null; employee_id?: string | null };
}>();

const toast = useToast();
/*
 * Aliased on purpose. NativeDialogTest bans a bare `confirm(` because that is how window.confirm is
 * written; the composable has the same name, so binding it as `confirm` makes a migrated page look
 * like it regressed. `askConfirm` says which one this is.
 */
const { confirm: askConfirm } = useConfirm();
const showCreate = ref(false);
const busy = ref(false);

const form = reactive({
  employee_id: null as string | number | null,
  probation_start: '',
  probation_end: '',
  remarks: '',
});

const today = new Date();

/** Deadline a row is judged against — the extension wins when there is one. */
const deadline = (p: Probation): string | null => p.extended_till ?? p.probation_end;

const daysLeft = (p: Probation): number | null => {
  const d = deadline(p);
  if (!d) return null;
  return Math.ceil((new Date(d).getTime() - today.getTime()) / 86_400_000);
};

const isOpen = (p: Probation): boolean => p.status === 'Active' || p.status === 'Extended';

/**
 * Overdue is `isOverdue()` on the model, not "days left < 0": a confirmed probation whose deadline
 * has passed is not late, it is finished.
 */
const isOverdue = (p: Probation): boolean => isOpen(p) && (daysLeft(p) ?? 0) < 0;

const openCount = computed(() => props.probations.data.filter(isOpen).length);

const statusFilters = [
  { value: '', label: 'All statuses' },
  { value: 'Active', label: 'Active' },
  { value: 'Extended', label: 'Extended' },
  { value: 'Confirmed', label: 'Confirmed' },
  { value: 'Failed', label: 'Failed' },
  { value: 'Terminated', label: 'Terminated' },
];

function filterBy(status: string): void {
  router.get(route('hrm.probations.index'), status ? { status } : {}, { preserveState: true, preserveScroll: true });
}

function create(): void {
  if (!form.employee_id) {
    toast.warning('Choose the employee whose probation is starting.');
    return;
  }
  if (!form.probation_end) {
    toast.warning('A probation needs an end date — that date is the whole screen.');
    return;
  }
  busy.value = true;
  router.post(route('hrm.probations.store'), { ...form }, {
    onSuccess: () => {
      showCreate.value = false;
      Object.assign(form, { employee_id: null, probation_start: '', probation_end: '', remarks: '' });
    },
    onError: (errors) => toast.error(Object.values(errors).join(' ') || 'The probation was not created.'),
    onFinish: () => { busy.value = false; },
  });
}

async function extend(p: Probation): Promise<void> {
  const value = await promptDialog({
    title: `Extend probation — ${p.employee?.full_name ?? p.employee_id}`,
    description: `The end date moves from ${formatDate(deadline(p))}. Extending does not change the employee's employment type.`,
    inputLabel: 'New end date',
    inputPlaceholder: 'YYYY-MM-DD',
    confirmLabel: 'Extend',
    danger: false,
  });
  if (!value) return;
  router.post(route('hrm.probations.extend', p.id), { extended_till: value }, {
    onError: (errors) => toast.error(Object.values(errors).join(' ')),
  });
}

async function confirmProbation(p: Probation): Promise<void> {
  const ok = await askConfirm({
    title: 'Confirm probation',
    description: `${p.employee?.full_name ?? p.employee_id} becomes a permanent employee — employment type changes on the employee record and the change is written to the audit trail.`,
    confirmLabel: 'Confirm as permanent',
    danger: false,
  });
  if (!ok) return;
  router.post(route('hrm.probations.confirm', p.id), {}, {
    onError: (errors) => toast.error(Object.values(errors).join(' ')),
  });
}

async function fail(p: Probation): Promise<void> {
  const reason = await promptDialog({
    title: 'Fail probation',
    description: 'This closes the probation as failed. The reason is stored and cannot be edited afterwards.',
    inputLabel: 'Reason',
    inputType: 'textarea',
    confirmLabel: 'Record the failure',
  });
  if (reason === null) return;
  if (reason.trim().length < 10) {
    toast.warning('A reason needs at least ten characters — it is the only record of why.');
    return;
  }
  router.post(route('hrm.probations.fail', p.id), { reason: reason.trim() }, {
    onError: (errors) => toast.error(Object.values(errors).join(' ')),
  });
}
</script>

<template>
  <AdminLayout :breadcrumbs="[{ title: 'HRM' }, { title: 'Probation & confirmation' }]">
    <Head title="Probation & confirmation" />

    <PageHeader
      title="Probation & confirmation"
      description="Every probation with the deadline it is judged against. Confirming makes the employee permanent; failing closes the record with a reason. Nothing here changes a salary."
      :icon="Timer"
    >
      <template #actions>
        <Button size="sm" @click="showCreate = !showCreate">
          <CalendarClock class="size-4" /> New probation
        </Button>
      </template>
    </PageHeader>

    <!-- Three real numbers, each a different question. "Total records" was a fourth that only
         restated the table heading. -->
    <div class="mt-5 grid gap-3 sm:grid-cols-3">
      <EnterpriseStat label="Open probation" :value="stats.active" hint="Active or extended, not yet decided" :icon="Timer" />
      <EnterpriseStat label="Overdue" :value="stats.overdue" hint="Deadline passed and still open" :icon="UserX" />
      <EnterpriseStat label="Due within 7 days" :value="stats.due_7d" hint="Decide this week" :icon="CalendarClock" />
    </div>

    <EnterpriseCard v-if="showCreate" class="mt-4" title="Open a probation" description="The end date is the deadline the desk counts down to.">
      <div class="grid gap-4 md:grid-cols-4">
        <div class="space-y-1">
          <Label>Employee</Label>
          <SearchableSelect v-model="form.employee_id" endpoint="/hrm/api/options/employees" placeholder="Search by name or ID" />
        </div>
        <div class="space-y-1">
          <Label>Start</Label>
          <Input v-model="form.probation_start" type="date" />
        </div>
        <div class="space-y-1">
          <Label>End</Label>
          <Input v-model="form.probation_end" type="date" />
        </div>
        <div class="space-y-1">
          <Label>Remarks</Label>
          <Input v-model="form.remarks" placeholder="Three months from joining" />
        </div>
      </div>
      <div class="mt-4 flex justify-end gap-2">
        <Button variant="outline" size="sm" @click="showCreate = false">Cancel</Button>
        <Button size="sm" :disabled="busy" @click="create">Open probation</Button>
      </div>
    </EnterpriseCard>

    <EnterpriseCard
      class="mt-4"
      title="Probations"
      :description="`${probations.total} on record · ${openCount} open on this page`"
      :icon="BadgeCheck"
    >
      <template #actions>
        <select
          :value="filters.status ?? ''"
          class="h-8 rounded-md border bg-background px-2 text-xs"
          @change="filterBy(($event.target as HTMLSelectElement).value)"
        >
          <option v-for="s in statusFilters" :key="s.value" :value="s.value">{{ s.label }}</option>
        </select>
      </template>

      <div v-if="probations.data.length" class="overflow-x-auto">
        <table class="w-full min-w-[840px] text-sm">
          <thead class="border-b text-[11px] uppercase tracking-wider text-muted-foreground">
            <tr>
              <th class="py-2 text-left font-medium">Employee</th>
              <th class="py-2 text-left font-medium">Period</th>
              <th class="py-2 text-left font-medium">Status</th>
              <th class="py-2 text-right font-medium">Time left</th>
              <th class="py-2 text-right font-medium">Decision</th>
            </tr>
          </thead>
          <tbody class="divide-y">
            <tr v-for="p in probations.data" :key="p.id" class="align-top">
              <td class="py-2.5">
                <p class="font-medium">{{ p.employee?.full_name ?? p.employee_id }}</p>
                <p class="text-[11px] text-muted-foreground">
                  {{ p.employee_id }}<span v-if="p.employee?.department?.name"> · {{ p.employee.department.name }}</span>
                </p>
              </td>
              <td class="py-2.5 text-xs">
                {{ formatDate(p.probation_start) }} → {{ formatDate(deadline(p)) }}
                <span v-if="p.extended_till" class="ml-1 rounded bg-warning/15 px-1.5 py-0.5 text-[10px] font-medium text-warning">
                  extended
                </span>
              </td>
              <td class="py-2.5">
                <span
                  class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                  :class="{
                    'bg-info/10 text-info': p.status === 'Active',
                    'bg-warning/10 text-warning': p.status === 'Extended' || p.status === 'Failed',
                    'bg-success/10 text-success': p.status === 'Confirmed',
                    'bg-muted text-muted-foreground': !['Active', 'Extended', 'Confirmed', 'Failed'].includes(p.status),
                  }"
                >{{ p.status }}</span>
              </td>
              <td class="py-2.5 text-right text-xs tabular-nums">
                <template v-if="isOpen(p)">
                  <span :class="isOverdue(p) ? 'font-semibold text-danger' : 'text-foreground'">
                    {{ isOverdue(p) ? `${Math.abs(daysLeft(p) ?? 0)}d overdue` : `${daysLeft(p)}d left` }}
                  </span>
                </template>
                <span v-else class="text-muted-foreground">decided</span>
              </td>
              <td class="py-2.5">
                <div v-if="isOpen(p)" class="flex flex-wrap justify-end gap-1.5">
                  <Button size="sm" variant="outline" class="h-7 text-xs" @click="extend(p)">Extend</Button>
                  <Button size="sm" variant="outline" class="h-7 text-xs text-danger" @click="fail(p)">Fail</Button>
                  <Button size="sm" class="h-7 text-xs" @click="confirmProbation(p)">Confirm permanent</Button>
                </div>
                <p v-else class="text-right text-[11px] text-muted-foreground">
                  {{ p.status === 'Confirmed' ? 'New employee master already updated' : 'Closed' }}
                </p>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <EmptyState
        v-else
        title="No probations on record"
        description="Open one for every employee hired on a probationary employment type. Three to six months is the usual run."
        :icon="Timer"
      />

      <p v-if="probations.data.length" class="mt-3 text-[11px] leading-snug text-muted-foreground">
        Confirming flips the employee's employment type to Permanent and writes an audit entry. Failing
        keeps the record and does not terminate the employee — termination is a separate action on the
        employee record, because probation and exit are different decisions.
      </p>
    </EnterpriseCard>
  </AdminLayout>
</template>
