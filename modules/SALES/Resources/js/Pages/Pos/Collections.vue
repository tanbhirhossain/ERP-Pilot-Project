<script setup lang="ts">
/**
 * The POS collection register — who collected what, from whom, against which sale.
 *
 * The day-end report already folds collections into the drawer that took them, which is the right
 * shape for reconciling a till and is what the ledger posts. It cannot answer the credit side of the
 * counter, though: which cashier brought in the most against old dues, which customer paid down what,
 * how much of it was cash and how much card. Those rows existed in `pos_collections` with nowhere to
 * be read, one at a time on a sale's own page or as a single summed figure inside a drawer row.
 *
 * Grouped here by `collected_by` — the person who actually took the money — rather than by the
 * drawer's opener, because when two cashiers work one supervisor's drawer the day-end cannot tell
 * them apart. Both views are right for their own question; this is the collector's.
 */
import { Head, Link, router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { HandCoins, Banknote, CreditCard, Users, ReceiptText, AlertCircle, ArrowLeft, Filter, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import PageHeader from '../../../../../CORE/Resources/js/Components/PageHeader.vue';
import SearchableSelect from '../../../../../CORE/Resources/js/Components/SearchableSelect.vue';
import { formatCurrency, formatDate } from '../../../../../CORE/Resources/js/Utils/format';
import StaticTable from '../../../../../CORE/Resources/js/Components/StaticTable.vue';

interface Row {
  id: number;
  collection_no: string;
  collected_at: string | null;
  collector: string | null;
  collector_id: number | null;
  customer: string | null;
  partner_id: number | null;
  sale_id: number | null;
  sale_no: string | null;
  method: string;
  bank: string | null;
  session_no: string | null;
  session_id: number | null;
  amount: string;
  notes: string | null;
}
interface CollectorRow {
  user_id: number | null;
  collector: string | null;
  collections: number;
  total: string;
  cash: string;
  bank: string;
}

const props = defineProps<{
  report: {
    rows: Row[];
    byCollector: CollectorRow[];
    totals: Record<string, any>;
    range: { from: string | null; to: string | null };
  };
  filters: Record<string, any>;
  collectors: any[];
  customers: any[];
}>();

const from = ref<string>(props.filters.from ?? props.report.range.from ?? '');
const to = ref<string>(props.filters.to ?? props.report.range.to ?? '');
const userId = ref<number | null>(props.filters.user_id ? Number(props.filters.user_id) : null);
const partnerId = ref<number | null>(props.filters.partner_id ? Number(props.filters.partner_id) : null);
const method = ref<string>(props.filters.method ?? '');

const methods = [
  { value: '', label: 'All methods' },
  { value: 'cash', label: 'Cash' },
  { value: 'bank', label: 'Bank' },
  { value: 'bkash', label: 'bKash' },
  { value: 'card', label: 'Card' },
];

function apply() {
  router.get(
    route('sales.pos.collections'),
    {
      from: from.value || undefined,
      to: to.value || undefined,
      user_id: userId.value ?? undefined,
      partner_id: partnerId.value ?? undefined,
      method: method.value || undefined,
    },
    { preserveState: true, preserveScroll: true },
  );
}

function reset() {
  from.value = '';
  to.value = '';
  userId.value = null;
  partnerId.value = null;
  method.value = '';
  apply();
}

const rows = computed(() => props.report.rows);
const byCollector = computed(() => props.report.byCollector);
const totals = computed(() => props.report.totals);
const money = (value: unknown): number => Number(value ?? 0);

/** Money that landed in no drawer, so no day-end report carries it. */

const methodTone = (m: string) => (m === 'cash' ? 'text-emerald-700 dark:text-emerald-400' : 'text-sky-700 dark:text-sky-400');
</script>

<template>
  <AdminLayout :breadcrumbs="[{ title: 'Sales & POS' }, { title: 'POS Collections' }]">
    <Head title="POS Collections" />

    <PageHeader
      title="POS Collections"
      description="Money taken at a counter against credit sales: per collector, per customer, per method."
      :icon="HandCoins"
      :actions="[{ label: 'Day-end report', route: 'sales.pos.reports.dayEnd', icon: ArrowLeft, variant: 'outline' }]"
    />

    <!-- ── Filters ─────────────────────────────────────────────────────── -->
    <div class="rounded-xl border bg-card p-4">
      <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
        <div class="space-y-1">
          <label class="text-xs font-medium" for="col-from">From</label>
          <Input id="col-from" v-model="from" type="date" />
        </div>
        <div class="space-y-1">
          <label class="text-xs font-medium" for="col-to">To</label>
          <Input id="col-to" v-model="to" type="date" />
        </div>
        <div class="space-y-1">
          <span class="text-xs font-medium">Collector</span>
          <SearchableSelect v-model="userId" :options="collectors" placeholder="All collectors" clearable />
        </div>
        <div class="space-y-1">
          <span class="text-xs font-medium">Customer</span>
          <SearchableSelect v-model="partnerId" :options="customers" placeholder="All customers" clearable />
        </div>
        <div class="space-y-1">
          <label class="text-xs font-medium" for="col-method">Method</label>
          <select
            id="col-method" v-model="method"
            class="h-10 w-full rounded-md border border-input bg-background px-3 text-sm ring-offset-background transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
          >
            <option v-for="m in methods" :key="m.value" :value="m.value">{{ m.label }}</option>
          </select>
        </div>
      </div>
      <div class="mt-3 flex flex-wrap items-center gap-2">
        <Button size="sm" @click="apply"><Filter class="size-4" />Apply</Button>
        <Button size="sm" variant="outline" @click="reset"><X class="size-4" />Reset</Button>
        <p class="ml-auto text-xs text-muted-foreground">
          {{ props.report.range.from ?? '…' }} to {{ props.report.range.to ?? '…' }} · {{ rows.length }} collection{{ rows.length === 1 ? '' : 's' }}
        </p>
      </div>
    </div>

    <!-- ── Headline ────────────────────────────────────────────────────── -->
    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
      <div class="rounded-xl border bg-card p-4">
        <p class="flex items-center gap-1 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">
          <HandCoins class="size-3" aria-hidden="true" /> Collected
        </p>
        <p class="mt-1 font-mono text-2xl font-bold tabular-nums text-emerald-600">{{ formatCurrency(money(totals.total)) }}</p>
        <p class="text-xs text-muted-foreground">{{ totals.collections ?? 0 }} payment{{ (totals.collections ?? 0) === 1 ? '' : 's' }} taken at the counter</p>
      </div>
      <div class="rounded-xl border bg-card p-4">
        <p class="flex items-center gap-1 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">
          <Banknote class="size-3" aria-hidden="true" /> In cash
        </p>
        <p class="mt-1 font-mono text-2xl font-bold tabular-nums">{{ formatCurrency(money(totals.cash)) }}</p>
        <p class="text-xs text-muted-foreground">Goes into the drawer that took it</p>
      </div>
      <div class="rounded-xl border bg-card p-4">
        <p class="flex items-center gap-1 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">
          <CreditCard class="size-3" aria-hidden="true" /> Bank, bKash &amp; card
        </p>
        <p class="mt-1 font-mono text-2xl font-bold tabular-nums">{{ formatCurrency(money(totals.bank)) }}</p>
        <p class="text-xs text-muted-foreground">Settles into the named account</p>
      </div>
    </div>

    <!-- ── By collector: the user-wise collection report ───────────────── -->
    <div class="rounded-xl border bg-card">
      <div class="flex flex-wrap items-center gap-2 border-b bg-muted/30 px-4 py-3">
        <Users class="size-4 text-primary" aria-hidden="true" />
        <h2 class="text-sm font-semibold">By collector</h2>
        <p class="ml-auto text-xs text-muted-foreground">Grouped by whoever took the money, not by whoever opened the drawer</p>
      </div>
      <StaticTable flush>
          <thead class="border-b bg-muted/20 text-[11px] uppercase tracking-wider text-muted-foreground">
            <tr>
              <th class="px-3 py-2 text-left font-semibold">Collector</th>
              <th class="px-3 py-2 text-right font-semibold">Collections</th>
              <th class="px-3 py-2 text-right font-semibold">Cash</th>
              <th class="px-3 py-2 text-right font-semibold">Bank / card</th>
              <th class="px-3 py-2 text-right font-semibold">Total</th>
            </tr>
          </thead>
          <tbody class="divide-y">
            <tr v-for="c in byCollector" :key="c.user_id ?? 'none'" class="hover:bg-muted/40">
              <td class="px-3 py-2 font-medium">{{ c.collector || 'Unknown collector' }}</td>
              <td class="px-3 py-2 text-right font-mono tabular-nums">{{ c.collections }}</td>
              <td class="px-3 py-2 text-right font-mono tabular-nums">{{ formatCurrency(money(c.cash)) }}</td>
              <td class="px-3 py-2 text-right font-mono tabular-nums">{{ formatCurrency(money(c.bank)) }}</td>
              <td class="px-3 py-2 text-right font-mono font-bold tabular-nums text-emerald-600">{{ formatCurrency(money(c.total)) }}</td>
            </tr>
            <tr v-if="!byCollector.length">
              <td colspan="5" class="px-3 py-8 text-center text-muted-foreground">No collections in this range.</td>
            </tr>
          </tbody>
          <tfoot v-if="byCollector.length" class="border-t bg-muted/40 font-bold">
            <tr>
              <td class="px-3 py-2">{{ byCollector.length }} collector{{ byCollector.length === 1 ? '' : 's' }}</td>
              <td class="px-3 py-2 text-right font-mono tabular-nums">{{ totals.collections ?? 0 }}</td>
              <td class="px-3 py-2 text-right font-mono tabular-nums">{{ formatCurrency(money(totals.cash)) }}</td>
              <td class="px-3 py-2 text-right font-mono tabular-nums">{{ formatCurrency(money(totals.bank)) }}</td>
              <td class="px-3 py-2 text-right font-mono tabular-nums">{{ formatCurrency(money(totals.total)) }}</td>
            </tr>
          </tfoot>
        </StaticTable>
    </div>

    <!-- ── The register itself ─────────────────────────────────────────── -->
    <div class="rounded-xl border bg-card">
      <div class="flex flex-wrap items-center gap-2 border-b bg-muted/30 px-4 py-3">
        <ReceiptText class="size-4 text-primary" aria-hidden="true" />
        <h2 class="text-sm font-semibold">Register</h2>
        <span class="rounded-full bg-muted px-2 py-0.5 text-[11px] font-semibold text-muted-foreground">{{ rows.length }}</span>
      </div>
      <StaticTable flush>
          <thead class="border-b bg-muted/20 text-[11px] uppercase tracking-wider text-muted-foreground">
            <tr>
              <th class="px-3 py-2 text-left font-semibold">No</th>
              <th class="px-3 py-2 text-left font-semibold">Taken</th>
              <th class="px-3 py-2 text-left font-semibold">Collector</th>
              <th class="px-3 py-2 text-left font-semibold">Customer</th>
              <th class="px-3 py-2 text-left font-semibold">Against sale</th>
              <th class="px-3 py-2 text-left font-semibold">Method</th>
              <th class="px-3 py-2 text-left font-semibold">Drawer</th>
              <th class="px-3 py-2 text-right font-semibold">Amount</th>
            </tr>
          </thead>
          <tbody class="divide-y">
            <tr v-for="r in rows" :key="r.id" class="hover:bg-muted/40">
              <td class="px-3 py-2 font-mono text-xs font-bold">{{ r.collection_no }}</td>
              <td class="px-3 py-2 text-xs text-muted-foreground">{{ r.collected_at ? formatDate(r.collected_at) : '—' }}</td>
              <td class="px-3 py-2">{{ r.collector || '—' }}</td>
              <td class="px-3 py-2">{{ r.customer || '—' }}</td>
              <td class="px-3 py-2">
                <Link v-if="r.sale_id" :href="route('sales.pos.show', { sale: r.sale_id })" class="font-mono text-xs text-primary hover:underline">{{ r.sale_no }}</Link>
                <span v-else class="text-xs text-muted-foreground">—</span>
              </td>
              <td class="px-3 py-2">
                <span class="text-xs font-semibold capitalize" :class="methodTone(r.method)">{{ r.method }}</span>
                <span v-if="r.bank" class="block text-[11px] text-muted-foreground">{{ r.bank }}</span>
              </td>
              <td class="px-3 py-2">
                <Link v-if="r.session_id" :href="route('sales.pos.sessions.dayEnd', { session: r.session_id })" class="font-mono text-xs text-primary hover:underline">{{ r.session_no }}</Link>
                <span v-else class="inline-flex items-center gap-1 rounded-md bg-amber-500/15 px-1.5 py-0.5 text-[11px] font-semibold text-amber-700 dark:text-amber-400">
                  <AlertCircle class="size-3" aria-hidden="true" /> none
                </span>
              </td>
              <td class="px-3 py-2 text-right font-mono font-bold tabular-nums">{{ formatCurrency(money(r.amount)) }}</td>
            </tr>
            <tr v-if="!rows.length">
              <td colspan="8" class="px-3 py-10 text-center">
                <p class="text-sm font-medium text-muted-foreground">Nothing collected in this range</p>
                <p class="mt-0.5 text-xs text-muted-foreground/80">Widen the dates, or clear the collector and customer filters.</p>
              </td>
            </tr>
          </tbody>
        </StaticTable>
      <p class="border-t px-4 py-3 text-xs leading-relaxed text-muted-foreground">
        A collection settles a receivable and joins the day-end journal of the drawer that took it (Dr cash or bank, Cr receivables 1100).
        It is not a journal of its own: posting it separately would put two entries in the ledger for one day's takings and break the
        reconciliation between the drawer count and the books. Collections taken with no drawer open are flagged above, because no
        day-end report can carry them.
      </p>
    </div>
  </AdminLayout>
</template>
