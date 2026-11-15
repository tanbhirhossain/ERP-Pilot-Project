<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useToast } from '@core/Composables/useToast';
import {
  CalendarDays,
  CircleCheck,
  CircleSlash,
  Info,
  Plus,
  ShieldCheck,
  Tag,
  Users,
} from 'lucide-vue-next';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import EnterpriseCard from '@core/Components/EnterpriseCard.vue';
import ModuleNav from './ModuleNav.vue';
import { Button } from '@/components/ui/button';
import { formatNumber } from '@core/Utils/format';
import { confirmDialog } from '@core/Composables/useConfirm';

/**
 * Leave Types & policy.
 *
 * The old index showed four columns — name, code, days/year, paid — while the settings that actually
 * decide what a leave day costs (working-day base, accrual, pro-rating, carry, encashment, approval
 * chain) sat unread in `leave_type_settings`. Every balance on every screen is downstream of that
 * table, so the screen has to show it.
 *
 * Editing is a separate page; this one is the read model, grouped so a reader can compare policies
 * side by side rather than opening six edit forms.
 */

interface TypeRow {
  id: number;
  name: string;
  code: string | null;
  color: string | null;
  description: string | null;
  days_per_year: number;
  is_paid: boolean;
  is_active: boolean;
  day_count_mode: string;
  accrual_mode: string;
  proration_mode: string;
  working_days: number[];
  approval_steps: string[];
  is_carry_forward: boolean;
  max_carry_days: number | null;
  carry_expiry_months: number;
  is_encashable: boolean;
  encash_max_days: number | null;
  encash_salary_component: string;
  used_days: number;
  request_count: number;
  live_requests: number;
}

const props = defineProps<{ types: TypeRow[]; year: number; canManage: boolean }>();

const toast = useToast();
const busy = ref<number | null>(null);

const weekday = (i: number): string => ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'][i] ?? '';

const weekdaySummary = (days: number[]): string => {
  const sorted = [...days].sort((a, b) => a - b);
  if (sorted.length === 7) return 'every day';
  if (sorted.length === 0) return 'no days';
  const contiguous = sorted.every((d, i) => i === 0 || d === sorted[i - 1]! + 1);
  if (contiguous && sorted.length > 2) return `${weekday(sorted[0]!)}–${weekday(sorted[sorted.length - 1]!)}`;
  return sorted.map(weekday).join(', ');
};

const paidCount = computed(() => props.types.filter((t) => t.is_paid).length);
const carryCount = computed(() => props.types.filter((t) => t.is_carry_forward).length);
const encashCount = computed(() => props.types.filter((t) => t.is_encashable).length);

const remove = async (type: TypeRow): Promise<void> => {
  const proceed = await confirmDialog({
    title: `Delete ${type.name}?`,
    description: type.request_count > 0
      ? `${type.name} has ${type.request_count} application(s) in ${props.year}. It cannot be deleted while history refers to it — deactivate it instead.`
      : `${type.name} has no applications. Deleting removes its policy too.`,
    danger: true,
  });
  if (!proceed) return;
  busy.value = type.id;
  router.delete(route('hrm.leave-types.destroy', type.id), {
    preserveScroll: true,
    onSuccess: () => toast.success(`${type.name} removed.`),
    onError: () => toast.error('Could not delete this leave type.'),
    onFinish: () => (busy.value = null),
  });
};
</script>

