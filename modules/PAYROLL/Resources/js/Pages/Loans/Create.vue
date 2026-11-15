<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { ArrowLeft, BadgeCheck, CalendarClock, CircleAlert, HandCoins, Info } from 'lucide-vue-next';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import ModuleNav from './ModuleNav.vue';
import EmployeeSearchSelect from '../../Components/EmployeeSearchSelect.vue';
import { useUnsavedGuard } from '@core/Composables/useUnsavedGuard';
import { formatCurrency } from '@core/Utils/format';

/**
 * Request an advance.
 *
 * The schedule is previewed before it is submitted, because the number an employee actually cares
 * about is the monthly deduction, not the principal. Showing it here means a request that would
 * leave someone with an unaffordable deduction is visible *before* it enters an approval queue,
 * rather than after someone has signed off on it.
 *
 * The last installment absorbs the rounding remainder, so the schedule always sums exactly to the
 * principal — a cent lost across ten installments is a reconciliation problem in eleven places.
 */

interface EmployeeOption { value: string; label: string; description?: string }

const props = defineProps<{ employeeOptions: EmployeeOption[] }>();

const money = (value: number): string => formatCurrency(Number.isFinite(value) ? value : 0);

const form = useForm({
  employee_id: '',
  principal_amount: 0,
  installment_count: 12,
  first_due_date: new Date().toISOString().slice(0, 10),
  purpose: '',
});

useUnsavedGuard(() => form.isDirty);

const monthly = computed(() => {
  const principal = Number(form.principal_amount) || 0;
  const count = Math.max(1, Number(form.installment_count) || 1);
  return principal / count;
});

/** Mirrors the server: every installment is the standard amount, the last one takes the remainder. */
const schedule = computed(() => {
  const principal = Math.round((Number(form.principal_amount) || 0) * 100) / 100;
  const count = Math.max(1, Math.min(120, Number(form.installment_count) || 1));
  const standard = Math.round((principal / count) * 100) / 100;
  const rows: { number: number; date: string; amount: number }[] = [];
  const start = form.first_due_date ? new Date(form.first_due_date) : null;
  let remaining = principal;
  for (let i = 1; i <= count && i <= 6; i++) {
    const amount = i === count ? Math.round(remaining * 100) / 100 : Math.min(remaining, standard);
    const date = start ? new Date(start.getFullYear(), start.getMonth() + (i - 1), start.getDate()) : null;
    rows.push({
      number: i,
      date: date ? date.toISOString().slice(0, 10) : '—',
      amount,
    });
    remaining = Math.round((remaining - amount) * 100) / 100;
  }
  return { rows, standard, remainder: count > 6 ? count - 6 : 0 };
});

const finalInstallment = computed(() => {
  const principal = Math.round((Number(form.principal_amount) || 0) * 100) / 100;
  const count = Math.max(1, Math.min(120, Number(form.installment_count) || 1));
  const standard = Math.round((principal / count) * 100) / 100;
  return Math.round((principal - standard * (count - 1)) * 100) / 100;
});

const hasRemainder = computed(() => Math.abs(finalInstallment.value - schedule.value.standard) >= 0.01);

function submit(): void {
  form.post(route('payroll.loans.store'));
}
</script>

