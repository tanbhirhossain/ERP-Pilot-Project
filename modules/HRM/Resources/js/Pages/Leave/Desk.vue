<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import {
  ArrowRight,
  CalendarCheck2,
  CalendarClock,
  CalendarDays,
  CheckCircle2,
  ClipboardList,
  Info,
  Plus,
  TriangleAlert,
  UserMinus,
  Users,
} from 'lucide-vue-next';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import EnterpriseCard from '@core/Components/EnterpriseCard.vue';
import EmptyState from '@core/Components/EmptyState.vue';
import ModuleNav from './ModuleNav.vue';
import { Button } from '@/components/ui/button';
import { formatDate, formatDateTime, formatNumber } from '@core/Utils/format';

/**
 * The Leave Desk.
 *
 * What an HR officer needs when the module opens: who is away today, what is waiting for a decision,
 * and which type is running out. The old screen answered none of those — it opened on four counters
 * (total/pending/approved/rejected) that told you how many rows existed, next to a table of the two
 * seeded applications.
 *
 * Nothing here is a percentage bar. Leave is counted in days, and a bar of "73% used" on a 16-day
 * Sick Leave entitlement says less than "11 of 16 days taken".
 */

interface LeaveRow {
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
}

interface TypeRow {
  id: number;
  name: string;
  code: string | null;
  color: string | null;
  is_paid: boolean;
  days_per_year: number;
  entitlement_pool: number;
  approved: number;
  pending: number;
  requests: number;
}

interface Overview {
  year: number;
  pending: LeaveRow[];
  pending_total: number;
  pending_days: number;
  on_leave_now: LeaveRow[];
  on_leave_today: number;
  types: TypeRow[];
  totals: { entitlement_pool: number; approved: number; pending: number; utilisation: number | null; active_employees: number };
  series: Array<{ month: number; label: string; days: number; requests: number }>;
  year_state: { previous_closed: boolean; current_closed: boolean; previous_rows: number; current_rows: number };
  lapse_preview: { days: number; carry: number; types: Array<{ name: string; lapsed: number; is_carry_forward: boolean }>; already_closed: number };
}

interface Decision {
  id: number;
  leave_request_id: number;
  decision: string;
  stage: string | null;
  actor: string | null;
  comments: string | null;
  decided_at: string | null;
  employee: string | null;
  leave_type: string | null;
  leave_type_color: string | null;
  days: number | null;
}

const props = defineProps<{ overview: Overview; decisions: Decision[]; canApprove: boolean }>();

const years = Array.from({ length: 7 }, (_, i) => new Date().getFullYear() - 3 + i);
const changeYear = (year: number): void => {
  router.get(route('hrm.leaves.index'), { year }, { preserveState: true, preserveScroll: true });
};

const days = (value: number): string => `${formatNumber(Math.round(value * 10) / 10)}d`;

/** The height of a month bar in a 96px strip — scaled to the busiest month, never to 100%. */
const peak = computed(() => Math.max(1, ...props.overview.series.map((s) => s.days)));
const barHeight = (value: number): string => `${Math.max(2, Math.round((value / peak.value) * 96))}px`;

const longestPending = computed(() => props.overview.pending[0] ?? null);

const todayLabel = new Date().toLocaleDateString(undefined, { day: '2-digit', month: 'short', year: 'numeric' });
</script>

