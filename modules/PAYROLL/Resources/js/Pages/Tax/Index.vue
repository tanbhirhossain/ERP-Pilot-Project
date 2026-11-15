<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import {
  AlertTriangle,
  BadgeCheck,
  Calculator,
  CircleCheck,
  FileWarning,
  Layers,
  Plus,
  Receipt,
  ScrollText,
  Trash2,
  Users,
} from 'lucide-vue-next';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import DataTable, { type DataTableColumn } from '@core/Components/DataTable.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import EmployeeSearchSelect from '../../Components/EmployeeSearchSelect.vue';
import type { Paginator, SelectOption } from '@core/types';
import { formatCurrency } from '@core/Utils/format';

/**
 * Employee tax.
 *
 * The page used to be a policy form with no answer on it. You could edit slabs and save an override,
 * but nothing told you what tax the payroll had actually withheld — so a policy with no slabs at all
 * sat there looking correct while 981 payslips computed ৳0. The screen now leads with that number.
 *
 * Three separate things live here and they are deliberately kept apart:
 *   1. the **policy** — effective-dated versions, each with a reason and an audit trail;
 *   2. the **impact** — what the last payroll actually withheld, and how many slips ran without a
 *      usable policy, because a form that cannot show its own effect cannot be checked;
 *   3. the **overrides** — the employees who do not follow the policy, each versioned.
 */

interface Slab {
  lower_limit: number | string;
  upper_limit: number | string | null;
  rate_percent: number | string;
}

interface Configuration {
  id: number;
  name: string;
  mode: 'fixed' | 'progressive';
  fixed_monthly_tax: number | string;
  annual_exemption: number | string;
  taxable_salary_percent: number | string;
  effective_from: string | null;
  effective_to: string | null;
  slabs: Slab[];
}

interface Readiness {
  configured: boolean;
  reason: string | null;
  starts_in_future: boolean;
  effective_from: string | null;
}

interface Impact {
  withheld: number;
  taxable_income: number;
  slips: number;
  unconfigured: number;
  configured: number;
  employees: number;
  periods: number;
  latest_period: string | null;
  effective_rate: number;
}

const props = defineProps<{
  configuration: Configuration;
  readiness: Readiness;
  impact: Impact;
  employeeOptions: SelectOption[];
  profiles: Paginator<Record<string, unknown>>;
  auditEvents: Paginator<Record<string, unknown>>;
}>();

const money = (value: number | string | null | undefined): string => formatCurrency(Number(value ?? 0));

/* -------------------------------- policy -------------------------------- */

const form = useForm({
  name: props.configuration.name,
  mode: props.configuration.mode,
  fixed_monthly_tax: Number(props.configuration.fixed_monthly_tax),
  annual_exemption: Number(props.configuration.annual_exemption),
  taxable_salary_percent: Number(props.configuration.taxable_salary_percent),
  effective_from: (props.configuration.effective_from ?? '').slice(0, 10),
  effective_to: (props.configuration.effective_to ?? '').slice(0, 10),
  reason: '',
  slabs: (props.configuration.slabs ?? []).map((slab) => ({
    lower_limit: Number(slab.lower_limit),
    upper_limit: slab.upper_limit === null || slab.upper_limit === '' ? '' : Number(slab.upper_limit),
    rate_percent: Number(slab.rate_percent),
  })) as { lower_limit: number; upper_limit: number | ''; rate_percent: number }[],
});

function addSlab(): void {
  const previous = form.slabs[form.slabs.length - 1];
  // Chained by default: a band that starts where the last one ended is the only shape the server
  // accepts, so the form offers it rather than making the operator re-type it and get it wrong.
  const from = previous ? (previous.upper_limit === '' ? 0 : Number(previous.upper_limit)) : 0;
  form.slabs.push({ lower_limit: from, upper_limit: '', rate_percent: 0 });
}

function removeSlab(index: number): void {
  form.slabs.splice(index, 1);
}

/** The last band has to be open-ended or the policy cannot cover the top of the income range. */
const lastSlabOpen = computed(() => form.slabs.length > 0 && form.slabs[form.slabs.length - 1].upper_limit === '');