<template>
  <AdminLayout :breadcrumbs="[{ title: 'Payroll' }, { title: 'Staff advances', href: route('payroll.loans.index') }, { title: 'New request' }]">
    <Head title="New advance request" />

    <div class="space-y-4">
      <ModuleNav :can-manage="true" />

      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <button type="button" class="mb-1 flex items-center gap-1 text-[11px] text-muted-foreground hover:text-foreground" @click="router.visit(route('payroll.loans.register'))">
            <ArrowLeft class="size-3" />Register
          </button>
          <h1 class="flex items-center gap-2.5 text-xl font-bold tracking-tight">
            <span class="grid size-9 place-items-center rounded-xl bg-gradient-to-br from-primary to-primary/70 text-primary-foreground shadow-sm">
              <HandCoins class="size-5" />
            </span>
            Request an advance
          </h1>
          <p class="mt-1 text-sm text-muted-foreground">
            Interest is fixed at 0%. The principal is split into equal monthly installments and recovered one per payroll month once approved.
          </p>
        </div>
      </div>

      <div class="grid items-start gap-4 xl:grid-cols-3">
        <form class="rounded-xl border bg-card shadow-sm xl:col-span-2" @submit.prevent="submit">
          <div class="border-b px-5 py-4">
            <h2 class="text-sm font-semibold">Request</h2>
            <p class="mt-0.5 text-xs text-muted-foreground">Every field is required, including the purpose — it is what the approver reads first.</p>
          </div>

          <div class="space-y-4 p-5">
            <div class="space-y-2">
              <Label>Employee</Label>
              <EmployeeSearchSelect v-model="form.employee_id" :options="props.employeeOptions" />
              <p v-if="form.errors.employee_id" class="text-xs text-destructive">{{ form.errors.employee_id }}</p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
              <div class="space-y-2">
                <Label>Advance amount</Label>
                <Input v-model.number="form.principal_amount" type="number" min="0.01" max="9999999999.99" step="0.01" />
                <p v-if="form.errors.principal_amount" class="text-xs text-destructive">{{ form.errors.principal_amount }}</p>
              </div>
              <div class="space-y-2">
                <Label>Monthly installments</Label>
                <Input v-model.number="form.installment_count" type="number" min="1" max="120" step="1" />
                <p v-if="form.errors.installment_count" class="text-xs text-destructive">{{ form.errors.installment_count }}</p>
              </div>
            </div>

            <div class="space-y-2">
              <Label>First due date</Label>
              <Input v-model="form.first_due_date" type="date" />
              <p class="text-xs text-muted-foreground">The month of the first deduction. Later installments fall on the same day of the following months.</p>
              <p v-if="form.errors.first_due_date" class="text-xs text-destructive">{{ form.errors.first_due_date }}</p>
            </div>

            <div class="space-y-2">
              <Label>Purpose (kept for audit)</Label>
              <textarea
                v-model="form.purpose"
                required
                minlength="5"
                maxlength="1000"
                rows="3"
                class="w-full rounded-md border bg-background p-3 text-sm"
                placeholder="What is this advance for?"
              />
              <p v-if="form.errors.purpose" class="text-xs text-destructive">{{ form.errors.purpose }}</p>
            </div>

            <p v-if="form.errors.principal_amount === undefined && Object.keys(form.errors).length" class="rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive">
              {{ Object.values(form.errors).join(' ') }}
            </p>
          </div>

          <div class="flex flex-wrap items-center gap-3 border-t px-5 py-4">
            <Button :disabled="form.processing">{{ form.processing ? 'Submitting…' : 'Submit for approval' }}</Button>
            <Button type="button" variant="outline" :disabled="form.processing" @click="router.visit(route('payroll.loans.register'))">Cancel</Button>
            <p class="text-xs text-muted-foreground">Nothing is deducted until the advance is approved and a payroll month stages it.</p>
          </div>
        </form>

        <div class="space-y-4">
          <section class="rounded-xl border bg-card shadow-sm">
            <div class="border-b px-5 py-4">
              <h2 class="flex items-center gap-2 text-sm font-semibold"><CalendarClock class="size-4 text-muted-foreground" />Schedule preview</h2>
              <p class="mt-0.5 text-xs text-muted-foreground">What the monthly deduction will be.</p>
            </div>
            <div class="px-5 py-4">
              <p class="text-2xl font-bold tabular-nums tracking-tight">{{ money(schedule.standard) }}<span class="ml-1 text-sm font-normal text-muted-foreground">/ month</span></p>
              <p class="mt-1 text-xs text-muted-foreground">for {{ Math.max(1, Number(form.installment_count) || 1) }} month(s)</p>

              <table v-if="schedule.rows.length" class="mt-3 w-full text-[12px]">
                <thead>
                  <tr class="border-b text-left text-[10px] uppercase tracking-wide text-muted-foreground">
                    <th class="py-1.5 font-medium">#</th>
                    <th class="py-1.5 font-medium">Due</th>
                    <th class="py-1.5 text-right font-medium">Amount</th>
                  </tr>
                </thead>
                <tbody class="divide-y">
                  <tr v-for="row in schedule.rows" :key="row.number">
                    <td class="py-1.5 tabular-nums text-muted-foreground">{{ row.number }}</td>
                    <td class="py-1.5 tabular-nums">{{ row.date }}</td>
                    <td class="py-1.5 text-right tabular-nums">{{ money(row.amount) }}</td>
                  </tr>
                </tbody>
              </table>
              <p v-if="schedule.remainder > 0" class="mt-2 text-[11px] text-muted-foreground">+ {{ schedule.remainder }} more installment(s)</p>

              <p v-if="hasRemainder" class="mt-3 flex items-start gap-1.5 rounded-lg bg-muted/60 px-2.5 py-2 text-[11px] leading-snug text-muted-foreground">
                <Info class="mt-px size-3 shrink-0" />
                <span>The principal does not divide evenly, so the final installment is {{ money(finalInstallment) }} instead of {{ money(schedule.standard) }}. The schedule still sums exactly to the advance.</span>
              </p>
            </div>
          </section>

          <section class="rounded-xl border bg-card shadow-sm">
            <div class="border-b px-5 py-4">
              <h2 class="flex items-center gap-2 text-sm font-semibold"><BadgeCheck class="size-4 text-muted-foreground" />What happens next</h2>
            </div>
            <ol class="space-y-2.5 px-5 py-4 text-[12px] leading-snug text-muted-foreground">
              <li class="flex gap-2"><span class="font-semibold text-foreground">1.</span><span>The request goes to the configured approval stage(s). A requester can never approve their own advance.</span></li>
              <li class="flex gap-2"><span class="font-semibold text-foreground">2.</span><span>On final approval the repayment schedule is created — one installment per month from the first due date.</span></li>
              <li class="flex gap-2"><span class="font-semibold text-foreground">3.</span><span>Before each payroll month, an installment is staged. One per advance per month, no matter how many are behind.</span></li>
              <li class="flex gap-2"><span class="font-semibold text-foreground">4.</span><span>Payroll takes the staged amount from the payslip and marks the installment paid.</span></li>
            </ol>
            <p class="flex items-start gap-1.5 border-t px-5 py-3 text-[11px] leading-snug text-muted-foreground">
              <CircleAlert class="mt-px size-3 shrink-0" />
              <span>Advances are strictly interest-free. The system refuses to create a schedule that charges interest.</span>
            </p>
          </section>
        </div>
      </div>
    </div>
  </AdminLayout>
</template>
