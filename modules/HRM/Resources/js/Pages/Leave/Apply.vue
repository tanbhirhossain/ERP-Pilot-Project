<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { ArrowLeft, CalendarDays, Info, Send, TriangleAlert } from 'lucide-vue-next';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import EnterpriseCard from '@core/Components/EnterpriseCard.vue';
import SearchableSelect from '@core/Components/SearchableSelect.vue';
import ModuleNav from './ModuleNav.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useUnsavedGuard } from '@core/Composables/useUnsavedGuard';
import { formatNumber } from '@core/Utils/format';

/**
 * Apply for leave, or edit a pending application.
 *
 * The form now prices the application before it is submitted. The old one showed four numbers
 * (entitlement / approved / available / after) but never said which days were being charged, so an
 * employee who applied Friday-to-Sunday on a working-day type could not see that the weekend cost
 * nothing until the balance moved.
 *
 * The day strip below the dates is that answer: each date in the range, with the ones the policy
 * excludes marked, and the chargeable total beside it.
 */

interface DateCell { date: string; weekday: number; excluded: 'weekend' | 'holiday' | null }

interface Quote {
  leave_type_id: number;
  leave_type: string;
  year: number;
  opening: number;
  entitlement: number;
  carry_forward: number;
  approved: number;
  pending: number;
  available: number;
  total: number;
  day_count_mode: string;
  accrual_mode: string;
  proration_mode: string;
  approval_steps: string[];
  frozen: boolean;
  requested_days?: number;
  chargeable?: number;
  excluded?: number;
  dates?: DateCell[];
  available_after_request?: number;
  quote_error?: string;
}

const props = defineProps<{
  employeeOptions: Array<{ value: string; label: string }>;
  leaveTypeOptions: Array<{ value: number; label: string; color?: string }>;
  presetEmployee?: string | null;
  leave?: {
    id: number;
    employee_id: string;
    leave_type_id: number | null;
    from_date: string | null;
    to_date: string | null;
    is_half_day: boolean;
    reason: string | null;
    status: string;
  } | null;
}>();

const isEdit = computed(() => Boolean(props.leave));
const today = new Date().toISOString().slice(0, 10);

const form = useForm({
  employee_id: props.leave?.employee_id ?? props.presetEmployee ?? '',
  leave_type_id: (props.leave?.leave_type_id ?? null) as number | null,
  from_date: props.leave?.from_date ?? today,
  to_date: props.leave?.to_date ?? today,
  is_half_day: props.leave?.is_half_day ?? false,
  reason: props.leave?.reason ?? '',
});

useUnsavedGuard(() => form.isDirty);

const quote = ref<Quote | null>(null);
const loading = ref(false);
const error = ref('');

let controller: AbortController | null = null;

watch(
  () => [form.employee_id, form.leave_type_id, form.from_date, form.to_date, form.is_half_day] as const,
  async () => {
    quote.value = null;
    error.value = '';
    if (!form.employee_id || !form.leave_type_id) return;

    controller?.abort();
    controller = new AbortController();
    loading.value = true;

    const params = new URLSearchParams({
      employee_id: String(form.employee_id),
      leave_type_id: String(form.leave_type_id),
      from_date: form.from_date,
      to_date: form.to_date,
      is_half_day: form.is_half_day ? '1' : '0',
    });
    if (props.leave?.id) params.set('ignore_leave_id', String(props.leave.id));

    try {
      const response = await fetch(`${route('hrm.leaves.balance')}?${params}`, {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
        signal: controller.signal,
      });
      const payload = await response.json();
      if (!response.ok) throw new Error(payload.message ?? 'Could not load the balance');
      quote.value = payload as Quote;
      if (payload.quote_error) error.value = payload.quote_error;
    } catch (e: unknown) {
      if ((e as { name?: string })?.name !== 'AbortError') {
        error.value = (e as Error)?.message ?? 'Could not load the balance';
      }
    } finally {
      loading.value = false;
    }
  },
  { immediate: true },
);

const weekdayName = (index: number): string => ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'][index] ?? '';

const submit = (): void => {
  if (isEdit.value && props.leave) {
    form.put(route('hrm.leaves.update', props.leave.id));
  } else {
    form.post(route('hrm.leaves.store'));
  }
};

const modeLabel = (mode: string): string => ({
  calendar_days: 'every calendar day counts',
  working_days: 'only working days count',
}[mode] ?? mode);

const accrualLabel = (mode: string): string => ({
  annual: 'granted once a year',
  monthly: 'accrues monthly',
}[mode] ?? mode);

const prorationLabel = (mode: string): string => ({
  none: 'full-year entitlement',
  monthly: 'pro-rated by months of service',
  daily: 'pro-rated by days of service',
}[mode] ?? mode);
</script>

