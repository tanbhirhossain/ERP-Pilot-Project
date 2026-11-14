<script setup lang="ts">
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Receipt, Printer, Download, ArrowUpRight, ArrowDownRight, Scale } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import { printDocument } from '@core/Composables/usePrint';
import { useLocale } from '@core/Composables/useLocale';
import { formatCurrency } from '@core/Utils/format';
import type { VatReturn } from '@accounts/types';

const { t } = useLocale();

const props = defineProps<{ month: string; return: VatReturn }>();
const month = ref(props.month);
function apply() { router.get(route('accounts.reports.vat-return'), { month: month.value }, { preserveState: true }); }

const ret = props.return;
const isPayable = ret.net_payable > 0;
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Accounts' }, { title: 'VAT Return · Mushak 9.1' }]">
    <Head title="VAT Return · Mushak 9.1" />
    <PageHeader
      :title="t('tax.vatReturn')"
      :description="`${ret.company.name} · BIN ${ret.company.bin} · Period ${ret.period.label}. Output VAT netted against input credit and VDS; figures in BDT.`"
      :icon="Receipt"
      :stats="[
        { label: 'Output VAT (net)', value: formatCurrency(ret.net_output_vat), sub: 'After credit notes' },
        { label: 'Input credit (net)', value: formatCurrency(ret.net_input_vat), sub: 'After debit notes' },
        { label: 'VDS credit', value: formatCurrency(ret.vds_credit), sub: 'Certified 6.6' },
        { label: isPayable ? 'Net payable' : 'Credit c/f', value: formatCurrency(isPayable ? ret.net_payable : ret.credit_carried_forward), sub: isPayable ? 'Due to NBR' : 'Carry forward' },
      ]"
    >
      <template #actions>
        <div class="flex items-end gap-2">
          <Input v-model="month" type="month" class="h-9 w-44" @change="apply" />
          <Link :href="route('accounts.reports.vat-return.pdf', { month })"><Button variant="outline" size="sm" class="rounded-full"><Download class="size-4" /> PDF</Button></Link>
          <Button variant="outline" size="sm" class="rounded-full" @click="printDocument()"><Printer class="size-4" /> {{ t('common.print') }}</Button>
        </div>
      </template>
    </PageHeader>

    <div data-core-print class="space-y-6">
      <!-- Net position banner -->
      <div class="rounded-xl border p-5 shadow-sm" :class="isPayable ? 'bg-rose-500/[0.05] border-rose-500/20' : 'bg-emerald-500/[0.05] border-emerald-500/20'">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div class="flex items-center gap-3">
            <span class="grid size-10 place-items-center rounded-xl" :class="isPayable ? 'bg-rose-500/15 text-rose-600' : 'bg-emerald-500/15 text-emerald-600'">
              <component :is="isPayable ? ArrowUpRight : ArrowDownRight" class="size-5" />
            </span>
            <div>
              <p class="text-sm font-semibold">{{ isPayable ? 'Net VAT payable to NBR' : 'Credit carried forward' }}</p>
              <p class="text-xs text-muted-foreground">{{ ret.period.label }} · file within 15 days of period end</p>
            </div>
          </div>
          <p class="font-mono text-3xl font-bold" :class="isPayable ? 'text-rose-600' : 'text-emerald-600'">{{ formatCurrency(isPayable ? ret.net_payable : ret.credit_carried_forward) }}</p>
        </div>
      </div>

      <!-- Working paper -->
      <div class="rounded-xl border bg-card shadow-sm">
        <div class="border-b px-5 py-3"><h2 class="flex items-center gap-2 font-semibold"><Scale class="size-4 text-primary" /> {{ t('tax.workingPaper') }}</h2></div>
        <table class="w-full text-sm">
          <tbody class="divide-y">
            <tr class="bg-muted/40"><td colspan="2" class="px-5 py-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">{{ t('tax.outputTaxOnSupplies') }}</td></tr>
            <tr><td class="px-5 py-2.5">Standard-rated supplies (15%)</td><td class="px-5 py-2.5 text-right font-mono">{{ formatCurrency(ret.output.standard.vat) }}</td></tr>
            <tr><td class="px-5 py-2.5">Reduced-rate supplies</td><td class="px-5 py-2.5 text-right font-mono">{{ formatCurrency(ret.output.reduced.vat) }}</td></tr>
            <tr><td class="px-5 py-2.5 text-muted-foreground">Zero-rated / exempt supplies (value {{ formatCurrency(ret.output.zero.base) }})</td><td class="px-5 py-2.5 text-right font-mono text-muted-foreground">—</td></tr>
            <tr class="font-semibold"><td class="px-5 py-2.5">Total output VAT</td><td class="px-5 py-2.5 text-right font-mono">{{ formatCurrency(ret.output.total_vat) }}</td></tr>
            <tr><td class="px-5 py-2.5 text-muted-foreground">Less: credit notes (sales returns)</td><td class="px-5 py-2.5 text-right font-mono text-rose-600">({{ formatCurrency(ret.output_adjustment) }})</td></tr>
            <tr class="font-semibold"><td class="px-5 py-2.5">Net output VAT</td><td class="px-5 py-2.5 text-right font-mono">{{ formatCurrency(ret.net_output_vat) }}</td></tr>

            <tr class="bg-muted/40"><td colspan="2" class="px-5 py-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">{{ t('tax.inputTaxCredit') }}</td></tr>
            <tr><td class="px-5 py-2.5">Input VAT on purchases</td><td class="px-5 py-2.5 text-right font-mono">{{ formatCurrency(ret.input.total_vat) }}</td></tr>
            <tr><td class="px-5 py-2.5 text-muted-foreground">Less: debit notes (purchase returns)</td><td class="px-5 py-2.5 text-right font-mono text-rose-600">({{ formatCurrency(ret.input_adjustment) }})</td></tr>
            <tr class="font-semibold"><td class="px-5 py-2.5">Net input credit</td><td class="px-5 py-2.5 text-right font-mono">{{ formatCurrency(ret.net_input_vat) }}</td></tr>
            <tr><td class="px-5 py-2.5">VDS deducted at source (certified)</td><td class="px-5 py-2.5 text-right font-mono">{{ formatCurrency(ret.vds_credit) }}</td></tr>

            <tr class="border-t-2 font-bold" :class="isPayable ? 'bg-rose-500/[0.04]' : 'bg-emerald-500/[0.04]'">
              <td class="px-5 py-3">{{ isPayable ? 'Net VAT payable' : 'Credit carried forward' }}</td>
              <td class="px-5 py-3 text-right font-mono text-base">{{ formatCurrency(isPayable ? ret.net_payable : ret.credit_carried_forward) }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Registers -->
      <div class="grid gap-6 lg:grid-cols-2">
        <div class="rounded-xl border bg-card shadow-sm">
          <div class="border-b px-5 py-3"><h3 class="font-semibold">{{ t('tax.salesRegister') }} <span class="text-xs font-normal text-muted-foreground">({{ ret.registers.sales.length }} supplies)</span></h3></div>
          <div class="max-h-96 overflow-auto">
            <table class="w-full text-xs">
              <thead class="sticky top-0 bg-muted/60"><tr class="text-left text-muted-foreground"><th class="px-3 py-2">Date</th><th class="px-3 py-2">Ref</th><th class="px-3 py-2">Party</th><th class="px-3 py-2 text-right">VAT</th></tr></thead>
              <tbody class="divide-y">
                <tr v-for="row in ret.registers.sales" :key="row.channel + row.ref">
                  <td class="px-3 py-1.5 whitespace-nowrap">{{ row.date }}</td>
                  <td class="px-3 py-1.5 font-mono">{{ row.ref }} <span class="text-muted-foreground">· {{ row.channel }}</span></td>
                  <td class="px-3 py-1.5 truncate max-w-32">{{ row.party }}</td>
                  <td class="px-3 py-1.5 text-right font-mono">{{ formatCurrency(row.vat) }}</td>
                </tr>
                <tr v-if="!ret.registers.sales.length"><td colspan="4" class="px-3 py-6 text-center text-muted-foreground">No supplies this period.</td></tr>
              </tbody>
            </table>
          </div>
        </div>
        <div class="rounded-xl border bg-card shadow-sm">
          <div class="border-b px-5 py-3"><h3 class="font-semibold">{{ t('tax.purchaseRegister') }} <span class="text-xs font-normal text-muted-foreground">({{ ret.registers.purchases.length }} bills)</span></h3></div>
          <div class="max-h-96 overflow-auto">
            <table class="w-full text-xs">
              <thead class="sticky top-0 bg-muted/60"><tr class="text-left text-muted-foreground"><th class="px-3 py-2">Date</th><th class="px-3 py-2">Ref</th><th class="px-3 py-2">Vendor</th><th class="px-3 py-2 text-right">VAT</th></tr></thead>
              <tbody class="divide-y">
                <tr v-for="row in ret.registers.purchases" :key="row.ref">
                  <td class="px-3 py-1.5 whitespace-nowrap">{{ row.date }}</td>
                  <td class="px-3 py-1.5 font-mono">{{ row.ref }}</td>
                  <td class="px-3 py-1.5 truncate max-w-32">{{ row.party }}</td>
                  <td class="px-3 py-1.5 text-right font-mono">{{ formatCurrency(row.vat) }}</td>
                </tr>
                <tr v-if="!ret.registers.purchases.length"><td colspan="4" class="px-3 py-6 text-center text-muted-foreground">No purchases this period.</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </AdminLayout>
</template>
