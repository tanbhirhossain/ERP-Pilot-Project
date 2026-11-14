<script setup lang="ts">
/**
 * One drawer's Z-report: the sales it rang up, the dues it collected, the count, and the single
 * journal the whole day posted to.
 *
 * This is the drill-down behind the day-end report, and the page a supervisor stands at with the
 * drawer open. Everything on it is read-only — the posting already happened when the drawer closed,
 * and a Z-report you can edit is not a Z-report (report item 39).
 */
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { ArrowLeft, Banknote, CalendarClock, FileText, HandCoins, Landmark, Printer, ReceiptText, TriangleAlert } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import { printDocument } from '@core/Composables/usePrint';
import StatusBadge from '@core/Components/StatusBadge.vue';
import { formatCurrency, formatDate } from '@core/Utils/format';
import StaticTable from '@core/Components/StaticTable.vue';

interface JournalLine { code: string | null; account: string | null; debit: string; credit: string; description: string | null }

const props = defineProps<{
  session: Record<string, any>;
  sales: Record<string, any>[];
  collections: Record<string, any>[];
  journal: { journal_no: string; journal_date: string; status: string; narration: string; total_debit: string; total_credit: string; lines: JournalLine[]; id: number } | null;
}>();

const money = (value: unknown): number => Number(value ?? 0);

const payLabels: Record<string, string> = {
  cash: 'Cash', bank: 'Bank', bkash: 'bKash', card: 'Card', split: 'Split', due: 'Due — credit sale',
};

const counted = computed(() => props.session.closing_cash !== null && props.session.closing_cash !== undefined);
const variance = computed(() => money(props.session.cash_difference));
const outBy = computed(() => counted.value && Math.abs(variance.value) > 0.005);
const balanced = computed(() => props.journal !== null && Math.abs(money(props.journal.total_debit) - money(props.journal.total_credit)) < 0.005);

const liveSales = computed(() => props.sales.filter((s) => s.status !== 'void'));
const voidedSales = computed(() => props.sales.filter((s) => s.status === 'void'));
const creditSold = computed(() => liveSales.value.reduce((sum, s) => sum + money(s.due), 0));
const stillOwed = computed(() => liveSales.value.reduce((sum, s) => sum + Math.max(0, money(s.due) - money(s.collected)), 0));
// `window` is not in a template's scope for the type-checker; keep it in script.
function printPage(): void {
    printDocument();
}

</script>

