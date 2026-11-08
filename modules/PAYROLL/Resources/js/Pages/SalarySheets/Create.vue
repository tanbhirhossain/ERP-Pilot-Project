<script setup lang="ts">
import { Head, useForm} from '@inertiajs/vue3';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import EmployeeMultiSearchSelect from '../../Components/EmployeeMultiSearchSelect.vue';

const p = defineProps<{ defaultYear: number; defaultMonth: number; defaultIncludePunishments: boolean; employeeOptions: any[] }>();
const form = useForm({
  pay_year: p.defaultYear,
  pay_month: p.defaultMonth,
  include_punishments: p.defaultIncludePunishments,
  excluded_employee_ids: [] as string[],
  exclusion_reason: '',
});

function submit() {
  form.post(route('payroll.sheets.store'));
}
</script>

<template>
  <AdminLayout :breadcrumbs="[{ title: 'Payroll' }, { title: 'Salary Sheets', href: route('payroll.sheets.index') }, { title: 'New run' }]">
    <Head title="New Payroll Run" />
    <div class="max-w-3xl rounded-xl border bg-card p-6">
      <h1 class="text-xl font-bold">Create monthly payroll</h1>
      <p class="mt-1 text-sm text-muted-foreground">This queues attendance cutlist generation and calculates eligible HRM employees in database transactions, 50 per chunk.</p>
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

        <div class="rounded-lg bg-muted/50 p-3 text-sm">Existing paid slips are never rewritten. Exclusions can be added only before an employee snapshot or slips exist; retrying a failed sheet otherwise reuses its original snapshot.</div>
        <Button :disabled="form.processing">Queue payroll</Button>
      </form>
    </div>
  </AdminLayout>
</template>