/** A quick read of what the policy actually charges, at a few incomes. */
const preview = computed(() => {
  const exemption = Number(form.annual_exemption) || 0;
  const percent = Number(form.taxable_salary_percent) || 0;
  const rows = [];
  for (const monthly of [20000, 40000, 60000, 100000, 200000]) {
    const gross = monthly * 12 * (percent / 100);
    let tax = 0;
    for (const slab of form.slabs) {
      const lower = Math.max(Number(slab.lower_limit) || 0, exemption);
      const upper = slab.upper_limit === '' || slab.upper_limit === null ? gross : Number(slab.upper_limit);
      const inBand = Math.max(0, Math.min(gross, upper) - lower);
      tax += inBand * ((Number(slab.rate_percent) || 0) / 100);
    }
    rows.push({ monthly, annual: tax, monthly_tax: tax / 12 });
  }
  return rows;
});

/* -------------------------------- profile -------------------------------- */

const profile = useForm({
  employee_id: '',
  override_mode: 'global_config' as 'global_config' | 'force_fixed' | 'exempt',
  fixed_tax_amount: 0,
  fixed_tax_frequency: 'monthly' as 'monthly' | 'annual',
  exemption_reason: '',
  reason: '',
  effective_from: new Date().toISOString().slice(0, 10),
  effective_to: '',
});

function savePolicy(): void {
  form.put(route('payroll.tax.update'), { preserveScroll: true });
}

function saveProfile(): void {
  profile.post(route('payroll.tax.profiles.store'), { preserveScroll: true });
}

const knownProfileErrors = ['employee_id', 'override_mode', 'fixed_tax_amount', 'exemption_reason', 'reason', 'effective_from', 'effective_to'];

const looseProfileErrors = computed(() =>
  Object.entries(profile.errors)
    .filter(([key]) => !knownProfileErrors.includes(key))
    .map(([, message]) => String(message)),
);

const profileColumns: DataTableColumn[] = [
  { key: 'employee_id', label: 'Employee' },
  { key: 'override_mode', label: 'Override' },
  { key: 'fixed_tax_amount', label: 'Fixed tax', align: 'right' },
  { key: 'effective_from', label: 'From' },
  { key: 'effective_to', label: 'To' },
];

const plainDate = (value: unknown): string => (value ? String(value).slice(0, 10) : '—');

const modeLabel = (value: string): string => (value ?? '').replaceAll('_', ' ');
</script>

