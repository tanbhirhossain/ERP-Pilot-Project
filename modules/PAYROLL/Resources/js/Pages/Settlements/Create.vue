<script setup lang="ts">
import EmployeeSearchSelect from '../../Components/EmployeeSearchSelect.vue';
import type { SelectOption } from '@core/types';
import { Head, useForm } from '@inertiajs/vue3';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

import { useUnsavedGuard } from '@core/Composables/useUnsavedGuard';
defineProps<{ employeeOptions: SelectOption[] }>();
const f = useForm({
  employee_id: '',
  settlement_date: new Date().toISOString().slice(0, 10),
  last_working_date: new Date().toISOString().slice(0, 10),
  leave_encashment: 0,
  leave_encashment_reason: '',
  other_benefits: 0,
  other_benefits_reason: '',
  deductions: 0,
  deductions_reason: '',
});
useUnsavedGuard(() => f.isDirty);
function save() { f.post(route('payroll.settlements.store')); }
</script>

<template>
  <AdminLayout :breadcrumbs="[{ title: 'Payroll' }, { title: 'Settlements', href: route('payroll.settlements.index') }, { title: 'New' }]">
    <Head title="Calculate final settlement" />
    <div class="max-w-4xl rounded-xl border bg-card p-6">
      <PageHeader title="Calculate final settlement" />
      <p class="mt-1 text-sm text-muted-foreground">Salary and gratuity are calculated from HRM salary history and the active gratuity policy. Leave encashment and manual adjustments require review and a reason.</p>
      <form @submit.prevent="save" class="mt-5 space-y-4">
        <div><Label>Employee</Label><EmployeeSearchSelect v-model="f.employee_id" :options="employeeOptions" /></div>
        <div class="grid grid-cols-2 gap-4">
          <div><Label>Last working date</Label><Input v-model="f.last_working_date" type="date" /></div>
          <div><Label>Settlement date</Label><Input v-model="f.settlement_date" type="date" /></div>
        </div>
        <div class="space-y-3 rounded-lg border p-4">
          <h2 class="font-semibold">Manually reviewed amounts</h2>
          <div><Label>Leave encashment</Label><Input v-model.number="f.leave_encashment" type="number" min="0" step="0.01" /><textarea v-model="f.leave_encashment_reason" placeholder="Reason / HRM leave-balance reference" class="mt-2 min-h-16 w-full rounded-md border bg-background p-2 text-sm" /></div>
          <div><Label>Other benefits</Label><Input v-model.number="f.other_benefits" type="number" min="0" step="0.01" /><textarea v-model="f.other_benefits_reason" placeholder="Reason or supporting reference" class="mt-2 min-h-16 w-full rounded-md border bg-background p-2 text-sm" /></div>
          <div><Label>Deductions</Label><Input v-model.number="f.deductions" type="number" min="0" step="0.01" /><textarea v-model="f.deductions_reason" placeholder="Reason or supporting reference" class="mt-2 min-h-16 w-full rounded-md border bg-background p-2 text-sm" /></div>
        </div>
        <Button :disabled="f.processing">Calculate and submit</Button>
      </form>
    </div>
  </AdminLayout>
</template>
