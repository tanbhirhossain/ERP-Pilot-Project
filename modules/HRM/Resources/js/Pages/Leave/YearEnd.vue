<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useToast } from '@core/Composables/useToast';
import {
  ArrowLeftRight,
  CalendarRange,
  CheckCircle2,
  ChevronLeft,
  ChevronRight,
  Info,
  Lock,
  TriangleAlert,
  Undo2,
} from 'lucide-vue-next';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import EnterpriseCard from '@core/Components/EnterpriseCard.vue';
import ModuleNav from './ModuleNav.vue';
import { Button } from '@/components/ui/button';
import { formatNumber } from '@core/Utils/format';
import { confirmDialog } from '@core/Composables/useConfirm';

/**
 * Year-end.
 *
 * Closing a leave year used to be one button under a gradient banner, against a table that was empty
 * until you pressed it, with a confirmation that claimed an "admin undo" the system did not have. Two
 * things were also quietly broken: the close wrote `is_closed = false`, so a closed year never read as
 * closed, and the carry it computed was not part of any balance formula.
 *
 * This page leads with the dry run — the same computation the close performs, written nowhere — so the
 * decision to close is made against numbers, not a promise.
 */

interface TypeLine {
  leave_type_id: number;
  leave_type: string;
  code: string | null;
  color: string | null;
  is_carry_forward: boolean;
  max_carry_days: number | null;
  is_encashable: boolean;
  encash_max_days: number | null;
  employees: number;
  entitlement: number;
  availed: number;
  pending: number;
  carry: number;
  lapsed: number;
  encashable: number;
  already_closed: number;
}

interface Preview {
  year: number;
  types: TypeLine[];
  totals: { employees: number; entitlement: number; availed: number; pending: number; carry: number; lapsed: number; encashable: number };
  closed: number;
  next_year_openings: number;
}

const props = defineProps<{
  year: number;
  preview: Preview;
  summary: Array<{ leave_type: string; leave_type_id: number; emp_count: number; entitlement: number; availed: number; pending: number; carry: number; lapsed: number; available: number }>;
  types: Array<{ id: number; name: string; is_carry_forward: boolean; max_carry_days: number | null; is_encashable: boolean; encash_max_days: number | null; carry_expiry_months: number; encash_salary_component: string }>;
  closed: boolean;
  next_year_prepared: boolean;
  canManage: boolean;
}>();

const toast = useToast();
const busy = ref(false);
const closeForm = useForm({ year: props.year });

const days = (value: number): string => `${formatNumber(Math.round(value * 10) / 10)}d`;

const goYear = (year: number): void => router.get(route('hrm.leave-year-end.index'), { year }, { preserveState: true, preserveScroll: true });

/**
 * What closing actually does, in the order it happens — the sentence the confirmation cannot carry.
 */
const consequence = computed(() => {
  const t = props.preview.totals;
  if (props.closed) {
    return 'This year is already closed. Its balances are frozen; re-running the close updates nothing.';
  }
  return `${formatNumber(props.preview.types.filter((x) => x.employees > 0).length)} leave types across ${formatNumber(t.employees)} employees: ${days(t.carry)} carry into ${props.year + 1}, ${days(t.lapsed)} lapse, ${days(t.encashable)} become encashable.`;
});

const closeYear = async (): Promise<void> => {
  const proceed = await confirmDialog({
    title: `Close the ${props.year} leave year?`,
    description: `${consequence.value} Balances become frozen and later back-dated applications will no longer change them. You can reopen the year from this page if something is wrong.`,
    confirmLabel: `Close ${props.year}`,
    danger: true,
  });
  if (!proceed) return;

  busy.value = true;
  closeForm.post(route('hrm.leave-year-end.close'), {
    preserveScroll: true,
    onSuccess: () => toast.success(`Leave year ${props.year} closed. Carry-forward now opens ${props.year + 1}.`),
    onError: () => toast.error('Could not close the year.'),
    onFinish: () => (busy.value = false),
  });
};

