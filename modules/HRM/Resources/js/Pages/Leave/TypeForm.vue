<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { ArrowLeft, Info, Save, ShieldCheck, Tag } from 'lucide-vue-next';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import EnterpriseCard from '@core/Components/EnterpriseCard.vue';
import ModuleNav from './ModuleNav.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useUnsavedGuard } from '@core/Composables/useUnsavedGuard';
import { formatNumber } from '@core/Utils/format';

/**
 * Create or edit one leave type and its policy — on one page, because the entitlement and the rule
 * that counts it are the same decision. The old form split them: `days_per_year` lived on the type
 * form, and day-counting/accrual lived on a separate year-end screen.
 */

interface TypeShape {
  id: number;
  name: string;
  code: string | null;
  days_per_year: number;
  is_paid: boolean;
  color: string | null;
  description: string | null;
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
  gender_eligibility?: string | null;
  live_requests: number;
}

const props = defineProps<{
  type: TypeShape | null;
  preview?: { employee: string | null; balance?: Record<string, unknown> } | null;
}>();

const isEdit = computed(() => props.type !== null);

const form = useForm({
  name: props.type?.name ?? '',
  code: props.type?.code ?? '',
  days_per_year: props.type?.days_per_year ?? 14,
  is_paid: props.type?.is_paid ?? true,
  color: props.type?.color ?? '#6366f1',
  description: props.type?.description ?? '',
  is_active: props.type?.is_active ?? true,
  day_count_mode: props.type?.day_count_mode ?? 'calendar_days',
  accrual_mode: props.type?.accrual_mode ?? 'annual',
  proration_mode: props.type?.proration_mode ?? 'none',
  working_days: props.type?.working_days ?? [0, 1, 2, 3, 4],
  approval_steps_text: (props.type?.approval_steps ?? ['HR']).join(', '),
  is_carry_forward: props.type?.is_carry_forward ?? false,
  gender_eligibility: (props.type as any)?.gender_eligibility ?? null,
  max_carry_days: props.type?.max_carry_days ?? null,
  carry_expiry_months: props.type?.carry_expiry_months ?? 3,
  is_encashable: props.type?.is_encashable ?? false,
  encash_max_days: props.type?.encash_max_days ?? null,
  encash_salary_component: props.type?.encash_salary_component ?? 'basic',
});

useUnsavedGuard(() => form.isDirty);

const weekdays = [
  { value: 0, label: 'Sun' }, { value: 1, label: 'Mon' }, { value: 2, label: 'Tue' },
  { value: 3, label: 'Wed' }, { value: 4, label: 'Thu' }, { value: 5, label: 'Fri' }, { value: 6, label: 'Sat' },
];

const toggleDay = (day: number): void => {
  form.working_days = form.working_days.includes(day)
    ? form.working_days.filter((d) => d !== day)
    : [...form.working_days, day].sort((a, b) => a - b);
};

const submit = (): void => {
  // The controller expects an array of stage labels.
  form.transform((data) => ({
    ...data,
    approval_steps: data.approval_steps_text.split(',').map((s) => s.trim()).filter(Boolean),
  }));

  if (isEdit.value && props.type) {
    form.put(route('hrm.leave-types.update', props.type.id));
  } else {
    form.post(route('hrm.leave-types.store'));
  }
};

/** A plain-language summary of what the form currently says — the sentence an employee would be told. */
const summary = computed(() => {
  const days = form.days_per_year;
  const counting = form.day_count_mode === 'working_days'
    ? 'working days only (weekends and holidays are not charged)'
    : 'every calendar day in the range';
  const accrual = form.accrual_mode === 'monthly' ? 'accrues monthly' : 'granted at the start of the year';
  const proration = form.proration_mode === 'none' ? 'with no pro-rating for mid-year joiners'
    : form.proration_mode === 'monthly' ? 'pro-rated by months of service' : 'pro-rated by days of service';
  const carry = form.is_carry_forward
    ? `up to ${form.max_carry_days ?? 'everything unused'} day(s) carry into the next year`
    : 'unused days lapse at year-end';
  const chain = form.approval_steps_text.split(',').map((s) => s.trim()).filter(Boolean).join(' → ') || 'HR';

  return `“${form.name || 'This type'}” grants ${days} day(s) per year, ${accrual} ${proration}, counted on ${counting}. ${carry}. Approved by ${chain}.${form.is_paid ? '' : ' Days are unpaid and will be deducted by payroll.'}`;
});
</script>

