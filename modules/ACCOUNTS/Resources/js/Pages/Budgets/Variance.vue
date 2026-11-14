<script setup lang="ts">
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { Printer, Scale, TrendingDown, TrendingUp } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import { printDocument } from '@core/Composables/usePrint';
import { formatCurrency } from '@core/Utils/format';
import { useLocale } from '@core/Composables/useLocale';
import type { BudgetVarianceReport } from '@accounts/types';

const props = defineProps<{
    budgets: Array<{ id: number; name: string; code: string; status: string; fiscal_year?: string | null }>;
    selectedId?: number | null;
    period?: string | null;
    report: BudgetVarianceReport | null;
}>();

const { t: tr } = useLocale();

const budget = ref<string>(props.selectedId ? String(props.selectedId) : '');
const period = ref<string>(props.period ?? '');

function apply() {
    const q: Record<string, string> = {};
    if (budget.value) q.budget = budget.value;
    if (period.value) q.period = period.value;
    router.get(route('accounts.budgets.variance'), q, { preserveState: true });
}
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Accounts' }, { title: 'Budget Variance' }]">
    <Head :title="tr('budget.variance')" />
    <PageHeader
      :title="tr('budget.variance')"
      description="Plan versus the posted ledger. Actuals are read live from the journal, so this is always reconciled to the books the moment you open it."
      :icon="Scale"
      :stats="report ? [
        { label: tr('budget.budgeted'), value: formatCurrency(report.totals.budget) },
        { label: tr('budget.actual'), value: formatCurrency(report.totals.actual) },
        { label: 'Variance', value: formatCurrency(report.totals.variance), sub: report.totals.pct + '% utilised' },
        { label: 'Off-plan', value: String(report.totals.overrun_count), sub: 'accounts' },
      ] : []"
    >
      <template #actions>
        <div class="flex items-end gap-2">
          <Button v-if="report" variant="outline" size="sm" class="rounded-full" @click="printDocument()"><Printer class="size-4" /> {{ tr('common.print') }}</Button>
        </div>
      </template>
    </PageHeader>

    <!-- Controls -->
    <form class="grid gap-3 rounded-xl border bg-card p-4 shadow-sm sm:grid-cols-[1fr_180px_auto]" @submit.prevent="apply">
      <div>
        <label class="text-xs font-medium text-muted-foreground">Budget</label>
        <select v-model="budget" class="mt-1 h-10 w-full rounded-md border bg-background px-3 text-sm">
          <option value="">Select a budget…</option>
          <option v-for="b in budgets" :key="b.id" :value="String(b.id)">{{ b.name }} · {{ b.code }} ({{ b.fiscal_year || '—' }} · {{ b.status }})</option>
        </select>
      </div>
      <div><label class="text-xs font-medium text-muted-foreground">Month (blank = whole plan)</label><Input v-model="period" type="month" class="mt-1" /></div>
      <div class="flex items-end"><Button type="submit" :disabled="!budget"><Scale class="size-4" /> Show</Button></div>
    </form>

    <p v-if="!report" class="rounded-xl border bg-card px-4 py-8 text-center text-sm text-muted-foreground">Pick a budget to see its variance.</p>

    <div v-else data-core-print class="space-y-6">
      <!-- Unfavourable callout -->
      <div v-if="report.unfavorable.length" class="rounded-xl border border-amber-500/25 bg-amber-500/[0.05] p-4">
        <p class="flex items-center gap-2 text-sm font-semibold text-amber-800"><TrendingDown class="size-4" /> {{ report.unfavorable.length }} account(s) off-plan for {{ report.budget.period || 'the whole plan' }}</p>
        <ul class="mt-2 grid gap-1 text-xs sm:grid-cols-2">
          <li v-for="u in report.unfavorable" :key="u.account_id" class="flex justify-between gap-3">
            <span>{{ u.code }} · {{ u.name }}</span>
            <span class="font-mono">{{ u.nature === 'credit' ? 'under-earned' : 'over-spent' }} by {{ formatCurrency(Math.abs(u.variance)) }}</span>
          </li>
        </ul>
      </div>

      <!-- Report table -->
      <div class="rounded-xl border bg-card shadow-sm">
        <div class="flex flex-wrap items-baseline justify-between gap-2 border-b px-5 py-3">
          <h3 class="font-semibold">{{ report.budget.name }} <span class="text-xs font-normal text-muted-foreground">· {{ report.budget.code }}<template v-if="report.budget.period"> · {{ report.budget.period }}</template><template v-else> · {{ report.periods.length }} month(s)</template></span></h3>
          <span class="text-xs text-muted-foreground">{{ report.budget.fiscal_year }}</span>
        </div>
        <div class="overflow-auto">
          <table class="w-full text-sm">
            <thead class="bg-muted/50 text-left text-xs text-muted-foreground"><tr><th class="px-4 py-2">Account</th><th class="px-4 py-2 text-right">{{ tr('budget.budgeted') }}</th><th class="px-4 py-2 text-right">{{ tr('budget.actual') }}</th><th class="px-4 py-2 text-right">Variance</th><th class="px-4 py-2 text-right">Used</th><th class="px-4 py-2">State</th></tr></thead>
            <tbody class="divide-y">
              <tr v-for="row in report.rows" :key="row.account_id" class="hover:bg-accent/40">
                <td class="px-4 py-2.5"><span class="font-medium">{{ row.name }}</span><span class="block font-mono text-xs text-muted-foreground">{{ row.code }} · {{ row.nature }}</span></td>
                <td class="px-4 py-2.5 text-right font-mono">{{ formatCurrency(row.budget) }}</td>
                <td class="px-4 py-2.5 text-right font-mono">{{ formatCurrency(row.actual) }}</td>
                <td class="px-4 py-2.5 text-right font-mono" :class="row.favourable ? 'text-emerald-600' : 'text-rose-600'">{{ formatCurrency(row.variance) }}</td>
                <td class="px-4 py-2.5 text-right font-mono text-muted-foreground">{{ row.pct }}%</td>
                <td class="px-4 py-2.5">
                  <span class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs font-medium" :class="row.favourable ? 'bg-emerald-500/10 text-emerald-700 border-emerald-500/20' : 'bg-rose-500/10 text-rose-700 border-rose-500/20'">
                    <component :is="row.favourable ? TrendingUp : TrendingDown" class="size-3" /> {{ row.favourable ? 'On track' : 'Off plan' }}
                  </span>
                </td>
              </tr>
              <tr v-if="!report.rows.length"><td colspan="6" class="px-4 py-8 text-center text-muted-foreground">This budget has no lines for the selected period.</td></tr>
              <tr v-else class="border-t-2 font-bold bg-muted/30">
                <td class="px-4 py-3">Total</td>
                <td class="px-4 py-3 text-right font-mono">{{ formatCurrency(report.totals.budget) }}</td>
                <td class="px-4 py-3 text-right font-mono">{{ formatCurrency(report.totals.actual) }}</td>
                <td class="px-4 py-3 text-right font-mono" :class="report.totals.variance >= 0 ? 'text-emerald-600' : 'text-rose-600'">{{ formatCurrency(report.totals.variance) }}</td>
                <td class="px-4 py-3 text-right font-mono text-muted-foreground">{{ report.totals.pct }}%</td>
                <td class="px-4 py-3 text-xs text-muted-foreground">{{ report.totals.overrun_count }} off-plan</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
      <p class="text-[11px] text-muted-foreground">Actuals = posted ledger movements within the period, in each account’s natural direction. Not a substitute for the statutory statements.</p>
    </div>
  </AdminLayout>
</template>