const reopenYear = async (): Promise<void> => {
  const proceed = await confirmDialog({
    title: `Reopen the ${props.year} leave year?`,
    description: `The frozen balances for ${props.year} are unfrozen and next year's opening balances are cleared. Balances go back to being calculated live from applications, so any back-dated approval will change them. Re-close the year afterwards.`,
    confirmLabel: 'Reopen year',
    danger: true,
  });
  if (!proceed) return;

  busy.value = true;
  closeForm.post(route('hrm.leave-year-end.reopen'), {
    preserveScroll: true,
    onSuccess: () => toast.success(`Leave year ${props.year} reopened.`),
    onError: () => toast.error('Could not reopen the year.'),
    onFinish: () => (busy.value = false),
  });
};
</script>

<template>
  <AdminLayout :breadcrumbs="[{ title: 'Leave' }, { title: 'Year-end' }]">
    <Head title="Leave year-end" />

    <div class="space-y-4">
      <ModuleNav />

      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 class="flex items-center gap-2.5 text-xl font-bold tracking-tight">
            <span class="grid size-9 place-items-center rounded-xl bg-gradient-to-br from-primary to-primary/70 text-primary-foreground shadow-sm">
              <CalendarRange class="size-5" />
            </span>
            Leave year-end
            <span
              class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-[11px] font-semibold"
              :class="closed ? 'bg-success/10 text-success' : 'bg-warning/10 text-warning'"
            >
              <component :is="closed ? Lock : TriangleAlert" class="size-3" />
              {{ closed ? `${year} closed` : `${year} open` }}
            </span>
          </h1>
          <p class="mt-1 max-w-3xl text-sm text-muted-foreground">
            Closing the year freezes every employee's balance for {{ year }} and hands the unused days into
            {{ year + 1 }}. Everything below is a dry run of that close — nothing has been written.
          </p>
        </div>
        <div class="flex items-center gap-1">
          <Button size="sm" variant="outline" class="h-8 px-2" @click="goYear(year - 1)"><ChevronLeft class="size-4" /> {{ year - 1 }}</Button>
          <Button size="sm" variant="outline" class="h-8 px-2" @click="goYear(year + 1)">{{ year + 1 }} <ChevronRight class="size-4" /></Button>
        </div>
      </div>

      <!-- The consequence strip: what the close will do, before the button that does it -->
      <div
        class="rounded-xl border px-5 py-4"
        :class="closed ? 'border-success/30 bg-success/[0.05]' : 'border-warning/30 bg-warning/[0.05]'"
      >
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div class="flex items-start gap-3">
            <span class="mt-0.5 grid size-8 shrink-0 place-items-center rounded-lg" :class="closed ? 'bg-success/15 text-success' : 'bg-warning/15 text-warning'">
              <component :is="closed ? CheckCircle2 : Info" class="size-4" />
            </span>
            <div>
              <p class="text-sm font-semibold">{{ consequence }}</p>
              <p class="mt-0.5 text-xs text-muted-foreground">
                <template v-if="closed">
                  {{ formatNumber(preview.closed) }} balance rows are frozen. Reopening makes them live again.
                </template>
                <template v-else-if="next_year_prepared">
                  {{ year + 1 }} already has opening balances of {{ days(preview.next_year_openings) }} — re-running the close will set them again from this year's carry.
                </template>
                <template v-else>
                  {{ year + 1 }} has no balances yet. Closing creates them with the carried days as the opening balance.
                </template>
              </p>
            </div>
          </div>
          <div v-if="canManage" class="flex gap-2">
            <Button v-if="!closed" size="sm" :disabled="busy" @click="closeYear">
              <Lock class="size-4" /> Close {{ year }}
            </Button>
            <Button v-else size="sm" variant="outline" :disabled="busy" @click="reopenYear">
              <Undo2 class="size-4" /> Reopen {{ year }}
            </Button>
          </div>
        </div>
      </div>

      <!-- Dry run by type -->
      <EnterpriseCard title="Dry run — what closing would write" :description="`Computed from live balances; nothing is stored until you close.`" :icon="ArrowLeftRight">
        <div class="overflow-x-auto">
          <table class="w-full min-w-[880px] text-sm">
            <thead class="border-b text-[11px] uppercase tracking-wider text-muted-foreground">
              <tr>
                <th class="pb-2 text-left font-semibold">Leave type</th>
                <th class="pb-2 text-right font-semibold">Employees</th>
                <th class="pb-2 text-right font-semibold">Entitlement</th>
                <th class="pb-2 text-right font-semibold">Taken</th>
                <th class="pb-2 text-right font-semibold">Pending</th>
                <th class="pb-2 text-right font-semibold">Would carry</th>
                <th class="pb-2 text-right font-semibold">Would lapse</th>
                <th class="pb-2 text-right font-semibold">Encashable</th>
                <th class="pb-2 pl-4 text-left font-semibold">Policy</th>
              </tr>
            </thead>
            <tbody class="divide-y">
              <tr v-for="line in preview.types" :key="line.leave_type_id" class="hover:bg-muted/30">
                <td class="py-2.5">
                  <span class="flex items-center gap-2 font-medium">
                    <span class="size-2 rounded-full" :style="{ backgroundColor: line.color ?? '#94a3b8' }" />
                    {{ line.leave_type }}
                    <span class="rounded bg-muted px-1.5 py-0.5 text-[10px] font-semibold text-muted-foreground">{{ line.code }}</span>
                  </span>
                </td>
                <td class="py-2.5 text-right tabular-nums text-muted-foreground">{{ formatNumber(line.employees) }}</td>
                <td class="py-2.5 text-right font-mono tabular-nums">{{ days(line.entitlement) }}</td>
                <td class="py-2.5 text-right font-mono tabular-nums">{{ days(line.availed) }}</td>
                <td class="py-2.5 text-right font-mono tabular-nums text-muted-foreground">{{ line.pending > 0 ? days(line.pending) : '–' }}</td>
                <td class="py-2.5 text-right font-mono font-semibold tabular-nums">{{ line.carry > 0 ? days(line.carry) : '–' }}</td>
                <td class="py-2.5 text-right font-mono tabular-nums" :class="line.lapsed > 0 ? 'text-warning' : 'text-muted-foreground'">
                  {{ line.lapsed > 0 ? days(line.lapsed) : '–' }}
                </td>
                <td class="py-2.5 text-right font-mono tabular-nums">{{ line.encashable > 0 ? days(line.encashable) : '–' }}</td>
                <td class="py-2.5 pl-4 text-[11px] text-muted-foreground">
                  {{ line.is_carry_forward ? `carry ≤ ${line.max_carry_days === null ? 'all' : formatNumber(line.max_carry_days)}d` : 'no carry — all lapses' }}
                  <span v-if="line.is_encashable" class="block">encash ≤ {{ line.encash_max_days === null ? 'all carried' : formatNumber(line.encash_max_days) + 'd' }}</span>
                </td>
              </tr>
            </tbody>
            <tfoot class="border-t bg-muted/30 text-[13px] font-semibold">
              <tr>
                <td class="py-2.5">All types</td>
                <td class="py-2.5 text-right tabular-nums">{{ formatNumber(preview.totals.employees) }}</td>
                <td class="py-2.5 text-right font-mono tabular-nums">{{ days(preview.totals.entitlement) }}</td>
                <td class="py-2.5 text-right font-mono tabular-nums">{{ days(preview.totals.availed) }}</td>
                <td class="py-2.5 text-right font-mono tabular-nums">{{ days(preview.totals.pending) }}</td>
                <td class="py-2.5 text-right font-mono tabular-nums">{{ days(preview.totals.carry) }}</td>
                <td class="py-2.5 text-right font-mono tabular-nums">{{ days(preview.totals.lapsed) }}</td>
                <td class="py-2.5 text-right font-mono tabular-nums">{{ days(preview.totals.encashable) }}</td>
                <td class="py-2.5 pl-4 text-[11px] font-normal text-muted-foreground">
                  will open {{ year + 1 }} with {{ days(preview.totals.carry) }}
                </td>
              </tr>
            </tfoot>
          </table>
        </div>
        <p class="mt-3 flex items-start gap-1.5 text-[11px] text-muted-foreground">
          <Info class="mt-0.5 size-3 shrink-0" />
          Employees counts every active employee × type. A row is only written if the employee is active now;
          a closed row from a previous run is skipped, never overwritten.
        </p>

        <!--
          The table shows a lapse figure per row, which is correct and easy to read straight past: no
          leave type here carries anything, so the "would carry" column is a column of dashes and the
          total reads as a number rather than as a decision. Closing the year with this configuration
          destroys every unused day in the company, and nothing said so in a sentence.
        -->
        <div v-if="preview.totals.lapsed > 0 && preview.totals.carry === 0"
             class="mt-3 rounded-lg border border-amber-300 bg-amber-50 p-3 text-xs text-amber-900">
          <p class="font-semibold">Closing this year would lapse every unused day — {{ days(preview.totals.lapsed) }} of them.</p>
          <p class="mt-1">
            No leave type currently carries forward, so {{ year + 1 }} would open at zero for all {{ formatNumber(preview.totals.employees) }} employees
            and the rest would cease to exist. That is a legitimate policy, and it is also the kind of
            policy that should be chosen on purpose. If any type should carry, turn on
            <span class="font-medium">carry forward</span> (with an optional cap) for it under
            <span class="font-medium">Leave → Types &amp; policy</span> before closing.
          </p>
        </div>
      </EnterpriseCard>

      <!-- Already-closed snapshot -->
      <EnterpriseCard v-if="summary.length" :title="`Closed snapshot for ${year}`" description="What the last close actually wrote.">
        <div class="overflow-x-auto">
          <table class="w-full min-w-[640px] text-sm">
            <thead class="border-b text-[11px] uppercase tracking-wider text-muted-foreground">
              <tr>
                <th class="pb-2 text-left font-semibold">Leave type</th>
                <th class="pb-2 text-right font-semibold">Employees</th>
                <th class="pb-2 text-right font-semibold">Entitlement</th>
                <th class="pb-2 text-right font-semibold">Taken</th>
                <th class="pb-2 text-right font-semibold">Carried</th>
                <th class="pb-2 text-right font-semibold">Lapsed</th>
                <th class="pb-2 text-right font-semibold">Left over</th>
              </tr>
            </thead>
            <tbody class="divide-y">
              <tr v-for="row in summary" :key="row.leave_type_id">
                <td class="py-2 font-medium">{{ row.leave_type }}</td>
                <td class="py-2 text-right tabular-nums">{{ formatNumber(row.emp_count) }}</td>
                <td class="py-2 text-right font-mono tabular-nums">{{ days(row.entitlement) }}</td>
                <td class="py-2 text-right font-mono tabular-nums">{{ days(row.availed) }}</td>
                <td class="py-2 text-right font-mono tabular-nums">{{ days(row.carry) }}</td>
                <td class="py-2 text-right font-mono tabular-nums">{{ days(row.lapsed) }}</td>
                <td class="py-2 text-right font-mono tabular-nums">{{ days(row.available) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </EnterpriseCard>

      <EnterpriseCard v-else title="No snapshot yet" description="Nothing has been closed for this year." :icon="Info">
        <p class="text-sm text-muted-foreground">
          {{ year }} has no frozen balances, so every balance screen is computing live from applications. When
          you close, each employee gets one row per leave type holding their entitlement, what they took, what
          carries and what lapses.
        </p>
      </EnterpriseCard>

      <p class="text-xs text-muted-foreground">
        Encashment is priced per employee from the Types &amp; policy rate basis (basic or gross ÷ 30).
        <Button size="sm" variant="ghost" class="ml-1 h-6 px-1.5 text-xs" @click="router.visit(route('hrm.leave-year-end.encash-preview'))">
          Open the encashment preview <ChevronRight class="size-3" />
        </Button>
      </p>
    </div>
  </AdminLayout>
</template>