<template>
  <AdminLayout :breadcrumbs="[{ title: 'Leave' }, { title: 'Types & policy', href: route('hrm.leave-types.index') }, { title: isEdit ? 'Edit' : 'New' }]">
    <Head :title="isEdit ? `Edit ${type?.name}` : 'New leave type'" />

    <div class="space-y-4">
      <ModuleNav />

      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 class="flex items-center gap-2.5 text-xl font-bold tracking-tight">
            <span class="grid size-9 place-items-center rounded-xl bg-gradient-to-br from-primary to-primary/70 text-primary-foreground shadow-sm">
              <Tag class="size-5" />
            </span>
            {{ isEdit ? `Edit ${type?.name}` : 'New leave type' }}
          </h1>
          <p class="mt-1 max-w-3xl text-sm text-muted-foreground">
            The entitlement and the rule that counts it are one decision. Saving recalculates every balance
            this type feeds.
          </p>
        </div>
        <Link :href="route('hrm.leave-types.index')">
          <Button size="sm" variant="outline"><ArrowLeft class="size-4" /> Back to types</Button>
        </Link>
      </div>

      <form class="grid gap-4 lg:grid-cols-3" @submit.prevent="submit">
        <div class="space-y-4 lg:col-span-2">
          <EnterpriseCard title="Identity" description="What employees see when they apply." :icon="Tag">
            <div class="grid gap-4 sm:grid-cols-3">
              <div class="space-y-1.5 sm:col-span-2">
                <Label>Name <span class="text-destructive">*</span></Label>
                <Input v-model="form.name" placeholder="e.g. Casual Leave" />
                <p v-if="form.errors.name" class="text-xs text-destructive">{{ form.errors.name }}</p>
              </div>
              <div class="space-y-1.5">
                <Label>Code</Label>
                <Input v-model="form.code" placeholder="CL" maxlength="10" />
                <p v-if="form.errors.code" class="text-xs text-destructive">{{ form.errors.code }}</p>
              </div>
              <div class="space-y-1.5">
                <Label>Days per year <span class="text-destructive">*</span></Label>
                <Input v-model.number="form.days_per_year" type="number" min="0" max="365" step="0.5" />
                <p v-if="form.errors.days_per_year" class="text-xs text-destructive">{{ form.errors.days_per_year }}</p>
              </div>
              <div class="space-y-1.5">
                <Label>Paid?</Label>
                <select v-model="form.is_paid" class="h-9 w-full rounded-md border bg-background px-3 text-sm">
                  <option :value="true">Paid — payroll does not deduct</option>
                  <option :value="false">Unpaid — payroll deducts the days</option>
                </select>
                <p class="text-[11px] text-muted-foreground">Unpaid leave feeds the payroll cutlist's unpaid-leave deduction.</p>
              </div>
              <div class="space-y-1.5">
                <Label>Colour</Label>
                <Input v-model="form.color" type="color" class="h-9 p-1" />
              </div>
            </div>
            <div class="mt-4 space-y-1.5">
              <Label>Description</Label>
              <Textarea v-model="form.description" rows="2" placeholder="Policy note shown beside the type." />
            </div>
            <label class="mt-3 flex items-center gap-2 text-sm">
              <input v-model="form.is_active" type="checkbox" class="rounded" />
              Active — appears in the application form and the balance register
            </label>
          </EnterpriseCard>

          <EnterpriseCard title="How a day is counted" description="This decides the number on every application and every balance." :icon="ShieldCheck">
            <div class="grid gap-4 sm:grid-cols-2">
              <div class="space-y-1.5">
                <Label>Day counting</Label>
                <select v-model="form.day_count_mode" class="h-9 w-full rounded-md border bg-background px-3 text-sm">
                  <option value="calendar_days">Every calendar day</option>
                  <option value="working_days">Working days only (holidays excluded)</option>
                </select>
              </div>
              <div class="space-y-1.5">
                <Label>Entitlement</Label>
                <select v-model="form.accrual_mode" class="h-9 w-full rounded-md border bg-background px-3 text-sm">
                  <option value="annual">Granted once a year</option>
                  <option value="monthly">Accrues month by month</option>
                </select>
              </div>
              <div class="space-y-1.5 sm:col-span-2">
                <Label>Mid-year joiners</Label>
                <select v-model="form.proration_mode" class="h-9 w-full rounded-md border bg-background px-3 text-sm">
                  <option value="none">No pro-rating — full-year entitlement</option>
                  <option value="monthly">Pro-rate by months of service</option>
                  <option value="daily">Pro-rate by days of service</option>
                </select>
                <p class="text-[11px] text-muted-foreground">
                  With no pro-rating, somebody who joins in December receives the same entitlement as somebody
                  who worked the whole year.
                </p>
              </div>

              <!--
                Maternity (180 days) and paternity (7 days) were being granted to every employee, because
                nothing here could say who a type was for. Restricting a type now subtracts it from the
                balance of everyone it does not apply to — which is the difference between a register of
                what people may book and a list of numbers.
              -->
              <div class="space-y-1.5 sm:col-span-2">
                <Label>Who may take this leave</Label>
                <select v-model="form.gender_eligibility" class="h-9 w-full rounded-md border bg-background px-3 text-sm">
                  <option :value="null">Everyone</option>
                  <option value="Female">Female employees only</option>
                  <option value="Male">Male employees only</option>
                </select>
                <p class="text-[11px] text-muted-foreground">
                  Only matters for types that are reserved for one group, such as maternity or paternity.
                  Employees whose gender is not recorded keep their existing entitlement rather than
                  losing leave to a blank field.
                </p>
              </div>
            </div>

            <div v-if="form.day_count_mode === 'working_days'" class="mt-4">
              <Label>Working week</Label>
              <div class="mt-1.5 flex flex-wrap gap-1.5">
                <button
                  v-for="day in weekdays"
                  :key="day.value"
                  type="button"
                  class="rounded-md border px-2.5 py-1 text-xs font-medium transition"
                  :class="form.working_days.includes(day.value)
                    ? 'border-primary/40 bg-primary/10 text-primary'
                    : 'bg-card text-muted-foreground hover:bg-muted'"
                  @click="toggleDay(day.value)"
                >
                  {{ day.label }}
                </button>
              </div>
              <p class="mt-1 text-[11px] text-muted-foreground">
                Selected days are the working week. Dates outside it, and any holiday (global or this
                employee's department), are not charged.
                <span v-if="form.working_days.length === 0" class="font-medium text-warning"> Nothing is selected, so this type would charge zero days.</span>
              </p>
            </div>

            <div class="mt-4 space-y-1.5">
              <Label>Approval chain</Label>
              <Input v-model="form.approval_steps_text" placeholder="HR, Department Head, Managing Director" />
              <p class="text-[11px] text-muted-foreground">
                Comma-separated stage names, applied in order. An application needs every stage, and the last
                stage re-checks the employee's balance before it is granted.
              </p>
            </div>
          </EnterpriseCard>

          <EnterpriseCard title="Year-end" description="What happens to days nobody used." :icon="ShieldCheck">
            <label class="flex items-start gap-2">
              <input v-model="form.is_carry_forward" type="checkbox" class="mt-0.5 rounded" />
              <span class="text-sm">
                <span class="font-medium">Carry unused days into the next year</span>
                <span class="block text-xs text-muted-foreground">Anything over the cap below lapses at close.</span>
              </span>
            </label>
            <div v-if="form.is_carry_forward" class="mt-3 grid gap-3 sm:grid-cols-2">
              <div class="space-y-1.5">
                <Label>Maximum carried days</Label>
                <Input v-model.number="form.max_carry_days" type="number" min="0" max="365" step="0.5" placeholder="Leave blank for no cap" />
              </div>
              <div class="space-y-1.5">
                <Label>Carried days expire after (months)</Label>
                <Input v-model.number="form.carry_expiry_months" type="number" min="1" max="24" />
              </div>
            </div>

            <label class="mt-4 flex items-start gap-2">
              <input v-model="form.is_encashable" type="checkbox" class="mt-0.5 rounded" />
              <span class="text-sm">
                <span class="font-medium">Encashable at settlement</span>
                <span class="block text-xs text-muted-foreground">Unused carried days can be paid out when the employee leaves.</span>
              </span>
            </label>
            <div v-if="form.is_encashable" class="mt-3 grid gap-3 sm:grid-cols-2">
              <div class="space-y-1.5">
                <Label>Maximum encashable days</Label>
                <Input v-model.number="form.encash_max_days" type="number" min="0" max="365" step="0.5" placeholder="Leave blank for all carried days" />
              </div>
              <div class="space-y-1.5">
                <Label>Rate basis</Label>
                <select v-model="form.encash_salary_component" class="h-9 w-full rounded-md border bg-background px-3 text-sm">
                  <option value="basic">Basic salary ÷ 30</option>
                  <option value="gross">Gross salary ÷ 30</option>
                </select>
              </div>
            </div>
          </EnterpriseCard>
        </div>

        <div class="space-y-4">
          <EnterpriseCard title="In plain words" description="How the policy reads once saved." :icon="Info">
            <p class="text-[13px] leading-relaxed">{{ summary }}</p>
          </EnterpriseCard>

          <EnterpriseCard v-if="type && type.live_requests > 0" title="Careful" :icon="Info">
            <p class="text-[13px]">
              <span class="font-semibold">{{ type.live_requests }}</span> live application(s) already use this
              type. Changing its day-counting or entitlement recalculates the balance behind them; pending
              applications keep the day count they were submitted with until they are edited or approved.
            </p>
          </EnterpriseCard>

          <div class="flex flex-col gap-2">
            <Button type="submit" :disabled="form.processing">
              <Save class="size-4" /> {{ form.processing ? 'Saving…' : isEdit ? 'Save policy' : 'Create leave type' }}
            </Button>
            <Link :href="route('hrm.leave-types.index')"><Button type="button" variant="outline" class="w-full">Cancel</Button></Link>
          </div>

          <p v-if="form.errors.approval_steps" class="text-xs text-destructive">{{ form.errors.approval_steps }}</p>
          <p v-if="form.errors.working_days" class="text-xs text-destructive">{{ form.errors.working_days }}</p>
        </div>
      </form>
    </div>
  </AdminLayout>
</template>
