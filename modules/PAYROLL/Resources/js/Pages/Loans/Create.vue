<script setup lang="ts">
import EmployeeSearchSelect from '../../Components/EmployeeSearchSelect.vue';
import { Head, useForm } from '@inertiajs/vue3';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

import { useUnsavedGuard } from '@core/Composables/useUnsavedGuard';
const props = defineProps<{ employeeOptions: { value: string; label: string; description?: string }[] }>();
const form = useForm({
  employee_id: '',
  principal_amount: 0,
  installment_count: 1,
  first_due_date: new Date().toISOString().slice(0, 10),
  purpose: '',
});
useUnsavedGuard(() => form.isDirty);
function submit() { form.post(route('payroll.loans.store')); }
</script>

<template>
  <AdminLayout :breadcrumbs="[{ title: 'Payroll' }, { title: 'Employee Loans', href: route('payroll.loans.index') }, { title: 'New request' }]">
    <Head title="New loan request" />
    <div class="max-w-4xl rounded-xl border bg-card p-6">
      <PageHeader title="Request interest-free loan" />
      <p class="mt-1 text-sm text-muted-foreground">Interest is fixed at 0%. The requested principal is split into equal monthly installments; any rounding remainder is included in the final installment.</p>
      <form @submit.prevent="submit" class="mt-5 space-y-4">
        <div class="space-y-2">
          <Label>Employee</Label>
          <EmployeeSearchSelect v-model="form.employee_id" :options="props.employeeOptions" />
          <p v-if="form.errors.employee_id" class="text-xs text-destructive">{{ form.errors.employee_id }}</p>
        </div>
        <div class="grid grid-cols-2 gap-4">
          <div class="space-y-2">
            <Label>Principal amount</Label>
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
          <p v-if="form.errors.first_due_date" class="text-xs text-destructive">{{ form.errors.first_due_date }}</p>
        </div>
        <div class="space-y-2">
          <Label>Purpose (required for audit)</Label>
          <textarea v-model="form.purpose" required minlength="5" maxlength="1000" class="min-h-24 w-full rounded-md border bg-background p-3" />
          <p v-if="form.errors.purpose" class="text-xs text-destructive">{{ form.errors.purpose }}</p>
        </div>
        <Button :disabled="form.processing">{{ form.processing ? 'Submitting…' : 'Submit for approval' }}</Button>
      </form>
    </div>
  </AdminLayout>
</template>