<template>
  <AdminLayout :breadcrumbs="[{ title: 'Leave' }, { title: 'Requests', href: route('hrm.leaves.requests') }, { title: isEdit ? 'Edit application' : 'New application' }]">
    <Head :title="isEdit ? 'Edit leave application' : 'New leave application'" />

    <div class="space-y-4">
      <ModuleNav />

      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 class="flex items-center gap-2.5 text-xl font-bold tracking-tight">
            <span class="grid size-9 place-items-center rounded-xl bg-gradient-to-br from-primary to-primary/70 text-primary-foreground shadow-sm">
              <CalendarDays class="size-5" />
            </span>
            {{ isEdit ? 'Edit leave application' : 'New leave application' }}
          </h1>
          <p class="mt-1 max-w-3xl text-sm text-muted-foreground">
            The balance panel prices the application as you choose the dates: what it charges, what the policy
            skips, and what would be left afterwards. It is submitted for approval, not granted.
          </p>
        </div>
        <Link :href="route('hrm.leaves.requests')">
          <Button size="sm" variant="outline"><ArrowLeft class="size-4" /> Back to requests</Button>
        </Link>
      </div>

      <form class="grid gap-4 lg:grid-cols-3" @submit.prevent="submit">
        <EnterpriseCard class="lg:col-span-2" title="Application" description="Employee, leave type and dates." :icon="CalendarDays">
          <div class="grid gap-4 sm:grid-cols-2">
            <div class="space-y-1.5">
              <Label>Employee <span class="text-destructive">*</span></Label>
              <SearchableSelect v-model="form.employee_id" :options="employeeOptions" endpoint="/hrm/api/options/employees" placeholder="Search by name or employee ID" :invalid="!!form.errors.employee_id" />
              <p v-if="form.errors.employee_id" class="text-xs text-destructive">{{ form.errors.employee_id }}</p>
            </div>
            <div class="space-y-1.5">
              <Label>Leave type <span class="text-destructive">*</span></Label>
              <SearchableSelect v-model="form.leave_type_id" :options="leaveTypeOptions" placeholder="Choose a leave type" :invalid="!!form.errors.leave_type_id" />
              <p v-if="form.errors.leave_type_id" class="text-xs text-destructive">{{ form.errors.leave_type_id }}</p>
            </div>
            <div class="space-y-1.5">
              <Label>From <span class="text-destructive">*</span></Label>
              <Input v-model="form.from_date" type="date" />
              <p v-if="form.errors.from_date" class="text-xs text-destructive">{{ form.errors.from_date }}</p>
            </div>
            <div class="space-y-1.5">
              <Label>To <span class="text-destructive">*</span></Label>
              <Input v-model="form.to_date" type="date" />
              <p v-if="form.errors.to_date" class="text-xs text-destructive">{{ form.errors.to_date }}</p>
            </div>
          </div>

          <label class="mt-4 flex items-start gap-2 rounded-lg border p-3">
            <input v-model="form.is_half_day" type="checkbox" class="mt-0.5 rounded" />
            <span class="text-sm">
              <span class="font-medium">Half day (0.5)</span>
              <span class="block text-xs text-muted-foreground">Only for a single date. The attendance calendar will show the day as half a day of leave.</span>
            </span>
          </label>

          <div class="mt-4 space-y-1.5">
            <Label>Reason</Label>
            <Textarea v-model="form.reason" rows="3" placeholder="Why is this leave needed? This is what the approver reads." />
            <p v-if="form.errors.reason" class="text-xs text-destructive">{{ form.errors.reason }}</p>
          </div>

          <div class="mt-5 flex justify-end gap-2 border-t pt-4">
            <Link :href="route('hrm.leaves.requests')"><Button type="button" variant="outline">Cancel</Button></Link>
            <Button type="submit" :disabled="form.processing">
              <Send class="size-4" /> {{ form.processing ? 'Submitting…' : isEdit ? 'Save changes' : 'Submit for approval' }}
            </Button>
          </div>
        </EnterpriseCard>

        <!-- Balance panel -->
        <div class="space-y-4">
          <EnterpriseCard title="What this costs" :description="quote ? quote.leave_type : 'Choose an employee and a leave type'">
            <p v-if="loading" class="text-sm text-muted-foreground">Reading the balance…</p>
            <p v-else-if="error" class="flex items-start gap-2 text-sm text-destructive">
              <TriangleAlert class="mt-0.5 size-4 shrink-0" /> {{ error }}
            </p>

            <div v-else-if="quote" class="space-y-3">
              <dl class="space-y-1.5 text-sm">
                <div class="flex items-center justify-between">
                  <dt class="text-muted-foreground">Entitlement {{ quote.year }}</dt>
                  <dd class="font-mono tabular-nums">{{ formatNumber(quote.entitlement) }}d</dd>
                </div>
                <div v-if="quote.carry_forward > 0" class="flex items-center justify-between">
                  <dt class="text-muted-foreground">Carried from {{ quote.year - 1 }}</dt>
                  <dd class="font-mono tabular-nums text-info">+{{ formatNumber(quote.carry_forward) }}d</dd>
                </div>
                <div v-if="quote.opening > 0" class="flex items-center justify-between">
                  <dt class="text-muted-foreground">Opening balance</dt>
                  <dd class="font-mono tabular-nums">+{{ formatNumber(quote.opening) }}d</dd>
                </div>
                <div class="flex items-center justify-between">
                  <dt class="text-muted-foreground">Taken (approved)</dt>
                  <dd class="font-mono tabular-nums">−{{ formatNumber(quote.approved) }}d</dd>
                </div>
                <div v-if="quote.pending > 0" class="flex items-center justify-between">
                  <dt class="text-muted-foreground">Reserved (pending)</dt>
                  <dd class="font-mono tabular-nums">−{{ formatNumber(quote.pending) }}d</dd>
                </div>
                <div class="flex items-center justify-between border-t pt-1.5 font-semibold">
                  <dt>Available now</dt>
                  <dd class="font-mono tabular-nums">{{ formatNumber(quote.available) }}d</dd>
                </div>
              </dl>

              <div v-if="quote.requested_days !== undefined" class="rounded-lg border bg-muted/30 p-3">
                <div class="flex items-baseline justify-between">
                  <span class="text-xs text-muted-foreground">This application charges</span>
                  <span class="text-lg font-bold tabular-nums">{{ formatNumber(quote.requested_days) }}d</span>
                </div>
                <p v-if="quote.excluded" class="mt-1 text-xs text-muted-foreground">
                  {{ formatNumber(quote.excluded) }} day(s) in the range are not charged by this type's policy.
                </p>
                <div class="mt-2 flex items-baseline justify-between border-t pt-2 text-sm">
                  <span class="text-muted-foreground">Balance after approval</span>
                  <span
                    class="font-mono font-semibold tabular-nums"
                    :class="(quote.available_after_request ?? 0) < 0 ? 'text-destructive' : ''"
                  >
                    {{ formatNumber(quote.available_after_request ?? 0) }}d
                  </span>
                </div>
                <p v-if="(quote.available_after_request ?? 0) < 0" class="mt-1 flex items-start gap-1.5 text-xs text-destructive">
                  <TriangleAlert class="mt-0.5 size-3 shrink-0" />
                  More days than the balance holds — this application cannot be submitted as it stands.
                </p>
              </div>

              <div class="rounded-lg border bg-muted/20 p-2.5 text-[11px] leading-relaxed text-muted-foreground">
                <p class="flex items-center gap-1 font-medium text-foreground"><Info class="size-3" /> How this balance is calculated</p>
                <p class="mt-0.5">{{ modeLabel(quote.day_count_mode) }}, {{ accrualLabel(quote.accrual_mode) }}, {{ prorationLabel(quote.proration_mode) }}.</p>
                <p v-if="quote.frozen" class="mt-1 text-info">Frozen by the {{ quote.year }} year-end close — these figures no longer move.</p>
                <p class="mt-1">Approval stages: {{ quote.approval_steps.join(' → ') }}.</p>
              </div>
            </div>

            <p v-else class="text-sm text-muted-foreground">Choose an employee and a leave type to see the balance.</p>
          </EnterpriseCard>

          <!-- Day strip -->
          <EnterpriseCard v-if="quote?.dates?.length" title="Day by day" :description="`${quote.dates.length} date(s) in the range`">
            <div class="flex flex-wrap gap-1">
              <span
                v-for="cell in quote.dates"
                :key="cell.date"
                class="flex min-w-[42px] flex-col items-center rounded-md border px-1.5 py-1 text-center"
                :class="cell.excluded
                  ? 'border-dashed bg-muted/40 text-muted-foreground'
                  : 'border-primary/30 bg-primary/[0.07]'"
                :title="cell.excluded ? `${cell.date} — not charged (${cell.excluded})` : `${cell.date} — charged`"
              >
                <span class="text-[10px] uppercase leading-none text-muted-foreground">{{ weekdayName(cell.weekday) }}</span>
                <span class="text-[13px] font-semibold leading-tight tabular-nums">{{ cell.date.slice(8) }}</span>
                <span v-if="cell.excluded" class="text-[9px] leading-none">{{ cell.excluded === 'holiday' ? 'holiday' : 'weekend' }}</span>
              </span>
            </div>
            <p class="mt-2 text-[11px] text-muted-foreground">
              Dashed dates are excluded by this type's working-day policy. What remains is what the application charges.
            </p>
          </EnterpriseCard>
        </div>
      </form>
    </div>
  </AdminLayout>
</template>