<template>
  <AdminLayout :breadcrumbs="[{ title: 'Payroll' }, { title: 'Employee tax' }]">
    <Head title="Employee tax" />

    <div class="space-y-4">
      <div>
        <h1 class="flex items-center gap-2.5 text-xl font-bold tracking-tight">
          <span class="grid size-9 place-items-center rounded-xl bg-gradient-to-br from-primary to-primary/70 text-primary-foreground shadow-sm">
            <Receipt class="size-5" />
          </span>
          Employee tax
        </h1>
        <p class="mt-1 text-sm text-muted-foreground">
          Annual slabs applied to annualised taxable salary, withheld monthly as one twelfth — with versioned policy, versioned employee overrides, and the withholding each payroll actually produced.
        </p>
      </div>

      <!-- The policy cannot price anybody -->
      <div v-if="!readiness.configured" class="flex items-start gap-3 rounded-xl border border-amber-500/40 bg-amber-500/[0.07] px-4 py-3">
        <AlertTriangle class="mt-0.5 size-4 shrink-0 text-amber-600 dark:text-amber-400" />
        <div class="min-w-0">
          <p class="text-[13px] font-semibold text-amber-900 dark:text-amber-200">
            {{ readiness.starts_in_future ? 'The active policy is not in force yet' : 'The active policy cannot calculate tax' }}
          </p>
          <p class="mt-0.5 text-[12px] leading-snug text-amber-900/80 dark:text-amber-200/80">{{ readiness.reason }}</p>
        </div>
      </div>

      <!-- Impact -->
      <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-xl border bg-card p-5 shadow-sm">
          <div class="flex items-start justify-between">
            <span class="grid size-11 place-items-center rounded-xl bg-sky-500/10 text-sky-600 dark:text-sky-400"><Receipt class="size-5" /></span>
          </div>
          <p class="mt-3 text-2xl font-bold tabular-nums tracking-tight">{{ money(impact.withheld) }}</p>
          <p class="text-[13px] font-medium text-muted-foreground">Tax withheld</p>
          <p class="mt-1 text-xs text-muted-foreground">{{ impact.slips.toLocaleString() }} payslip(s) · {{ impact.periods }} period(s)</p>
        </div>

        <div class="rounded-xl border bg-card p-5 shadow-sm">
          <div class="flex items-start justify-between">
            <span class="grid size-11 place-items-center rounded-xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-400"><Calculator class="size-5" /></span>
          </div>
          <p class="mt-3 text-2xl font-bold tabular-nums tracking-tight">{{ impact.effective_rate }}%</p>
          <p class="text-[13px] font-medium text-muted-foreground">Effective rate</p>
          <p class="mt-1 text-xs text-muted-foreground">of {{ money(impact.taxable_income) }} taxable income</p>
        </div>

        <div class="rounded-xl border bg-card p-5 shadow-sm">
          <div class="flex items-start justify-between">
            <span class="grid size-11 place-items-center rounded-xl bg-violet-500/10 text-violet-600 dark:text-violet-400"><Users class="size-5" /></span>
          </div>
          <p class="mt-3 text-2xl font-bold tabular-nums tracking-tight">{{ impact.employees.toLocaleString() }}</p>
          <p class="text-[13px] font-medium text-muted-foreground">Employees taxed</p>
          <p class="mt-1 text-xs text-muted-foreground">across {{ impact.periods }} payroll period(s)</p>
        </div>

        <div
          class="rounded-xl border bg-card p-5 shadow-sm"
          :class="impact.unconfigured > 0 ? 'border-amber-500/40' : ''"
        >
          <div class="flex items-start justify-between">
            <span
              class="grid size-11 place-items-center rounded-xl"
              :class="impact.unconfigured > 0 ? 'bg-amber-500/10 text-amber-600 dark:text-amber-400' : 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'"
            >
              <component :is="impact.unconfigured > 0 ? FileWarning : CircleCheck" class="size-5" />
            </span>
          </div>
          <p class="mt-3 text-2xl font-bold tabular-nums tracking-tight">{{ impact.unconfigured.toLocaleString() }}</p>
          <p class="text-[13px] font-medium text-muted-foreground">Computed without a policy</p>
          <p class="mt-1 text-xs" :class="impact.unconfigured > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-muted-foreground'">
            {{ impact.unconfigured > 0 ? 'these payslips withheld nothing' : 'every payslip had a usable policy' }}
          </p>
        </div>
      </div>

      <div class="grid items-start gap-4 xl:grid-cols-[1.15fr_0.85fr]">
        <!-- Policy -->
        <section class="rounded-xl border bg-card shadow-sm">
          <div class="flex flex-wrap items-start justify-between gap-3 border-b px-5 py-4">
            <div>
              <h2 class="flex items-center gap-2 text-sm font-semibold"><Layers class="size-4 text-muted-foreground" />Annual tax policy</h2>
              <p class="mt-0.5 text-xs text-muted-foreground">
                Saving creates a new effective-dated version; the previous one is closed the day before, and nothing already calculated changes.
              </p>
            </div>
            <span
              class="flex shrink-0 items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-medium"
              :class="configuration.slabs.length ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' : 'bg-amber-500/10 text-amber-600 dark:text-amber-400'"
            >
              <BadgeCheck class="size-3" />
              {{ readiness.effective_from ? 'In force from ' + plainDate(readiness.effective_from) : 'Version ' + configuration.id }}
            </span>
          </div>

          <form class="space-y-4 p-5" @submit.prevent="savePolicy">
            <div class="grid gap-4 sm:grid-cols-2">
              <div class="space-y-2">
                <Label>Policy name</Label>
                <Input v-model="form.name" />
                <p v-if="form.errors.name" class="text-xs text-destructive">{{ form.errors.name }}</p>
              </div>
              <div class="space-y-2">
                <Label>Mode</Label>
                <select v-model="form.mode" class="h-10 w-full rounded-md border bg-background px-3 text-sm">
                  <option value="progressive">Progressive annual slabs</option>
                  <option value="fixed">Fixed monthly amount</option>
                </select>
              </div>
            </div>

            <div v-if="form.mode === 'fixed'" class="space-y-2">
              <Label>Fixed monthly tax</Label>
              <Input v-model.number="form.fixed_monthly_tax" type="number" min="0" step="0.01" />
              <p class="text-xs text-muted-foreground">Every employee is charged this exact amount per month, whatever they earn.</p>
            </div>

            <div v-else class="space-y-4">
              <div class="grid gap-4 sm:grid-cols-2">
                <div class="space-y-2">
                  <Label>Annual exemption (relief)</Label>
                  <Input v-model.number="form.annual_exemption" type="number" min="0" step="0.01" />
                  <p class="text-xs text-muted-foreground">
                    Nothing below this annual income is taxed, whichever slab it nominally falls in. If your first slab already runs 0 → this amount at 0%, that is fine — it is not applied twice.
                  </p>
                  <p v-if="form.errors.annual_exemption" class="text-xs text-destructive">{{ form.errors.annual_exemption }}</p>
                </div>
                <div class="space-y-2">
                  <Label>Taxable salary %</Label>
                  <Input v-model.number="form.taxable_salary_percent" type="number" min="0" max="100" step="0.01" />
                  <p class="text-xs text-muted-foreground">The share of gross salary that enters the tax base. 100% taxes the whole salary.</p>
                  <p v-if="form.errors.taxable_salary_percent" class="text-xs text-destructive">{{ form.errors.taxable_salary_percent }}</p>
                </div>
              </div>

              <div class="space-y-2">
                <div class="flex items-center justify-between">
                  <Label>Annual slabs</Label>
                  <Button type="button" size="sm" variant="outline" @click="addSlab"><Plus class="size-3.5" />Add slab</Button>
                </div>

                <div v-if="form.slabs.length === 0" class="rounded-lg border border-dashed px-3 py-6 text-center">
                  <p class="text-[13px] font-medium">No slabs</p>
                  <p class="mt-1 text-xs text-muted-foreground">A progressive policy without bands taxes nobody. Add the first band to continue.</p>
                </div>

                <div v-else class="space-y-2">
                  <div class="grid grid-cols-[1fr_1fr_0.8fr_2rem] gap-2 text-[10px] uppercase tracking-wide text-muted-foreground">
                    <span>From</span><span>To</span><span>Rate %</span><span />
                  </div>
                  <div v-for="(slab, index) in form.slabs" :key="index" class="grid grid-cols-[1fr_1fr_0.8fr_2rem] items-center gap-2">
                    <Input v-model.number="slab.lower_limit" type="number" min="0" step="0.01" />
                    <Input v-model="slab.upper_limit" type="number" min="0" step="0.01" placeholder="No limit" />
                    <Input v-model.number="slab.rate_percent" type="number" min="0" max="100" step="0.01" />
                    <Button type="button" size="sm" variant="ghost" class="text-muted-foreground hover:text-destructive" @click="removeSlab(index)">
                      <Trash2 class="size-3.5" />
                    </Button>
                  </div>
                  <p v-if="!lastSlabOpen" class="text-xs text-amber-600 dark:text-amber-400">
                    The final slab must have no upper limit, or income above it escapes tax entirely.
                  </p>
                  <p v-if="form.errors.slabs" class="text-xs text-destructive">{{ form.errors.slabs }}</p>
                  <p v-if="form.errors['slabs.0.lower_limit']" class="text-xs text-destructive">{{ form.errors['slabs.0.lower_limit'] }}</p>
                </div>
              </div>

              <!-- Preview -->
              <div class="rounded-lg border bg-muted/30 p-3">
                <p class="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">What this policy charges</p>
                <table class="mt-2 w-full text-[12px]">
                  <thead>
                    <tr class="text-left text-[10px] uppercase tracking-wide text-muted-foreground">
                      <th class="py-1 font-medium">Monthly salary</th>
                      <th class="py-1 text-right font-medium">Annual tax</th>
                      <th class="py-1 text-right font-medium">Withheld / month</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y">
                    <tr v-for="row in preview" :key="row.monthly">
                      <td class="py-1 tabular-nums">{{ money(row.monthly) }}</td>
                      <td class="py-1 text-right tabular-nums">{{ money(row.annual) }}</td>
                      <td class="py-1 text-right tabular-nums font-medium">{{ money(row.monthly_tax) }}</td>
                    </tr>
                  </tbody>
                </table>
                <p class="mt-1.5 text-[10px] text-muted-foreground">Indicative — gross salary only; benefits and overtime are added per employee at payroll time.</p>
              </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
              <div class="space-y-2">
                <Label>Effective from</Label>
                <Input v-model="form.effective_from" type="date" />
                <p v-if="form.errors.effective_from" class="text-xs text-destructive">{{ form.errors.effective_from }}</p>
              </div>
              <div class="space-y-2">
                <Label>Effective to</Label>
                <Input v-model="form.effective_to" type="date" />
                <p class="text-xs text-muted-foreground">Optional. A new version closes this one automatically.</p>
                <p v-if="form.errors.effective_to" class="text-xs text-destructive">{{ form.errors.effective_to }}</p>
              </div>
            </div>

            <div class="space-y-2">
              <Label>Reason for this change</Label>
              <textarea v-model="form.reason" required minlength="3" maxlength="1000" rows="2" class="w-full rounded-md border bg-background p-2.5 text-sm" placeholder="e.g. Finance Act 2026 slabs" />
              <p v-if="form.errors.reason" class="text-xs text-destructive">{{ form.errors.reason }}</p>
            </div>

            <div class="flex flex-wrap items-center gap-3">
              <Button :disabled="form.processing">{{ form.processing ? 'Saving…' : 'Save as new version' }}</Button>
              <p class="text-xs text-muted-foreground">Payslips already calculated keep the policy they were computed under.</p>
            </div>
          </form>
        </section>

        <div class="space-y-4">
          <!-- Overrides -->
          <section class="rounded-xl border bg-card shadow-sm">
            <div class="border-b px-5 py-4">
              <h2 class="flex items-center gap-2 text-sm font-semibold"><Users class="size-4 text-muted-foreground" />Employee override</h2>
              <p class="mt-0.5 text-xs text-muted-foreground">
                Everyone follows the policy above unless an override exists here — <strong>{{ profiles.meta.total }}</strong> on file.
              </p>
            </div>
            <form class="space-y-3 p-5" @submit.prevent="saveProfile">
              <div class="space-y-2">
                <Label>Employee</Label>
                <EmployeeSearchSelect v-model="profile.employee_id" :options="employeeOptions" />
                <p v-if="profile.errors.employee_id" class="text-xs text-destructive">{{ profile.errors.employee_id }}</p>
              </div>
              <div class="space-y-2">
                <Label>Override</Label>
                <select v-model="profile.override_mode" class="h-10 w-full rounded-md border bg-background px-3 text-sm">
                  <option value="global_config">Follow the policy</option>
                  <option value="force_fixed">Charge a fixed amount</option>
                  <option value="exempt">Exempt from tax</option>
                </select>
              </div>
              <template v-if="profile.override_mode === 'force_fixed'">
                <div class="space-y-2">
                  <Label>Fixed amount</Label>
                  <Input v-model.number="profile.fixed_tax_amount" type="number" min="0" step="0.01" />
                  <p v-if="profile.errors.fixed_tax_amount" class="text-xs text-destructive">{{ profile.errors.fixed_tax_amount }}</p>
                </div>
                <div class="space-y-2">
                  <Label>Frequency</Label>
                  <select v-model="profile.fixed_tax_frequency" class="h-10 w-full rounded-md border bg-background px-3 text-sm">
                    <option value="monthly">Monthly</option>
                    <option value="annual">Annual (split across 12)</option>
                  </select>
                </div>
              </template>
              <div v-if="profile.override_mode === 'exempt'" class="space-y-2">
                <Label>Legal / HR basis for the exemption</Label>
                <textarea v-model="profile.exemption_reason" required minlength="3" rows="2" class="w-full rounded-md border bg-background p-2.5 text-sm" />
                <p v-if="profile.errors.exemption_reason" class="text-xs text-destructive">{{ profile.errors.exemption_reason }}</p>
              </div>
              <div class="grid gap-3 sm:grid-cols-2">
                <div class="space-y-2">
                  <Label>Effective from</Label>
                  <Input v-model="profile.effective_from" type="date" />
                  <p v-if="profile.errors.effective_from" class="text-xs text-destructive">{{ profile.errors.effective_from }}</p>
                </div>
                <div class="space-y-2">
                  <Label>Effective to</Label>
                  <Input v-model="profile.effective_to" type="date" />
                  <p v-if="profile.errors.effective_to" class="text-xs text-destructive">{{ profile.errors.effective_to }}</p>
                </div>
              </div>
              <div class="space-y-2">
                <Label>Reason for this change</Label>
                <textarea v-model="profile.reason" required minlength="3" rows="2" class="w-full rounded-md border bg-background p-2.5 text-sm" />
                <p v-if="profile.errors.reason" class="text-xs text-destructive">{{ profile.errors.reason }}</p>
              </div>
              <ul v-if="looseProfileErrors.length" class="rounded-lg border border-amber-500/40 bg-amber-500/[0.07] px-3 py-2 text-xs text-amber-900 dark:text-amber-200">
                <li v-for="(message, index) in looseProfileErrors" :key="index">{{ message }}</li>
              </ul>
              <Button :disabled="profile.processing">{{ profile.processing ? 'Saving…' : 'Save override' }}</Button>
            </form>
          </section>
        </div>
      </div>

      <!-- Profiles + audit -->
      <div class="grid items-start gap-4 xl:grid-cols-2">
        <section class="rounded-xl border bg-card shadow-sm">
          <div class="border-b px-5 py-4">
            <h2 class="flex items-center gap-2 text-sm font-semibold"><Users class="size-4 text-muted-foreground" />Employees who do not follow the policy</h2>
            <p class="mt-0.5 text-xs text-muted-foreground">Each row is a versioned override, not an edit — the previous version keeps its dates.</p>
          </div>
          <div class="p-4">
            <DataTable
              route-name="payroll.tax.index"
              title="Tax profiles"
              :columns="profileColumns"
              :rows="profiles.data"
              :meta="profiles.meta"
              search-placeholder="Search employee…"
              empty-title="No overrides"
              empty-description="Every employee is taxed by the policy above."
            >
              <template #cell-employee_id="{ row }">
                <strong class="block leading-tight">{{ row.employee_name || row.employee_id }}</strong>
                <span class="block text-xs text-muted-foreground">{{ row.employee_id }}</span>
              </template>
              <template #cell-override_mode="{ row }">
                <span class="rounded bg-muted px-1.5 py-px text-[10px] font-semibold uppercase tracking-wide">{{ modeLabel(String(row.override_mode)) }}</span>
              </template>
              <template #cell-fixed_tax_amount="{ row }">
                <span class="tabular-nums">{{ Number(row.fixed_tax_amount) > 0 ? money(Number(row.fixed_tax_amount)) : '—' }}</span>
              </template>
              <template #cell-effective_from="{ row }"><span class="tabular-nums">{{ plainDate(row.effective_from) }}</span></template>
              <template #cell-effective_to="{ row }"><span class="tabular-nums">{{ plainDate(row.effective_to) }}</span></template>
            </DataTable>
          </div>
        </section>

        <section class="rounded-xl border bg-card shadow-sm">
          <div class="border-b px-5 py-4">
            <h2 class="flex items-center gap-2 text-sm font-semibold"><ScrollText class="size-4 text-muted-foreground" />Change history</h2>
            <p class="mt-0.5 text-xs text-muted-foreground">Every policy and override change, with the reason and a before/after snapshot.</p>
          </div>
          <div class="divide-y">
            <article v-for="event in auditEvents.data" :key="String(event.id)" class="px-5 py-3">
              <div class="flex flex-wrap items-baseline justify-between gap-2">
                <strong class="text-[13px]">{{ modeLabel(String(event.event_type)) }} · {{ event.employee_id || 'policy #' + ((event.after as Record<string, unknown>)?.id ?? '') }}</strong>
                <span class="text-[11px] text-muted-foreground">{{ event.actor }} · {{ plainDate(event.occurred_at) }}</span>
              </div>
              <p class="mt-0.5 text-[11px] text-muted-foreground">Reason: {{ event.reason || '—' }}</p>
              <details class="mt-1.5">
                <summary class="cursor-pointer text-[11px] font-medium text-primary">Before / after</summary>
                <div class="mt-1.5 grid gap-2 md:grid-cols-2">
                  <pre class="max-h-40 overflow-auto whitespace-pre-wrap rounded bg-muted p-2 text-[10px]">{{ JSON.stringify(event.before || {}, null, 2) }}</pre>
                  <pre class="max-h-40 overflow-auto whitespace-pre-wrap rounded bg-muted p-2 text-[10px]">{{ JSON.stringify(event.after || {}, null, 2) }}</pre>
                </div>
              </details>
            </article>
            <p v-if="!auditEvents.data.length" class="px-5 py-8 text-center text-xs text-muted-foreground">No tax changes recorded yet.</p>
          </div>
        </section>
      </div>
    </div>
  </AdminLayout>
</template>