<template>
  <AdminLayout :breadcrumbs="[{ title: 'Sales & POS' }, { title: 'POS Day-End & Collections', href: route('sales.pos.reports.dayEnd') }, { title: session.session_no }]">
    <Head :title="`Day-end ${session.session_no}`" />

    <div class="space-y-4">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 class="flex items-center gap-2 text-xl font-bold">
            <ReceiptText class="size-5 text-primary" />{{ session.session_no }}
            <StatusBadge :label="session.status" :tone="session.status === 'open' ? 'success' : 'neutral'" />
          </h1>
          <p class="mt-1 text-sm text-muted-foreground">
            {{ session.warehouse ?? '—' }} · opened by {{ session.cashier ?? '—' }} ·
            {{ formatDate(session.opened_at) }}
            <span v-if="session.closed_at"> → closed {{ formatDate(session.closed_at) }}</span>
          </p>
        </div>
        <div class="flex gap-2">
          <Link :href="route('sales.pos.reports.dayEnd')"><Button variant="outline" size="sm"><ArrowLeft class="size-4" />Report</Button></Link>
          <Button variant="outline" size="sm" @click="printPage"><Printer class="size-4" />Print</Button>
        </div>
      </div>

      <div v-if="session.status === 'open'" class="flex items-start gap-2 rounded-xl border border-amber-500/30 bg-amber-500/[0.07] p-3 text-sm text-amber-800 dark:text-amber-300">
        <CalendarClock class="mt-0.5 size-4 shrink-0" />
        <p>
          This drawer is still open, so nothing has posted yet. The figures below are derived from its sales and collections; when it is
          counted and closed they become the day's journal, exactly as shown.
        </p>
      </div>

      <!-- The day, and the drawer reconciliation -->
      <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <div class="rounded-xl border bg-card p-4 lg:col-span-2">
          <h2 class="mb-2 font-semibold">What the day did</h2>
          <div class="grid grid-cols-2 gap-x-6 gap-y-1 text-sm sm:grid-cols-3">
            <div class="flex justify-between"><span class="text-muted-foreground">Transactions</span><span class="font-mono">{{ session.transactions_count }}</span></div>
            <div class="flex justify-between"><span class="text-muted-foreground">Voids</span><span class="font-mono" :class="session.void_count ? 'text-amber-600' : ''">{{ session.void_count }}</span></div>
            <div class="flex justify-between"><span class="text-muted-foreground">Gross</span><span class="font-mono">{{ formatCurrency(money(session.net_sales_total) + money(session.discount_total)) }}</span></div>
            <div class="flex justify-between"><span class="text-muted-foreground">Discount</span><span class="font-mono">-{{ formatCurrency(money(session.discount_total)) }}</span></div>
            <div class="flex justify-between"><span class="text-muted-foreground">Net sales</span><span class="font-mono font-bold">{{ formatCurrency(money(session.net_sales_total)) }}</span></div>
            <div class="flex justify-between"><span class="text-muted-foreground">VAT</span><span class="font-mono">{{ formatCurrency(money(session.vat_total)) }}</span></div>
            <div class="flex justify-between"><span class="text-muted-foreground">Cost of goods</span><span class="font-mono">{{ formatCurrency(money(session.cogs_total)) }}</span></div>
            <div class="flex justify-between"><span class="text-muted-foreground">Cash taken</span><span class="font-mono">{{ formatCurrency(money(session.cash_total)) }}</span></div>
            <div class="flex justify-between"><span class="text-muted-foreground">Card / bank</span><span class="font-mono">{{ formatCurrency(money(session.bank_total)) }}</span></div>
            <div class="flex justify-between"><span class="text-muted-foreground">Sold on credit</span><span class="font-mono text-amber-600">{{ formatCurrency(money(session.due_total)) }}</span></div>
            <div class="flex justify-between"><span class="text-muted-foreground">Dues collected</span><span class="font-mono text-emerald-600">{{ formatCurrency(money(session.collected_total)) }}</span></div>
            <div class="flex justify-between border-t pt-1 font-bold"><span>Total sold</span><span class="font-mono">{{ formatCurrency(money(session.net_sales_total) + money(session.vat_total)) }}</span></div>
          </div>
          <p v-if="session.status === 'open'" class="mt-2 text-xs text-muted-foreground">
            These columns are written when the drawer closes. Until then the sales and collections below are the source of truth.
          </p>
          <p v-if="session.notes" class="mt-2 text-xs text-muted-foreground">Note: {{ session.notes }}</p>
        </div>

        <div class="rounded-xl border p-4" :class="outBy ? 'border-rose-500/30 bg-rose-500/[0.06]' : 'bg-card'">
          <h2 class="flex items-center gap-2 font-semibold"><Banknote class="size-4 text-primary" />Drawer count</h2>
          <div class="mt-2 space-y-1 text-sm">
            <div class="flex justify-between"><span class="text-muted-foreground">Float in</span><span class="font-mono">{{ formatCurrency(money(session.opening_cash)) }}</span></div>
            <div class="flex justify-between"><span class="text-muted-foreground">Should hold</span><span class="font-mono">{{ session.expected_cash === null ? '—' : formatCurrency(money(session.expected_cash)) }}</span></div>
            <div class="flex justify-between"><span class="text-muted-foreground">Counted</span><span class="font-mono">{{ session.closing_cash === null ? '—' : formatCurrency(money(session.closing_cash)) }}</span></div>
            <div class="flex justify-between border-t pt-1 font-bold">
              <span>Variance</span>
              <span class="font-mono" :class="!counted ? 'text-muted-foreground' : outBy ? 'text-rose-600' : 'text-emerald-600'">
                {{ !counted ? 'not counted' : formatCurrency(variance) }}
              </span>
            </div>
          </div>
          <p v-if="outBy" class="mt-2 flex items-start gap-1 text-xs text-rose-700 dark:text-rose-300">
            <TriangleAlert class="mt-0.5 size-3 shrink-0" />
            The drawer counted {{ variance < 0 ? 'short' : 'over' }} by {{ formatCurrency(Math.abs(variance)) }}. Voids are already excluded from what it should hold.
          </p>
          <p v-else-if="counted" class="mt-2 text-xs text-emerald-700 dark:text-emerald-300">The count matches the day.</p>
          <p v-else class="mt-2 text-xs text-muted-foreground">No count was taken for this drawer.</p>
          <p class="mt-2 text-xs text-muted-foreground">
            "Should hold" is the float plus cash taken at the counter, including dues collected in cash here.
          </p>
        </div>
      </div>

      <!-- The one journal -->
      <div class="rounded-xl border bg-card">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b p-3">
          <div>
            <h2 class="flex items-center gap-2 font-semibold"><Landmark class="size-4 text-primary" />Day-end journal</h2>
            <p class="text-xs text-muted-foreground">One entry for the whole drawer. No partner is named on it, so it cannot raise a receivable for the entire day.</p>
          </div>
          <Link v-if="journal" :href="route('accounts.journals.show', { journal: journal.id })">
            <Button variant="outline" size="sm"><FileText class="size-4" />Open {{ journal.journal_no }}</Button>
          </Link>
        </div>

        <div v-if="journal" class="overflow-auto">
          <StaticTable
            flush
            :empty="!journal?.lines?.length"
            empty-title="No journal lines"
            empty-description="The day-end journal for this session has no postings."
          >
            <thead class="bg-muted/60 text-xs uppercase tracking-wider text-muted-foreground">
              <tr>
                <th class="px-3 py-2 text-left font-semibold">Account</th>
                <th class="px-3 py-2 text-left font-semibold">Description</th>
                <th class="px-3 py-2 text-right font-semibold">Debit</th>
                <th class="px-3 py-2 text-right font-semibold">Credit</th>
              </tr>
            </thead>
            <tbody class="divide-y">
              <tr v-for="(line, i) in journal.lines" :key="i" class="hover:bg-muted/40">
                <td class="px-3 py-2">
                  <span class="rounded border bg-muted px-1.5 py-0.5 font-mono text-xs">{{ line.code ?? '—' }}</span>
                  <span class="ml-1.5">{{ line.account ?? '—' }}</span>
                </td>
                <td class="px-3 py-2 text-xs text-muted-foreground">{{ line.description }}</td>
                <td class="px-3 py-2 text-right font-mono">{{ money(line.debit) > 0.005 ? formatCurrency(money(line.debit)) : '—' }}</td>
                <td class="px-3 py-2 text-right font-mono">{{ money(line.credit) > 0.005 ? formatCurrency(money(line.credit)) : '—' }}</td>
              </tr>
            </tbody>
            <tfoot class="border-t bg-muted/60 font-bold">
              <tr>
                <td colspan="2" class="px-3 py-2 text-right">
                  {{ journal.journal_no }} · {{ journal.journal_date }} · {{ journal.status }}
                  <span class="ml-1 text-xs font-normal" :class="balanced ? 'text-emerald-600' : 'text-rose-600'">{{ balanced ? 'Dr = Cr ✓' : 'out of balance' }}</span>
                </td>
                <td class="px-3 py-2 text-right font-mono">{{ formatCurrency(money(journal.total_debit)) }}</td>
                <td class="px-3 py-2 text-right font-mono">{{ formatCurrency(money(journal.total_credit)) }}</td>
              </tr>
            </tfoot>
          </StaticTable>
          <p class="p-3 text-xs text-muted-foreground">{{ journal.narration }}</p>
        </div>
        <p v-else class="p-4 text-sm text-muted-foreground">
          {{ session.status === 'open'
            ? 'Nothing posted yet — the day posts when this drawer is counted and closed.'
            : 'This drawer closed without posting: it took no money all day, and an empty journal would only be noise in the ledger.' }}
        </p>
      </div>

      <!-- Sales and collections -->
      <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <div class="rounded-xl border bg-card">
          <div class="border-b p-3">
            <h2 class="font-semibold">Sales rung up</h2>
            <p class="text-xs text-muted-foreground">
              {{ liveSales.length }} transaction(s)<span v-if="voidedSales.length"> · {{ voidedSales.length }} voided</span>
              <span v-if="creditSold > 0"> · {{ formatCurrency(creditSold) }} sold on credit, {{ formatCurrency(stillOwed) }} still owed</span>
            </p>
          </div>
          <StaticTable flush sticky>
              <thead class="sticky top-0 bg-muted/90 text-xs uppercase tracking-wider text-muted-foreground backdrop-blur">
                <tr>
                  <th class="px-3 py-2 text-left font-semibold">Sale</th>
                  <th class="px-3 py-2 text-left font-semibold">Customer</th>
                  <th class="px-3 py-2 text-center font-semibold">Tender</th>
                  <th class="px-3 py-2 text-right font-semibold">Total</th>
                  <th class="px-3 py-2 text-right font-semibold">Owed</th>
                </tr>
              </thead>
              <tbody class="divide-y">
                <tr v-for="s in sales" :key="s.id" class="hover:bg-muted/40" :class="s.status === 'void' ? 'opacity-50' : ''">
                  <td class="px-3 py-2">
                    <Link :href="route('sales.pos.show', s.id)" class="font-mono text-xs font-bold text-primary hover:underline">{{ s.pos_no }}</Link>
                    <span class="block text-xs text-muted-foreground">{{ s.items }} item(s) · {{ s.time?.slice(11, 16) }}</span>
                  </td>
                  <td class="px-3 py-2 text-xs">{{ s.customer ?? 'Walk-in' }}</td>
                  <td class="px-3 py-2 text-center">
                    <span class="rounded bg-muted px-1.5 py-0.5 text-xs" :class="s.payment_method === 'due' ? 'text-amber-700 dark:text-amber-400' : ''">
                      {{ s.status === 'void' ? 'void' : (payLabels[s.payment_method] ?? s.payment_method) }}
                    </span>
                  </td>
                  <td class="px-3 py-2 text-right font-mono">{{ formatCurrency(money(s.total)) }}</td>
                  <td class="px-3 py-2 text-right font-mono">
                    <span v-if="money(s.due) - money(s.collected) > 0.005" class="font-bold text-amber-600">{{ formatCurrency(money(s.due) - money(s.collected)) }}</span>
                    <span v-else-if="money(s.due) > 0" class="text-xs text-emerald-600">settled</span>
                    <span v-else class="text-xs text-muted-foreground">—</span>
                  </td>
                </tr>
                <tr v-if="!sales.length"><td colspan="5" class="px-3 py-8 text-center text-muted-foreground">Nothing was rung up in this drawer.</td></tr>
              </tbody>
            </StaticTable>
        </div>

        <div class="rounded-xl border bg-card">
          <div class="border-b p-3">
            <h2 class="flex items-center gap-2 font-semibold"><HandCoins class="size-4 text-primary" />Dues collected here</h2>
            <p class="text-xs text-muted-foreground">
              Money taken against credit sales — including sales rung up on an earlier day. Each one settles a receivable and joins this
              drawer's takings.
            </p>
          </div>
          <StaticTable flush sticky>
              <thead class="sticky top-0 bg-muted/90 text-xs uppercase tracking-wider text-muted-foreground backdrop-blur">
                <tr>
                  <th class="px-3 py-2 text-left font-semibold">Collection</th>
                  <th class="px-3 py-2 text-left font-semibold">Customer</th>
                  <th class="px-3 py-2 text-left font-semibold">Against</th>
                  <th class="px-3 py-2 text-center font-semibold">Taken as</th>
                  <th class="px-3 py-2 text-right font-semibold">Amount</th>
                </tr>
              </thead>
              <tbody class="divide-y">
                <tr v-for="c in collections" :key="c.id" class="hover:bg-muted/40">
                  <td class="px-3 py-2">
                    <span class="font-mono text-xs font-bold">{{ c.collection_no }}</span>
                    <span class="block text-xs text-muted-foreground">{{ c.time?.slice(0, 16).replace('T', ' ') }}</span>
                  </td>
                  <td class="px-3 py-2 text-xs">{{ c.customer ?? '—' }}</td>
                  <td class="px-3 py-2 font-mono text-xs text-muted-foreground">{{ c.sale_no ?? '—' }}</td>
                  <td class="px-3 py-2 text-center"><span class="rounded bg-muted px-1.5 py-0.5 text-xs">{{ payLabels[c.method] ?? c.method }}</span></td>
                  <td class="px-3 py-2 text-right font-mono font-bold text-emerald-600">{{ formatCurrency(money(c.amount)) }}</td>
                </tr>
                <tr v-if="!collections.length"><td colspan="5" class="px-3 py-8 text-center text-muted-foreground">No dues were collected in this drawer.</td></tr>
              </tbody>
            </StaticTable>
        </div>
      </div>
    </div>
  </AdminLayout>
</template>
