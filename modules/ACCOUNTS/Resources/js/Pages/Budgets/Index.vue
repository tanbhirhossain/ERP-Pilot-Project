<script setup lang="ts">
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { PiggyBank, Plus, X, BadgeCheck, Lock, Trash2, Check } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import SearchableSelect from '@core/Components/SearchableSelect.vue';
import { formatCurrency } from '@core/Utils/format';
import { useLocale } from '@core/Composables/useLocale';
import type { SelectOption } from '@core/types';
import type { BudgetSummary } from '@accounts/types';

const props = defineProps<{
    budgets: BudgetSummary[];
    summary: { count: number; budgeted: number };
    fiscalYearOptions: SelectOption[];
    accountOptions: SelectOption[];
    selectedId?: number | null;
    selectedLines: Array<{ id: number; account_id: number; account: string; period: string; amount: number; notes?: string | null }>;
    report?: { totals: { budget: number; actual: number; variance: number; pct: number; overrun_count: number } } | null;
    period?: string | null;
    canManage: boolean;
}>();

const { t: tr } = useLocale();

const showCreate = ref(false);
const create = useForm({ name: '', fiscal_year_id: '' as number | string, department: '', notes: '' });
function submitCreate() { create.post(route('accounts.budgets.store'), { onSuccess: () => { create.reset(); showCreate.value = false; } }); }

const line = useForm({ account_id: '' as number | string, period: props.period || new Date().toISOString().slice(0, 7), amount: '', notes: '' });
function addLine() {
    if (!props.selectedId) return;
    line.post(route('accounts.budgets.lines.store', props.selectedId), {
        preserveScroll: true,
        // Keep the account and month so the next month can be typed straight in; clear the amount so
        // a double-submit can't silently re-write the line that was just entered.
        onSuccess: () => line.reset('amount', 'notes'),
    });
}
function removeLine(id: number) { router.delete(route('accounts.budgets.lines.destroy', id), { preserveScroll: true }); }
function select(b: number) { router.get(route('accounts.budgets.index'), { budget: b, ...(props.period ? { period: props.period } : {}) }, { preserveState: true, preserveScroll: true }); }
function act(b: number, action: 'approve' | 'lock') { router.post(route(`accounts.budgets.${action}`, b), {}, { preserveScroll: true }); }

