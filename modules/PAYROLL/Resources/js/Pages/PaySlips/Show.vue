<script setup lang="ts">
import { computed } from 'vue';
import { Head, router, Link } from '@inertiajs/vue3';
import type { PayrollSlip } from '../../types';
import { FileText, FileDown, Printer } from 'lucide-vue-next';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import { printDocument } from '@core/Composables/usePrint';
import { Button } from '@/components/ui/button';

const p = defineProps<{ slip: PayrollSlip; canPay: boolean }>();
function setStatus(status: string) {
  router.post(route('payroll.slips.payment-status', p.slip.id), { payment_status: status }, { preserveScroll: true });
}
function printSlip() { printDocument({ portrait: true }); }
const money = (value: unknown) => `${p.slip.breakdown_json.currency || 'BDT'} ${Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const hours = (value: unknown) => Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

/*
 * The earnings block has to add up to the gross it prints. In this database it does not: every
 * employee's salary row carries `gross = basic x 5/3` with house rent, medical and conveyance all
 * zero, so the four lines summed to 6,541.20 against a printed gross of 10,902.00. A payslip whose
 * own column does not add up is worse than one with a long line item, so the difference is named.
 */
const periodBase = (): Record<string, number> => {
  const s = p.slip.breakdown_json as Record<string, any>;

  return s.period_base_components ?? s.base_components ?? {};
};

const componentLines = (): Array<{ label: string; amount: number }> => {
  const b = periodBase();

  return [
    { label: 'Basic salary', amount: Number(b.basic ?? p.slip.basic_salary ?? 0) },
    { label: 'House rent', amount: Number(b.house_rent ?? 0) },
    { label: 'Medical allowance', amount: Number(b.medical ?? 0) },
    { label: 'Conveyance', amount: Number(b.conveyance ?? 0) },
    { label: 'Other allowance', amount: Number(b.other_allowance ?? 0) },
  ];
};

/** The part of the gross that no named component accounts for. */
const unallocated = computed(() => {
  const s = p.slip.breakdown_json as Record<string, any>;
  const items = (s.benefits ?? []).reduce((sum: number, i: any) => sum + Number(i.amount ?? 0), 0);
  const components = componentLines().reduce((sum, l) => sum + l.amount, 0);

  return Math.round((Number(p.slip.gross_salary ?? 0) - components - items) * 100) / 100;
});
</script>

<template>
  <AdminLayout :breadcrumbs="[{ title: 'Payroll' }, { title: 'Salary Sheets', href: route('payroll.sheets.index') }, { title: 'Pay Slip' }]">
    <Head title="Pay Slip" />
    <PageHeader
      title="Pay Slip"
      :icon="FileText"
      :description="`${slip.employee_id} · ${slip.department || '—'} · ${slip.designation || '—'}`"
    >
      <template #badge>
        <span class="rounded-full bg-muted px-2 py-1 text-xs capitalize">{{ slip.payment_status }}</span>
      </template>
      <template #actions>
        <Link :href="route('core.documents.pdf', { type: 'payslip', id: slip.id })"><Button size="sm" variant="default" class="rounded-full"><FileDown class="size-4" />Download PDF</Button></Link>
        <Button size="sm" variant="outline" @click="printSlip"><Printer class="size-4" />Print</Button>
      </template>
    </PageHeader>
    <div class="print print-portrait rounded-xl border bg-card p-6 print:border-0 print:shadow-none" data-core-print>
      <div class="print-furniture flex flex-wrap justify-between gap-4">
        <div>
          <p class="text-xs uppercase tracking-widest text-muted-foreground">Payroll statement</p>
          <h1 class="mt-1 text-2xl font-bold">{{ slip.employee_name }}</h1>
          <p class="text-sm text-muted-foreground">{{ slip.employee_id }} · {{ slip.department || '—' }} · {{ slip.designation || '—' }}</p>
          <p v-if="slip.breakdown_json.salary_proration?.factor < 1" class="mt-1 text-xs text-muted-foreground">Partial-month salary: {{ slip.breakdown_json.salary_proration.payable_days }} of {{ slip.breakdown_json.salary_proration.days_in_month }} calendar days.</p>
        </div>
        <div class="text-right">
          <p class="font-semibold">{{ slip.pay_year }}-{{ String(slip.pay_month).padStart(2, '0') }}</p>
          <p class="text-xs text-muted-foreground">{{ slip.breakdown_json.salary_sheet_mode?.with_punishment ? 'With punishment' : 'Without punishment' }}</p>
          <span class="rounded-full bg-muted px-2 py-1 text-xs capitalize">{{ slip.payment_status }}</span>
        </div>
      </div>

      <div class="my-6 grid grid-cols-1 gap-5 border-y py-5 md:grid-cols-2">
        <div>
          <h2 class="mb-3 font-semibold">Earnings</h2>
          <div v-for="line in componentLines()" :key="line.label" class="flex justify-between py-1 text-sm">
            <span>{{ line.label }}</span><span>{{ money(line.amount) }}</span>
          </div>
          <div v-for="(item, index) in slip.breakdown_json.benefits" :key="`${item.code}-${index}`" class="flex justify-between gap-3 py-1 text-sm">
            <span>
              {{ item.name }}
              <small v-if="item.code === 'approved-overtime'" class="block text-xs text-muted-foreground">{{ hours(slip.breakdown_json.cutlist.overtime_hours) }} approved hours</small>
              <small v-else-if="item.code === 'approved-extra-duty'" class="block text-xs text-muted-foreground">{{ hours(slip.breakdown_json.cutlist.extra_duty_hours) }} approved hours</small>
            </span>
            <span class="shrink-0">{{ money(item.amount) }}</span>
          </div>
          <!--
            Salary rows in this database store gross = basic x 5/3 with every allowance column at
            zero. Rather than print a column that does not add up, the difference is shown as its own
            line with the reason it exists.
          -->
          <div v-if="unallocated > 0.009" class="flex justify-between gap-3 py-1 text-sm">
            <span>
              Unallocated salary
              <small class="block text-xs text-muted-foreground">
                Gross exceeds the named components — the salary record carries gross without splitting it.
              </small>
            </span>
            <span class="shrink-0">{{ money(unallocated) }}</span>
          </div>
          <div class="mt-2 flex justify-between border-t pt-2 font-semibold"><span>Gross</span><span>{{ money(slip.gross_salary) }}</span></div>
        </div>
        <div>
          <h2 class="mb-3 font-semibold">Deductions</h2>
          <div v-for="(item, index) in slip.breakdown_json.recurring_deductions" :key="`${item.code}-${index}`" class="flex justify-between py-1 text-sm"><span>{{ item.name }}</span><span>{{ money(item.amount) }}</span></div>
          <div class="flex justify-between gap-3 py-1 text-sm"><span>Income tax<small class="block text-xs text-muted-foreground">{{ slip.breakdown_json.tax.mode }} · annual liability {{ money(slip.breakdown_json.tax.annual_tax) }} / 12</small></span><span>{{ money(slip.breakdown_json.tax.monthly_tax) }}</span></div>
          <div class="flex justify-between py-1 text-sm"><span>PF employee contribution</span><span>{{ money(slip.breakdown_json.provident_fund.employee_contribution) }}</span></div>
          <div class="flex justify-between py-1 text-sm"><span>Loan repayments</span><span>{{ money((slip.breakdown_json.loan_installments || []).reduce((sum: number, item: any) => sum + Number(item.amount), 0)) }}</span></div>
          <div class="flex justify-between py-1 text-sm"><span>Attendance deductions</span><span>{{ money(Number(slip.breakdown_json.cutlist.unpaid_leave || 0) + Number(slip.breakdown_json.cutlist.punishment || 0) + Number(slip.breakdown_json.cutlist.missing_punch || 0)) }}</span></div>
          <div class="mt-2 flex justify-between border-t pt-2 font-semibold"><span>Total deductions</span><span>{{ money(slip.total_deductions) }}</span></div>
        </div>
      </div>

      <section class="mb-5 rounded-lg border p-4">
        <h2 class="font-semibold">Attendance summary</h2>
        <p class="mb-3 text-xs text-muted-foreground">Counts and approved duty hours from the period cutlist.</p>
        <div class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-3 2xl:grid-cols-4">
          <div><span class="text-muted-foreground">Late days</span><strong class="block">{{ slip.breakdown_json.cutlist.late_count }}</strong></div>
          <div><span class="text-muted-foreground">Early-out days</span><strong class="block">{{ slip.breakdown_json.cutlist.early_out_count }}</strong></div>
          <div><span class="text-muted-foreground">Punishment-affected days</span><strong class="block">{{ slip.breakdown_json.cutlist.punishment_days }} <small>· {{ slip.breakdown_json.cutlist.punishable ? 'eligible' : 'not eligible' }}</small></strong></div>
          <div><span class="text-muted-foreground">Missing-punch count</span><strong class="block">{{ slip.breakdown_json.cutlist.missing_punch_count }}</strong></div>
          <div><span class="text-muted-foreground">Unpaid leave days</span><strong class="block">{{ slip.breakdown_json.cutlist.unpaid_leave_days }}</strong></div>
          <div><span class="text-muted-foreground">Approved overtime</span><strong class="block">{{ hours(slip.breakdown_json.cutlist.overtime_hours) }} hours</strong></div>
          <div><span class="text-muted-foreground">Approved extra duty</span><strong class="block">{{ hours(slip.breakdown_json.cutlist.extra_duty_hours) }} hours</strong></div>
        </div>
      </section>

      <div class="flex justify-between rounded-lg bg-muted/50 p-4 text-lg font-bold"><span>Net pay</span><span>{{ money(slip.net_salary) }}</span></div>
      <div v-if="canPay" class="no-print mt-5 flex flex-wrap gap-2">
        <Button @click="setStatus('paid')" :disabled="slip.payment_status === 'paid'">Mark paid</Button>
        <Button variant="outline" @click="setStatus('hold')" :disabled="slip.payment_status === 'paid'">Place on hold</Button>
        <Button variant="outline" @click="setStatus('unpaid')" :disabled="slip.payment_status === 'paid'">Mark unpaid</Button>
        <Button variant="outline" @click="printSlip">Print</Button>
      </div>
      <div v-else class="no-print mt-5 text-xs text-muted-foreground">You can view this slip but do not have permission to record payment.</div>
      <details class="no-print mt-6">
        <summary class="cursor-pointer text-sm font-medium">Immutable calculation snapshot</summary>
        <pre class="mt-2 max-h-96 overflow-auto rounded bg-muted p-3 text-xs">{{ JSON.stringify(slip.breakdown_json, null, 2) }}</pre>
      </details>
    </div>
  </AdminLayout>
</template>

<!--
  Printing is owned by resources/css/print.css and the `data-core-print`
  marker above. This file used to carry its own global `@media print` block
  that hid `body *`; in an SPA a component's unscoped <style> stays in the
  document after you navigate away, so visiting a payslip blanked the print
  output of every other page for the rest of the session.
-->
