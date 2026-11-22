<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import { FileDown, Banknote, CalendarRange, Info, Printer, Search, User } from 'lucide-vue-next';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import EnterpriseCard from '@core/Components/EnterpriseCard.vue';
import EmptyState from '@core/Components/EmptyState.vue';
import SearchableSelect from '@core/Components/SearchableSelect.vue';
import ModuleNav from './ModuleNav.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatCurrency, formatNumber } from '@core/Utils/format';
import { printDocument } from '@core/Composables/usePrint';
import { useReportPdf } from '@core/Composables/useReportPdf';

/**
 * Encashment preview — what an employee's unused leave is worth.
 *
 * This page existed before but answered with a bare table and no rate basis, so a number could not be
 * checked. It now shows the arithmetic per type: days, the salary component the policy names, the
 * basis, the per-day rate and the amount — and says when a cap is what limited the days.
 */

interface PreviewLine {
  leave_type_id: number;
  leave_type: string;
  code: string | null;
  days: number;
  component: string;
  basis: number;
  per_day: number;
  amount: number;
  max_days: number | null;
  capped: boolean;
}

interface Preview {
  employee: { employee_id: string; name: string; basic_salary: number; gross_salary: number };
  year: number;
  lines: PreviewLine[];
  total_days: number;
  total_amount: number;
}

const { downloadPdf } = useReportPdf();
const pdfPayload = computed(() => props.preview ? ({
    title: 'Leave encashment preview',
    subtitle: props.preview.employee.name,
    permission: 'hrm.leaves.balance.view',
    orientation: 'landscape',
    meta: {
        'Employee': props.preview.employee.name,
        'Employee ID': props.preview.employee.employee_id,
        'Basic salary': props.preview.employee.basic_salary,
        'Gross salary': props.preview.employee.gross_salary,
    },
    headings: ['Leave type', 'Code', 'Days', 'Component', 'Basis', 'Per day', 'Amount', 'Capped'],
    rows: props.preview.lines.map((l: any) => [l.leave_type, l.code ?? '—', l.days, l.component, l.basis, l.per_day, l.amount, l.capped ? 'Yes' : 'No']),
    footer: 'Leave encashment · HR',
}) : null);

const props = defineProps<{
  year: number;
  employeeId: string | null;
  preview: Preview | null;
  employeeOptions: Array<{ value: string; label: string }>;
}>();

const lookup = (employeeId: string | null): void => {
  router.get(
    route('hrm.leave-year-end.encash-preview'),
    { year: props.year, employee_id: employeeId || undefined },
    { preserveState: true, preserveScroll: true },
  );
};

const years = Array.from({ length: 7 }, (_, i) => new Date().getFullYear() - 3 + i);

const capped = computed(() => props.preview?.lines.filter((l) => l.capped) ?? []);
</script>

