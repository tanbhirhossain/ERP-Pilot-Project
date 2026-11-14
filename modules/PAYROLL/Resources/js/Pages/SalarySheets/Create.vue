<script setup lang="ts">
import { Head, useForm} from '@inertiajs/vue3';
import { computed } from 'vue';
import type { SelectOption } from '@core/types';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import EmployeeMultiSearchSelect from '../../Components/EmployeeMultiSearchSelect.vue';

import { useUnsavedGuard } from '@core/Composables/useUnsavedGuard';
const p = defineProps<{ defaultYear: number; defaultMonth: number; defaultIncludePunishments: boolean; employeeOptions: SelectOption[]; payrollWarnings: Array<{employee_id:string;employee_name:string|null;department:string|null;joining_date:string|null;reason:string}> }>();
const form = useForm({
  pay_year: p.defaultYear,
  pay_month: p.defaultMonth,
  include_punishments: p.defaultIncludePunishments,
  excluded_employee_ids: [] as string[],
  exclusion_reason: '',
});
useUnsavedGuard(() => form.isDirty);
// Catch-all for validation errors that have no bound field on this page — e.g. the cutlist review gate
// rejects with key `payroll_cutlist_id`, and a queue button that silently does nothing is how payroll
// desks lose a day. Keys already rendered inline are excluded so they never show twice.
const BOUND_KEYS = ['pay_year', 'pay_month', 'include_punishments', 'excluded_employee_ids', 'exclusion_reason'];
const looseErrors = computed(() => Object.entries(form.errors).filter(([key]) => !BOUND_KEYS.some((k) => key === k || key.startsWith(`${k}.`))));

function submit() {
  form.post(route('payroll.sheets.store'));
}
</script>

<template>
  <AdminLayout :breadcrumbs="[{ title: 'Payroll' }, { title: 'Salary Sheets', href: route('payroll.sheets.index') }, { title: 'New run' }]">
    <Head title="New Payroll Run" />
    <div class="max-w-3xl rounded-xl border bg-card p-6">
      <PageHeader title="Create monthly payroll" />
      <p class="mt-1 text-sm text-muted-foreground">This queues attendance cutlist generation and calculates eligible HRM employees in database transactions, 50 per chunk.</p>
      <p v-if="payrollWarnings.length" class="mt-2 rounded-lg border border-amber-300/60 bg-amber-500/5 p-3 text-xs text-amber-800">
        <strong>{{ payrollWarnings.length }} active employee(s) will be left out</strong> because no HRM salary record covers this month — payroll skips them rather than failing the whole sheet.
        <span class="block mt-1 font-mono">{{ payrollWarnings.slice(0, 8).map(w => w.employee_id).join(', ') }}{{ payrollWarnings.length > 8 ? ' …' : '' }}</span>
      </p>
      <form @submit.prevent="submit" class="mt-6 space-y-4">
        <div class="grid grid-cols-2 gap-4">
          <div class="space-y-2">
            <Label>Year</Label>
            <Input v-model.number="form.pay_year" type="number" min="2000" max="2100" />
            <p v-if="form.errors.pay_year" class="text-xs text-destructive">{{ form.errors.pay_year }}</p>
          </div>
          <div class="space-y-2">
            <Label>Month</Label>
            <Input v-model.number="form.pay_month" type="number" min="1" max="12" />
            <p v-if="form.errors.pay_month" class="text-xs text-destructive">{{ form.errors.pay_month }}</p>
          </div>
        </div>

        <label class="flex items-start gap-3 rounded-lg border p-3">
          <input v-model="form.include_punishments" type="checkbox" class="mt-1 size-4 rounded" />
          <span><strong>Include late/early punishment</strong><span class="block text-xs text-muted-foreground">Only employees with HRM is_punishable enabled are affected; threshold is configured in Payroll Settings. Default is without punishment.</span></span>
        </label>
        <p v-if="form.errors.include_punishments" class="text-xs text-destructive">{{ form.errors.include_punishments }}</p>

        <details class="rounded-lg border p-3">
          <summary class="cursor-pointer text-sm font-medium">Exclude employees from this period (documented exceptions only)</summary>
          <p class="mt-2 text-xs text-muted-foreground">Exclusions apply only to this pay month and are recorded in the calculation snapshot with the reason. They cannot be added after the sheet snapshot or slips exist.</p>
          <div class="mt-3 space-y-3">
            <div>
              <Label>Employees to exclude</Label>
              <EmployeeMultiSearchSelect v-model="form.excluded_employee_ids" :options="employeeOptions" placeholder="Search employee name or ID…" />
              <p v-if="form.errors.excluded_employee_ids" class="text-xs text-destructive">{{ form.errors.excluded_employee_ids }}</p>
              <p v-if="form.errors['excluded_employee_ids.0']" class="text-xs text-destructive">{{ form.errors['excluded_employee_ids.0'] }}</p>
            </div>
            <div>
              <Label>Documented reason</Label>
              <textarea v-model="form.exclusion_reason" :required="form.excluded_employee_ids.length > 0" minlength="3" maxlength="1000" class="min-h-20 w-full rounded-md border bg-background p-3" placeholder="e.g. Approved unpaid/no-pay period" />
              <p v-if="form.errors.exclusion_reason" class="text-xs text-destructive">{{ form.errors.exclusion_reason }}</p>
            </div>
          </div>
        </details>

        <ul v-if="looseErrors.length" class="rounded-lg border border-amber-500/40 bg-amber-500/10 p-3 text-sm text-amber-800 dark:text-amber-300">
          <li v-for="[key, message] in looseErrors" :key="key">{{ message }}</li>
        </ul>
        <div class="rounded-lg bg-muted/50 p-3 text-sm">Existing paid slips are never rewritten. Exclusions can be added only before an employee snapshot or slips exist; retrying a failed sheet otherwise reuses its original snapshot.</div>
        <Button :disabled="form.processing">Queue payroll</Button>
      </form>
    </div>
  </AdminLayout>
</template>