<template>
  <AdminLayout :breadcrumbs="[{ title: 'Leave' }, { title: 'Types & policy' }]">
    <Head title="Leave types and policy" />

    <div class="space-y-4">
      <ModuleNav />

      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 class="flex items-center gap-2.5 text-xl font-bold tracking-tight">
            <span class="grid size-9 place-items-center rounded-xl bg-gradient-to-br from-primary to-primary/70 text-primary-foreground shadow-sm">
              <Tag class="size-5" />
            </span>
            Leave types &amp; policy
          </h1>
          <p class="mt-1 max-w-3xl text-sm text-muted-foreground">
            The rules behind every balance: how a leave day is counted, whether entitlement accrues or is
            granted, whether unused days carry forward, and who has to approve. Changing a policy here
            changes what every employee's balance reads from tomorrow.
          </p>
        </div>
        <Button v-if="canManage" size="sm" @click="router.visit(route('hrm.leave-types.create'))">
          <Plus class="size-4" /> New leave type
        </Button>
      </div>

      <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-xl border bg-card px-4 py-3 shadow-sm">
          <p class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Leave types</p>
          <p class="mt-0.5 text-xl font-bold tabular-nums">{{ types.length }}</p>
          <p class="text-xs text-muted-foreground">{{ types.filter((t) => t.is_active).length }} active</p>
        </div>
        <div class="rounded-xl border bg-card px-4 py-3 shadow-sm">
          <p class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Paid types</p>
          <p class="mt-0.5 text-xl font-bold tabular-nums">{{ paidCount }}</p>
          <p class="text-xs text-muted-foreground">{{ types.length - paidCount }} unpaid — those feed payroll deductions</p>
        </div>
        <div class="rounded-xl border bg-card px-4 py-3 shadow-sm">
          <p class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Carry forward</p>
          <p class="mt-0.5 text-xl font-bold tabular-nums">{{ carryCount }}</p>
          <p class="text-xs text-muted-foreground">types move unused days into next year</p>
        </div>
        <div class="rounded-xl border bg-card px-4 py-3 shadow-sm">
          <p class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Encashable</p>
          <p class="mt-0.5 text-xl font-bold tabular-nums">{{ encashCount }}</p>
          <p class="text-xs text-muted-foreground">types can be paid out at settlement</p>
        </div>
      </div>

      <div
        v-if="types.some((t) => t.is_paid && !t.is_carry_forward && t.proration_mode === 'none')"
        class="flex items-start gap-3 rounded-xl border border-info/30 bg-info/[0.06] px-4 py-3"
      >
        <Info class="mt-0.5 size-4 shrink-0 text-info" />
        <p class="text-[13px]">
          A type with <span class="font-medium">no pro-rating and no carry-forward</span> grants the full year
          to everybody who is active today, including people who joined last month, and every unused day lapses
          on 31 December. If that is not the intended policy, edit the type.
        </p>
      </div>

      <div class="space-y-3">
        <EnterpriseCard v-for="type in types" :key="type.id" :title="type.name" :icon="ShieldCheck">
          <template #actions>
            <div class="flex items-center gap-2">
              <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-semibold"
                    :class="type.is_active ? 'bg-success/10 text-success' : 'bg-muted text-muted-foreground'">
                <component :is="type.is_active ? CircleCheck : CircleSlash" class="size-2.5" />
                {{ type.is_active ? 'active' : 'inactive' }}
              </span>
              <Button v-if="canManage" size="sm" variant="outline" class="h-7 px-2.5 text-[12px]" @click="router.visit(route('hrm.leave-types.edit', type.id))">
                Edit policy
              </Button>
              <Button v-if="canManage" size="sm" variant="ghost" class="h-7 px-2 text-[12px] text-danger" :disabled="busy === type.id" @click="remove(type)">
                Delete
              </Button>
            </div>
          </template>

          <div class="grid gap-4 lg:grid-cols-[1fr_2fr]">
            <!-- identity + entitlement -->
            <div class="space-y-2">
              <p class="flex flex-wrap items-center gap-1.5">
                <span class="size-2.5 rounded-full" :style="{ backgroundColor: type.color ?? '#94a3b8' }" />
                <span class="rounded bg-muted px-1.5 py-0.5 text-[10px] font-semibold text-muted-foreground">{{ type.code ?? '—' }}</span>
                <span class="rounded px-1.5 py-0.5 text-[10px] font-semibold" :class="type.is_paid ? 'bg-success/10 text-success' : 'bg-danger/10 text-danger'">
                  {{ type.is_paid ? 'paid' : 'unpaid' }}
                </span>
              </p>
              <p class="text-2xl font-bold tabular-nums">
                {{ formatNumber(type.days_per_year) }}<span class="ml-1 text-sm font-medium text-muted-foreground">days / year</span>
              </p>
              <p class="text-xs text-muted-foreground">
                {{ formatNumber(type.used_days) }}d taken in {{ year }} across {{ formatNumber(type.request_count) }} application(s)
              </p>
              <p v-if="type.description" class="text-xs italic text-muted-foreground">{{ type.description }}</p>
            </div>

            <!-- policy grid -->
            <dl class="grid gap-x-6 gap-y-2 sm:grid-cols-2">
              <div>
                <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Day counting</dt>
                <dd class="text-[13px]">
                  {{ type.day_count_mode === 'working_days' ? 'Working days only' : 'Every calendar day' }}
                  <span v-if="type.day_count_mode === 'working_days'" class="block text-xs text-muted-foreground">
                    week runs {{ weekdaySummary(type.working_days) }}; holidays excluded
                  </span>
                </dd>
              </div>
              <div>
                <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Entitlement</dt>
                <dd class="text-[13px]">
                  {{ type.accrual_mode === 'monthly' ? 'Accrues monthly' : 'Granted once a year' }}
                  <span class="block text-xs text-muted-foreground">
                    {{ type.proration_mode === 'none' ? 'not pro-rated for mid-year joiners'
                      : type.proration_mode === 'monthly' ? 'pro-rated by months of service'
                      : 'pro-rated by days of service' }}
                  </span>
                </dd>
              </div>
              <div>
                <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Carry forward</dt>
                <dd class="text-[13px]">
                  {{ type.is_carry_forward ? `Up to ${type.max_carry_days === null ? 'everything unused' : formatNumber(type.max_carry_days) + ' days'}` : 'No — unused days lapse' }}
                  <span v-if="type.is_carry_forward" class="block text-xs text-muted-foreground">expires after {{ type.carry_expiry_months }} months</span>
                </dd>
              </div>
              <div>
                <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Encashment</dt>
                <dd class="text-[13px]">
                  {{ type.is_encashable ? `Up to ${type.encash_max_days === null ? 'all carried days' : formatNumber(type.encash_max_days) + ' days'}` : 'Not encashable' }}
                  <span v-if="type.is_encashable" class="block text-xs text-muted-foreground">
                    paid at {{ type.encash_salary_component }} ÷ 30 per day
                  </span>
                </dd>
              </div>
              <div class="sm:col-span-2">
                <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Approval chain</dt>
                <dd class="flex flex-wrap items-center gap-1.5 text-[13px]">
                  <template v-for="(step, i) in type.approval_steps" :key="step">
                    <span class="rounded-md bg-muted px-2 py-0.5 text-xs font-medium">{{ step }}</span>
                    <span v-if="i < type.approval_steps.length - 1" class="text-muted-foreground">→</span>
                  </template>
                  <span v-if="type.approval_steps.length === 1" class="text-xs text-muted-foreground">
                    (single stage — one approval grants the leave)
                  </span>
                </dd>
              </div>
            </dl>
          </div>
        </EnterpriseCard>

        <EnterpriseCard v-if="!types.length">
          <div class="py-8 text-center">
            <Users class="mx-auto size-8 text-muted-foreground" />
            <p class="mt-2 text-sm font-medium">No leave types yet</p>
            <p class="mt-1 text-sm text-muted-foreground">Create one to start granting leave.</p>
            <Button v-if="canManage" class="mt-3" size="sm" @click="router.visit(route('hrm.leave-types.create'))">
              <Plus class="size-4" /> New leave type
            </Button>
          </div>
        </EnterpriseCard>
      </div>

      <p class="flex items-start gap-1.5 text-xs text-muted-foreground">
        <CalendarDays class="mt-0.5 size-3.5 shrink-0" />
        Balances are recalculated from these policies on every read. A snapshot taken by a year-end close
        is frozen and does not move when a policy changes afterwards.
      </p>
    </div>
  </AdminLayout>
</template>