<template>
  <AdminLayout :breadcrumbs="[{ title: 'Leave' }, { title: 'Desk' }]">
    <Head title="Leave desk" />

    <div class="space-y-4">
      <ModuleNav />

      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 class="flex items-center gap-2.5 text-xl font-bold tracking-tight">
            <span class="grid size-9 place-items-center rounded-xl bg-gradient-to-br from-primary to-primary/70 text-primary-foreground shadow-sm">
              <CalendarCheck2 class="size-5" />
            </span>
            Leave desk
          </h1>
          <p class="mt-1 max-w-3xl text-sm text-muted-foreground">
            Who is away today, what is waiting for a decision, and what each leave type is carrying.
            Days, not percentages — an entitlement is a number of days an employee may take.
          </p>
        </div>
        <div class="flex items-center gap-2">
          <select
            class="h-9 rounded-lg border bg-card px-3 text-sm"
            :value="overview.year"
            aria-label="Leave year"
            @change="changeYear(Number(($event.target as HTMLSelectElement).value))"
          >
            <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
          </select>
          <Button size="sm" variant="outline" @click="router.visit(route('hrm.leaves.approvals'))">
            <ClipboardList class="size-4" /> Approvals
          </Button>
          <Button size="sm" @click="router.visit(route('hrm.leaves.create'))">
            <Plus class="size-4" /> New application
          </Button>
        </div>
      </div>

      <!-- What needs a decision, before anything else -->
      <section
        v-if="overview.pending_total > 0"
        class="rounded-xl border border-warning/30 bg-warning/[0.06] px-5 py-4"
      >
        <div class="flex flex-wrap items-start gap-3">
          <span class="mt-0.5 grid size-8 shrink-0 place-items-center rounded-lg bg-warning/15 text-warning">
            <CalendarClock class="size-4" />
          </span>
          <div class="min-w-0 flex-1">
            <p class="text-sm font-semibold">
              {{ formatNumber(overview.pending_total) }}
              {{ overview.pending_total === 1 ? 'application is' : 'applications are' }} waiting for a decision
              <span class="font-normal text-muted-foreground">— {{ days(overview.pending_days) }} in total</span>
            </p>
            <p v-if="longestPending" class="mt-0.5 text-xs text-muted-foreground">
              Earliest: {{ longestPending.employee }} ({{ longestPending.employee_id }}),
              {{ longestPending.leave_type }}, {{ formatDate(longestPending.from_date) }} → {{ formatDate(longestPending.to_date) }}
            </p>
            <p v-else class="mt-0.5 text-xs text-muted-foreground">Nothing is queued.</p>
          </div>
          <Button size="sm" variant="outline" @click="router.visit(route('hrm.leaves.approvals'))">
            Open the queue <ArrowRight class="size-3.5" />
          </Button>
        </div>
      </section>

      <section
        v-else
        class="rounded-xl border border-success/30 bg-success/[0.06] px-5 py-4"
      >
        <div class="flex items-start gap-3">
          <span class="mt-0.5 grid size-8 shrink-0 place-items-center rounded-lg bg-success/15 text-success">
            <CheckCircle2 class="size-4" />
          </span>
          <div>
            <p class="text-sm font-semibold">Nothing is waiting for a decision</p>
            <p class="mt-0.5 text-xs text-muted-foreground">
              Every leave application in the system has been approved or rejected. New applications arrive here.
            </p>
          </div>
        </div>
      </section>

      <div class="grid items-start gap-4 lg:grid-cols-3">
        <!-- Away today -->
        <EnterpriseCard title="Away today" :description="`Approved leave that covers ${todayLabel}`" :icon="UserMinus" class="lg:col-span-2">
          <template #actions>
            <span class="rounded-full bg-muted px-2.5 py-0.5 text-[11px] font-medium tabular-nums">
              {{ formatNumber(overview.on_leave_today) }} {{ overview.on_leave_today === 1 ? 'person' : 'people' }}
            </span>
          </template>

          <ul v-if="overview.on_leave_now.length" class="divide-y">
            <li
              v-for="row in overview.on_leave_now.slice(0, 8)"
              :key="row.id"
              class="flex items-center gap-3 py-2.5 first:pt-0 last:pb-0"
            >
              <span
                class="size-2 shrink-0 rounded-full"
                :style="{ backgroundColor: row.leave_type_color ?? 'hsl(var(--muted-foreground))' }"
                aria-hidden="true"
              />
              <button
                type="button"
                class="min-w-0 flex-1 text-left text-[13px] font-medium hover:text-primary hover:underline"
                @click="router.visit(route('hrm.leaves.show', row.id))"
              >
                {{ row.employee }}
                <span class="font-normal text-muted-foreground">· {{ row.employee_id }}</span>
              </button>
              <span class="hidden shrink-0 text-xs text-muted-foreground sm:block">{{ row.department ?? '—' }}</span>
              <span class="shrink-0 text-xs">{{ row.leave_type }}</span>
              <span class="shrink-0 text-xs tabular-nums text-muted-foreground">
                {{ formatDate(row.from_date) }} → {{ formatDate(row.to_date) }}
              </span>
              <span class="shrink-0 text-xs font-semibold tabular-nums">{{ days(row.days) }}</span>
            </li>
          </ul>
          <!--
            A 260px dashed panel to say "nobody" pushed the type table below the fold. An absence of
            people is one line, not an illustration.
          -->
          <p v-else class="flex items-center gap-2 rounded-lg border border-dashed px-3 py-2.5 text-[13px] text-muted-foreground">
            <CalendarDays class="size-3.5 shrink-0" />
            Nobody is on approved leave today. Somebody away without an approved application shows as
            Absent on attendance, not On Leave.
          </p>

          <p v-if="overview.on_leave_now.length > 8" class="mt-3 text-xs text-muted-foreground">
            and {{ overview.on_leave_now.length - 8 }} more today.
          </p>
        </EnterpriseCard>

        <!-- The year in days -->
        <EnterpriseCard title="Leave taken, by month" :description="`Approved days in ${overview.year}`" :icon="CalendarDays">
          <div class="flex items-end justify-between gap-1" role="img" :aria-label="`Approved leave days per month in ${overview.year}`">
            <div v-for="point in overview.series" :key="point.month" class="flex min-w-0 flex-1 flex-col items-center gap-1.5">
              <span class="text-[10px] tabular-nums text-muted-foreground">{{ point.days > 0 ? point.days : '' }}</span>
              <div
                class="w-full rounded-t bg-primary/25 transition-colors hover:bg-primary/45"
                :style="{ height: barHeight(point.days) }"
                :title="`${point.label}: ${point.days} day(s) across ${point.requests} application(s)`"
              />
              <span class="text-[10px] uppercase tracking-wide text-muted-foreground">{{ point.label }}</span>
            </div>
          </div>
          <div class="mt-4 space-y-1 border-t pt-3 text-xs text-muted-foreground">
            <p class="flex items-center justify-between">
              <span>Approved this year</span>
              <span class="font-semibold tabular-nums text-foreground">{{ days(overview.totals.approved) }}</span>
            </p>
            <p class="flex items-center justify-between">
              <span>Still reserving balance</span>
              <span class="tabular-nums">{{ days(overview.totals.pending) }}</span>
            </p>
            <p class="flex items-center justify-between">
              <span>Granted across {{ formatNumber(overview.totals.active_employees) }} staff</span>
              <span class="tabular-nums">{{ days(overview.totals.entitlement_pool) }}</span>
            </p>
            <p class="pt-1 text-[11px] leading-snug">
              The grant total pools every type for every employee, so it is dominated by long leave — it is the
              size of the entitlement, not what anybody can spend.
            </p>
          </div>
        </EnterpriseCard>
      </div>

      <!-- By type -->
      <EnterpriseCard title="What each leave type is carrying" :description="`Entitlement granted versus days actually taken in ${overview.year}`" :icon="Users">
        <div class="overflow-x-auto">
          <table class="w-full min-w-[720px] text-sm">
            <thead class="border-b text-[11px] uppercase tracking-wider text-muted-foreground">
              <tr>
                <th class="pb-2 text-left font-semibold">Leave type</th>
                <th class="pb-2 text-right font-semibold">Days / year</th>
                <th class="pb-2 text-right font-semibold">Applications</th>
                <th class="pb-2 text-right font-semibold">Approved</th>
                <th class="pb-2 text-right font-semibold">Pending</th>
                <th class="pb-2 pl-6 text-left font-semibold">Days per employee taken</th>
              </tr>
            </thead>
            <tbody class="divide-y">
              <tr v-for="type in overview.types" :key="type.id" class="hover:bg-muted/30">
                <td class="py-2.5">
                  <span class="flex items-center gap-2 font-medium">
                    <span class="size-2 rounded-full" :style="{ backgroundColor: type.color ?? '#94a3b8' }" aria-hidden="true" />
                    {{ type.name }}
                    <span class="rounded bg-muted px-1.5 py-0.5 text-[10px] font-semibold text-muted-foreground">{{ type.code }}</span>
                    <span v-if="!type.is_paid" class="rounded bg-danger/10 px-1.5 py-0.5 text-[10px] font-semibold text-danger">unpaid</span>
                  </span>
                </td>
                <td class="py-2.5 text-right tabular-nums text-muted-foreground">{{ formatNumber(type.days_per_year) }}</td>
                <td class="py-2.5 text-right tabular-nums">{{ formatNumber(type.requests) }}</td>
                <td class="py-2.5 text-right font-semibold tabular-nums">{{ days(type.approved) }}</td>
                <td class="py-2.5 text-right tabular-nums text-muted-foreground">{{ type.pending > 0 ? days(type.pending) : '—' }}</td>
                <td class="py-2.5 pl-6">
                  <!--
                    A share-of-grant bar was wrong here twice over: the grant pools every employee, so the
                    percentage never rises above a rounding error, and on Maternity (180 days of grant)
                    even a correct bar would say nothing. The average days per head is what an HR reader
                    can compare between types.
                  -->
                  <span class="text-xs tabular-nums">
                    <template v-if="type.requests > 0 && overview.totals.active_employees > 0">
                      {{ formatNumber(Math.round((type.approved / overview.totals.active_employees) * 100) / 100) }}d
                      <span class="text-muted-foreground">per employee</span>
                    </template>
                    <span v-else class="text-muted-foreground">not used</span>
                  </span>
                </td>
              </tr>
              <tr v-if="!overview.types.length">
                <td colspan="6" class="py-8 text-center text-muted-foreground">No active leave types.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </EnterpriseCard>

      <div class="grid items-start gap-4 lg:grid-cols-3">
        <!-- Recent decisions -->
        <EnterpriseCard title="Recent decisions" description="The approval trail, newest first." :icon="CheckCircle2" class="lg:col-span-2">
          <ul v-if="decisions.length" class="divide-y">
            <li v-for="entry in decisions" :key="entry.id" class="flex items-start gap-3 py-2.5 first:pt-0 last:pb-0">
              <span
                class="mt-0.5 grid size-6 shrink-0 place-items-center rounded-md"
                :class="entry.decision === 'Approved' ? 'bg-success/12 text-success' : 'bg-danger/12 text-danger'"
              >
                <component :is="entry.decision === 'Approved' ? CheckCircle2 : TriangleAlert" class="size-3" />
              </span>
              <div class="min-w-0 flex-1">
                <p class="text-[13px] leading-snug">
                  <span class="font-medium">{{ entry.decision.toLowerCase() }}</span>
                  {{ entry.employee ?? 'an application' }}'s {{ entry.leave_type ?? 'leave' }}
                  <span v-if="entry.days !== null" class="text-muted-foreground">({{ days(entry.days) }})</span>
                  <span class="text-muted-foreground">— {{ entry.actor ?? 'system' }}, stage {{ entry.stage }}</span>
                </p>
                <p v-if="entry.comments" class="mt-0.5 line-clamp-2 text-xs text-muted-foreground">“{{ entry.comments }}”</p>
              </div>
              <button
                type="button"
                class="shrink-0 text-[11px] text-muted-foreground hover:text-primary hover:underline"
                @click="router.visit(route('hrm.leaves.show', entry.leave_request_id))"
              >
                {{ formatDateTime(entry.decided_at) }}
              </button>
            </li>
          </ul>
          <p v-else class="text-[13px] leading-relaxed text-muted-foreground">
            No decisions recorded yet. Approving or rejecting an application writes a stage here, with the
            name of whoever decided it — a rejection always carries a reason.
          </p>
        </EnterpriseCard>

        <!-- Year state -->
        <EnterpriseCard title="Where the year stands" :description="`Leave year ${overview.year}`" :icon="Info">
          <div class="space-y-3">
            <!-- The one number that is about to be thrown away. -->
            <div v-if="overview.lapse_preview.days > 0" class="rounded-lg border border-warning/30 bg-warning/[0.06] p-3">
              <p class="flex items-start gap-1.5 text-[13px] font-semibold text-warning">
                <TriangleAlert class="mt-0.5 size-3.5 shrink-0" />
                {{ days(overview.lapse_preview.days) }} would lapse
              </p>
              <p class="mt-1 text-xs text-muted-foreground">
                No leave type carries forward, so closing {{ overview.year }} throws every unused day away.
                {{ overview.lapse_preview.types.slice(0, 3).map((t) => t.name).join(', ') }} alone account for
                {{ days(overview.lapse_preview.types.slice(0, 3).reduce((sum, t) => sum + t.lapsed, 0)) }}.
              </p>
              <Button size="sm" variant="outline" class="mt-2 w-full" @click="router.visit(route('hrm.leave-types.index'))">
                Review carry-forward policy
              </Button>
            </div>
            <div class="rounded-lg border p-3">
              <p class="flex items-center gap-1.5 text-[13px] font-medium">
                <component
                  :is="overview.year_state.current_closed ? CheckCircle2 : Info"
                  class="size-3.5"
                  :class="overview.year_state.current_closed ? 'text-success' : 'text-muted-foreground'"
                />
                {{ overview.year }} {{ overview.year_state.current_closed ? 'is closed' : 'is open' }}
              </p>
              <p class="mt-1 text-xs text-muted-foreground">
                <template v-if="overview.year_state.current_closed">
                  {{ formatNumber(overview.year_state.current_rows) }} balance rows are frozen. Carry-forward and lapse are fixed for this year.
                </template>
                <template v-else>
                  Balances are computed live. Closing the year freezes them and carries the unused days into {{ overview.year + 1 }}.
                </template>
              </p>
            </div>
            <div class="rounded-lg border p-3">
              <p class="flex items-center gap-1.5 text-[13px] font-medium">
                <component
                  :is="overview.year_state.previous_closed ? CheckCircle2 : TriangleAlert"
                  class="size-3.5"
                  :class="overview.year_state.previous_closed ? 'text-success' : 'text-warning'"
                />
                {{ overview.year - 1 }}
                {{ overview.year_state.previous_closed ? 'carries forward' : 'was never closed' }}
              </p>
              <p class="mt-1 text-xs text-muted-foreground">
                <template v-if="overview.year_state.previous_closed">
                  Unused days from {{ overview.year - 1 }} were added to this year's balances.
                </template>
                <template v-else>
                  Nothing carries into {{ overview.year }} because {{ overview.year - 1 }} has no closed balance snapshot. Close it from Year-end to move unused days forward.
                </template>
              </p>
              <Button v-if="!overview.year_state.previous_closed" size="sm" variant="outline" class="mt-2 w-full" @click="router.visit(route('hrm.leave-year-end.index', { year: overview.year - 1 }))">
                Open year-end for {{ overview.year - 1 }}
              </Button>
            </div>
          </div>
        </EnterpriseCard>
      </div>
    </div>
  </AdminLayout>
</template>