function statusTone(s: string) {
    return s === 'approved' ? 'bg-emerald-500/10 text-emerald-700 border-emerald-500/20'
        : s === 'locked' ? 'bg-sky-500/10 text-sky-700 border-sky-500/20'
        : 'bg-amber-500/10 text-amber-700 border-amber-500/20';
}
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Accounts' }, { title: 'Budgets' }]">
    <Head :title="tr('Budgets')" />
    <PageHeader
      :title="tr('Budgets')"
      description="Plan income and spend per account for the fiscal year, approve it, then watch the posted ledger against it in the variance report."
      :icon="PiggyBank"
      :stats="[
        { label: tr('budget.budgeted'), value: formatCurrency(summary.budgeted) },
        { label: 'Budgets', value: String(summary.count), sub: 'approved' },
      ]"
    >
      <template #actions>
        <div class="flex items-center gap-2">
          <Link :href="route('accounts.budgets.variance')"><Button variant="outline" size="sm" class="rounded-full">{{ tr('budget.variance') }}</Button></Link>
          <Button v-if="canManage" size="sm" class="rounded-full" @click="showCreate = !showCreate"><Plus class="size-4" /> {{ tr('budget.new') }}</Button>
        </div>
      </template>
    </PageHeader>

    <!-- Create -->
    <div v-if="showCreate && canManage" class="rounded-xl border bg-card p-5 shadow-sm">
      <div class="flex items-center justify-between">
        <h2 class="flex items-center gap-2 font-semibold"><Plus class="size-4 text-primary" /> {{ tr('budget.new') }}</h2>
        <Button variant="ghost" size="icon" class="size-7" @click="showCreate = false"><X class="size-4" /></Button>
      </div>
      <form class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4" @submit.prevent="submitCreate">
        <div class="lg:col-span-2"><Label>Name</Label><Input v-model="create.name" placeholder="e.g. FY2627 Operating Budget" /></div>
        <div><Label>Fiscal year</Label><SearchableSelect v-model="create.fiscal_year_id" :options="fiscalYearOptions" placeholder="Select year" /></div>
        <div><Label>Department (optional)</Label><Input v-model="create.department" placeholder="e.g. Operations" /></div>
        <div class="lg:col-span-3"><Label>Notes</Label><Input v-model="create.notes" /></div>
        <div class="flex items-end"><Button :disabled="create.processing || !create.name || !create.fiscal_year_id"><Plus class="size-4" /> Create</Button></div>
      </form>
      <p v-if="create.errors.name" class="mt-2 text-sm text-destructive">{{ create.errors.name }}</p>
    </div>

    <!-- List -->
    <div class="rounded-xl border bg-card shadow-sm">
      <div class="border-b px-5 py-3"><h3 class="font-semibold">All budgets</h3></div>
      <div class="overflow-auto">
        <table class="w-full text-sm">
          <thead class="bg-muted/50 text-left text-xs text-muted-foreground"><tr><th class="px-4 py-2">Code</th><th class="px-4 py-2">Name</th><th class="px-4 py-2">Fiscal year</th><th class="px-4 py-2">Department</th><th class="px-4 py-2 text-right">Lines</th><th class="px-4 py-2 text-right">{{ tr('budget.budgeted') }}</th><th class="px-4 py-2">Status</th><th v-if="canManage" class="px-4 py-2"></th></tr></thead>
          <tbody class="divide-y">
            <tr v-for="b in budgets" :key="b.id" class="hover:bg-accent/40 cursor-pointer" :class="selectedId === b.id ? 'bg-accent/50' : ''" @click="select(b.id)">
              <td class="px-4 py-2.5 font-mono text-xs">{{ b.code }}</td>
              <td class="px-4 py-2.5 font-medium">{{ b.name }}</td>
              <td class="px-4 py-2.5 text-xs">{{ b.fiscal_year || '—' }}</td>
              <td class="px-4 py-2.5 text-xs">{{ b.department || '—' }}</td>
              <td class="px-4 py-2.5 text-right font-mono">{{ b.line_count }}</td>
              <td class="px-4 py-2.5 text-right font-mono">{{ formatCurrency(b.budgeted) }}</td>
              <td class="px-4 py-2.5"><span class="inline-flex rounded-full border px-2 py-0.5 text-xs font-medium capitalize" :class="statusTone(b.status)">{{ b.status }}</span></td>
              <td v-if="canManage" class="px-4 py-2.5">
                <div class="flex justify-end gap-1.5" @click.stop>
                  <Button v-if="b.status === 'draft'" variant="outline" size="sm" class="rounded-full" @click="act(b.id, 'approve')"><BadgeCheck class="size-3.5" /> {{ tr('budget.approve') }}</Button>
                  <Button v-else-if="b.status === 'approved'" variant="ghost" size="sm" class="rounded-full text-muted-foreground" @click="act(b.id, 'lock')"><Lock class="size-3.5" /> {{ tr('budget.lock') }}</Button>
                </div>
              </td>
            </tr>
            <tr v-if="!budgets.length"><td :colspan="canManage ? 8 : 7" class="px-4 py-8 text-center text-muted-foreground">No budgets yet. Create one above.</td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Line editor for the selected budget -->
    <div v-if="selectedId" class="rounded-xl border bg-card shadow-sm">
      <div class="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-3">
        <h3 class="font-semibold">{{ tr('budget.lines') }} <span class="text-xs font-normal text-muted-foreground">· {{ selectedLines.length }} line(s) · {{ formatCurrency(report?.totals.budget ?? 0) }} planned · {{ formatCurrency(report?.totals.actual ?? 0) }} actual</span></h3>
      </div>

      <form v-if="canManage" class="grid gap-3 border-b p-5 sm:grid-cols-2 lg:grid-cols-[1fr_140px_140px_auto]" @submit.prevent="addLine">
        <div><Label>Account</Label><SearchableSelect v-model="line.account_id" :options="accountOptions" placeholder="Select account" /></div>
        <div><Label>Month</Label><Input v-model="line.period" type="month" /></div>
        <div><Label>Amount (BDT)</Label><Input v-model="line.amount" type="number" min="0" step="0.01" /></div>
        <div class="flex items-end"><Button :disabled="line.processing || !line.account_id || line.amount === ''"><Plus class="size-4" /> {{ tr('budget.addLine') }}</Button></div>
      </form>
      <p class="px-5 pt-2 text-xs text-muted-foreground">Amount is in the account’s natural direction — planned spend for expenses/assets, planned income for revenue. Re-adding the same account + month updates that line.</p>

      <div class="overflow-auto">
        <table class="w-full text-sm">
          <thead class="bg-muted/50 text-left text-xs text-muted-foreground"><tr><th class="px-4 py-2">Account</th><th class="px-4 py-2">Period</th><th class="px-4 py-2 text-right">{{ tr('budget.budgeted') }}</th><th v-if="canManage" class="px-4 py-2"></th></tr></thead>
          <tbody class="divide-y">
            <tr v-for="l in selectedLines" :key="l.id" class="hover:bg-accent/40">
              <td class="px-4 py-2.5">{{ l.account }}</td>
              <td class="px-4 py-2.5 font-mono text-xs">{{ l.period }}</td>
              <td class="px-4 py-2.5 text-right font-mono font-semibold">{{ formatCurrency(l.amount) }}</td>
              <td v-if="canManage" class="px-4 py-2.5 text-right"><Button variant="ghost" size="sm" class="rounded-full text-muted-foreground hover:text-destructive" @click="removeLine(l.id)"><Trash2 class="size-3.5" /></Button></td>
            </tr>
            <tr v-if="!selectedLines.length"><td :colspan="canManage ? 4 : 3" class="px-4 py-6 text-center text-muted-foreground">No lines yet — add account × month amounts above.</td></tr>
          </tbody>
        </table>
      </div>
      <div v-if="report && report.totals.overrun_count > 0" class="flex items-center gap-2 border-t px-5 py-2.5 text-xs text-amber-700"><Check class="size-3.5" /> {{ report.totals.overrun_count }} account(s) currently off-plan.</div>
    </div>
  </AdminLayout>
</template>