<template>
  <AdminLayout :breadcrumbs="[{ title: 'Leave' }, { title: 'Year-end', href: route('hrm.leave-year-end.index') }, { title: 'Encashment' }]">
    <Head title="Leave encashment preview" />

    <div class="space-y-4">
      <ModuleNav />

      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 class="flex items-center gap-2.5 text-xl font-bold tracking-tight">
            <span class="grid size-9 place-items-center rounded-xl bg-gradient-to-br from-primary to-primary/70 text-primary-foreground shadow-sm">
              <Banknote class="size-5" />
            </span>
            Encashment preview
          </h1>
          <p class="mt-1 max-w-3xl text-sm text-muted-foreground">
            Unused days on the encashable leave types, priced at the rate each type's policy names. This is a
            quotation for settlement — nothing is posted to payroll from here.
          </p>
        </div>
        <div class="flex items-center gap-2">
          <select class="h-9 rounded-lg border bg-card px-3 text-sm" :value="year" aria-label="Leave year" @change="lookup(employeeId ? (($event.target as HTMLSelectElement).dataset.keep, employeeId) : null)">
            <option v-for="y in years" :key="y" :value="y" @click="router.get(route('hrm.leave-year-end.encash-preview'), { year: y, employee_id: employeeId || undefined }, { preserveState: true, preserveScroll: true })">
              {{ y }}
            </option>
          </select>
          <Button v-if="preview" size="sm" variant="outline" @click="printDocument({ portrait: true })">
            <Printer class="size-4" /> Print
          </Button>
        </div>
      </div>

      <form class="grid items-end gap-3 rounded-xl border bg-card p-4 sm:grid-cols-[1fr_auto]" @submit.prevent="lookup(employeeId)">
        <div class="space-y-1.5">
          <Label>Employee</Label>
          <SearchableSelect
            :model-value="employeeId"
            :options="employeeOptions"
            placeholder="Search by name or employee ID"
            @update:model-value="(v: string) => lookup(v)"
          />
        </div>
        <Button type="submit" variant="outline"><Search class="size-4" /> Recalculate</Button>
      </form>

      <template v-if="preview">
        <div class="grid gap-4 lg:grid-cols-3">
          <EnterpriseCard class="lg:col-span-2" :title="`${preview.employee.name}`" :description="`${preview.employee.employee_id} · unused leave valued at settlement, ${year}`" :icon="User">
            <template v-if="preview.lines.length">
              <div class="overflow-x-auto">
                <table class="w-full min-w-[620px] text-sm">
                  <thead class="border-b text-[11px] uppercase tracking-wider text-muted-foreground">
                    <tr>
                      <th class="pb-2 text-left font-semibold">Leave type</th>
                      <th class="pb-2 text-right font-semibold">Days</th>
                      <th class="pb-2 text-left font-semibold pl-4">Rate basis</th>
                      <th class="pb-2 text-right font-semibold">Per day</th>
                      <th class="pb-2 text-right font-semibold">Amount</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y">
                    <tr v-for="line in preview.lines" :key="line.leave_type_id">
                      <td class="py-2.5">
                        <span class="font-medium">{{ line.leave_type }}</span>
                        <span class="ml-1.5 rounded bg-muted px-1.5 py-0.5 text-[10px] font-semibold text-muted-foreground">{{ line.code }}</span>
                        <span v-if="line.capped" class="ml-1.5 rounded bg-warning/10 px-1.5 py-0.5 text-[10px] font-semibold text-warning">
                          capped at {{ formatNumber(line.max_days ?? 0) }}d
                        </span>
                      </td>
                      <td class="py-2.5 text-right font-mono tabular-nums">{{ formatNumber(line.days) }}d</td>
                      <td class="py-2.5 pl-4 text-xs text-muted-foreground">
                        {{ line.component === 'gross' ? 'gross' : 'basic' }} {{ formatCurrency(line.basis) }} ÷ 30
                      </td>
                      <td class="py-2.5 text-right font-mono tabular-nums">{{ formatCurrency(line.per_day) }}</td>
                      <td class="py-2.5 text-right font-mono font-semibold tabular-nums">{{ formatCurrency(line.amount) }}</td>
                    </tr>
                  </tbody>
                  <tfoot class="border-t bg-muted/30 font-semibold">
                    <tr>
                      <td class="py-2.5">Total</td>
                      <td class="py-2.5 text-right font-mono tabular-nums">{{ formatNumber(preview.total_days) }}d</td>
                      <td colspan="2" />
                      <td class="py-2.5 text-right font-mono tabular-nums">{{ formatCurrency(preview.total_amount) }}</td>
                    </tr>
                  </tfoot>
                </table>
              </div>
              <p v-if="capped.length" class="mt-3 flex items-start gap-1.5 text-[11px] text-muted-foreground">
                <Info class="mt-0.5 size-3 shrink-0" />
                {{ capped.length }} type(s) have more unused days than the policy allows to be encashed, so the
                cap limited the payout. The days beyond the cap stay in the balance until year-end closing decides
                whether they carry or lapse.
              </p>
            </template>

            <EmptyState
              v-else
              :icon="CalendarRange"
              title="Nothing to encash"
              description="No active leave type is marked encashable, or this employee has no unused days on one."
            />
          </EnterpriseCard>

          <EnterpriseCard title="Salary basis" description="Current salary, from employee_salaries.">
            <dl class="space-y-2 text-sm">
              <div class="flex items-center justify-between">
                <dt class="text-muted-foreground">Basic salary</dt>
                <dd class="font-mono font-semibold tabular-nums">{{ formatCurrency(preview.employee.basic_salary) }}</dd>
              </div>
              <div class="flex items-center justify-between">
                <dt class="text-muted-foreground">Gross salary</dt>
                <dd class="font-mono font-semibold tabular-nums">{{ formatCurrency(preview.employee.gross_salary) }}</dd>
              </div>
              <div class="flex items-center justify-between border-t pt-2 font-semibold">
                <dt>Encashment value</dt>
                <dd class="font-mono tabular-nums">{{ formatCurrency(preview.total_amount) }}</dd>
              </div>
            </dl>
            <p class="mt-3 text-[11px] leading-relaxed text-muted-foreground">
              Each type names whether it pays on basic or gross, divided by 30. Changing that basis is done per
              leave type on the Types &amp; policy page.
            </p>
          </EnterpriseCard>
        </div>
      </template>

      <EmptyState
        v-else
        :icon="Banknote"
        title="Choose an employee"
        description="Pick somebody to see what their unused, encashable leave is worth at settlement."
      />
    </div>
  </AdminLayout>
</template>
