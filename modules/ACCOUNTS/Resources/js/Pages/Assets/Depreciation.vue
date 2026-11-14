<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { CalendarClock, Play, FileSpreadsheet } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import { formatCurrency } from '@core/Utils/format';
import { useLocale } from '@core/Composables/useLocale';
import type { DepreciationPreviewRow, DepreciationRun } from '@accounts/types';

const props = defineProps<{
    month: string;
    alreadyRun: boolean;
    preview: DepreciationPreviewRow[];
    previewTotal: number;
    runs: DepreciationRun[];
    summary: { count: number; cost: number; accumulated: number; nbv: number };
    canManage: boolean;
}>();

const { t: tr } = useLocale();

const run = useForm({ period: props.month });
function submitRun() { run.post(route('accounts.assets.depreciation.run')); }

const byBasis = computed(() => props.preview.map((r) => r.charge.method === 'declining_balance' ? 'DB' : 'SL'));
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Accounts' }, { title: 'Fixed Assets' }, { title: 'Depreciation' }]">
    <Head :title="tr('assets.runTitle')" />
    <PageHeader
      :title="tr('assets.runTitle')"
      description="Preview the month's charge across the register, then post one balanced journal: debit depreciation expense, credit accumulated depreciation. Re-running a closed period is refused."
      :icon="CalendarClock"
      :stats="[
        { label: tr('assets.cost'), value: formatCurrency(summary.cost) },
        { label: tr('assets.accumulated'), value: formatCurrency(summary.accumulated) },
        { label: tr('assets.nbv'), value: formatCurrency(summary.nbv) },
        { label: 'Due this month', value: formatCurrency(previewTotal), sub: preview.length + ' asset(s)' },
      ]"
    >
      <template #actions>
        <form class="flex items-end gap-2" @submit.prevent="submitRun">
          <div><Input v-model="run.period" type="month" class="h-9 w-44" /></div>
          <Button type="submit" class="rounded-full" :disabled="run.processing || alreadyRun || !canManage"><Play class="size-4" /> {{ alreadyRun ? 'Already run' : tr('assets.run') }}</Button>
        </form>
      </template>
    </PageHeader>

    <div v-if="alreadyRun" class="rounded-xl border border-sky-500/20 bg-sky-500/[0.05] px-4 py-3 text-sm text-sky-700">
      A run already exists for {{ month }}. Pick another period, or void its journal to reverse it.
    </div>
    <div v-else-if="!preview.length" class="rounded-xl border bg-card px-4 py-6 text-center text-sm text-muted-foreground">
      Nothing is due for {{ month }} — no in-use asset has a charge left to book for this period.
    </div>

    <!-- Preview -->
    <div v-if="preview.length" class="rounded-xl border bg-card shadow-sm">
      <div class="flex items-center justify-between border-b px-5 py-3">
        <h3 class="font-semibold">What will post for {{ month }}</h3>
        <span class="text-xs text-muted-foreground">{{ preview.length }} asset(s) · {{ previewTotal ? byBasis.join(' ').includes('DB') ? 'incl. declining balance' : 'straight line' : '' }}</span>
      </div>
      <div class="overflow-auto">
        <table class="w-full text-sm">
          <thead class="bg-muted/50 text-left text-xs text-muted-foreground"><tr><th class="px-4 py-2">Asset</th><th class="px-4 py-2">Basis</th><th class="px-4 py-2 text-right">Opening NBV</th><th class="px-4 py-2 text-right">Charge</th><th class="px-4 py-2 text-right">Closing NBV</th></tr></thead>
          <tbody class="divide-y">
            <tr v-for="row in preview" :key="row.asset.id" class="hover:bg-accent/40">
              <td class="px-4 py-2.5"><span class="font-medium">{{ row.asset.name }}</span><span class="block font-mono text-xs text-muted-foreground">{{ row.asset.asset_code }}</span></td>
              <td class="px-4 py-2.5 text-xs text-muted-foreground">{{ row.charge.basis }}</td>
              <td class="px-4 py-2.5 text-right font-mono text-muted-foreground">{{ formatCurrency(row.charge.opening_nbv) }}</td>
              <td class="px-4 py-2.5 text-right font-mono font-semibold">{{ formatCurrency(row.charge.charge) }}</td>
              <td class="px-4 py-2.5 text-right font-mono">{{ formatCurrency(row.charge.closing_nbv) }}<span v-if="row.charge.fully" class="ml-1 text-[10px] font-sans text-sky-600">FULL</span></td>
            </tr>
            <tr class="border-t-2 font-bold"><td colspan="3" class="px-4 py-3">Total depreciation to post</td><td class="px-4 py-3 text-right font-mono">{{ formatCurrency(previewTotal) }}</td><td></td></tr>
          </tbody>
        </table>
      </div>
      <p v-if="run.errors.period" class="px-5 py-2 text-sm text-destructive">{{ run.errors.period }}</p>
    </div>

    <!-- History -->
    <div class="rounded-xl border bg-card shadow-sm">
      <div class="border-b px-5 py-3"><h3 class="font-semibold">Depreciation runs</h3></div>
      <div class="overflow-auto">
        <table class="w-full text-sm">
          <thead class="bg-muted/50 text-left text-xs text-muted-foreground"><tr><th class="px-4 py-2">Period</th><th class="px-4 py-2">Run</th><th class="px-4 py-2 text-right">Assets</th><th class="px-4 py-2 text-right">Amount</th><th class="px-4 py-2">Journal</th></tr></thead>
          <tbody class="divide-y">
            <tr v-for="r in runs" :key="r.id" class="hover:bg-accent/40">
              <td class="px-4 py-2.5 font-medium">{{ r.period }}</td>
              <td class="px-4 py-2.5 font-mono text-xs">{{ r.run_no }}</td>
              <td class="px-4 py-2.5 text-right font-mono">{{ r.line_count }}</td>
              <td class="px-4 py-2.5 text-right font-mono font-semibold">{{ formatCurrency(r.total_amount) }}</td>
              <td class="px-4 py-2.5 text-xs">
                <Link v-if="r.journal" :href="route('accounts.journals.show', r.journal.id)" class="inline-flex items-center gap-1 font-mono text-primary hover:underline"><FileSpreadsheet class="size-3.5" /> {{ r.journal.journal_no }}</Link>
                <span v-else class="text-muted-foreground">—</span>
              </td>
            </tr>
            <tr v-if="!runs.length"><td colspan="5" class="px-4 py-8 text-center text-muted-foreground">No depreciation posted yet.</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </AdminLayout>
</template>
