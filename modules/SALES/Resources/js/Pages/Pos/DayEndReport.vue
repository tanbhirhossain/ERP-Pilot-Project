<script setup lang="ts">
/**
 * The POS day-end and user-wise collection report.
 *
 * One row per drawer, and the same day folded up per cashier. The point of the screen is the second
 * table: with several tills and several cashiers, "how much did we take" is not a useful question —
 * "who took it, in what tender, and whose drawer counted short" is. Credit sales are shown separately
 * from takings because they are money the business is owed rather than money in a drawer, and counter
 * collections are attributed to the cashier who took the payment, which is the day the ledger posts
 * them in (report item 39).
 */
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Banknote, CalendarDays, CreditCard, FileText, HandCoins, Landmark, Printer, ReceiptText, TriangleAlert, Users } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import { printDocument } from '@core/Composables/usePrint';
import SearchableSelect from '@core/Components/SearchableSelect.vue';
import StatusBadge from '@core/Components/StatusBadge.vue';
import { formatCurrency } from '@core/Utils/format';
import StaticTable from '@core/Components/StaticTable.vue';

interface ReportRow {
  session_id: number;
  session_no: string;
  cashier: string;
  user_id: number;
  warehouse: string;
  date: string | null;
  status: string;
  transactions: number;
  voids: number;
  gross: string;
  discount: string;
  net: string;
  vat: string;
  cogs: string;
  cash: string;
  bank: string;
  due: string;
  collected: string;
  collections: number;
  takings: string;
  drawer_cash: string;
  opening_cash: string | null;
  expected_cash: string | null;
  closing_cash: string | null;
  variance: string | null;
  journal_id: number | null;
  journal_no: string | null;
  posted_at: string | null;
}

interface UserRow {
  user_id: number;
  cashier: string;
  sessions: number;
  transactions: number;
  voids: number;
  collections: number;
  gross: string;
  discount: string;
  net: string;
  vat: string;
  cogs: string;
  cash: string;
  bank: string;
  due: string;
  collected: string;
  takings: string;
  variance: string | null;
}

const props = defineProps<{
  report: { rows: ReportRow[]; byUser: UserRow[]; totals: Record<string, any>; range: { from: string; to: string } };
  filters: Record<string, any>;
  cashiers: { value: number; label: string }[];
  warehouses: { value: number; label: string }[];
}>();

const from = ref<string>(props.filters.from ?? props.report.range.from ?? '');
const to = ref<string>(props.filters.to ?? props.report.range.to ?? '');
const userId = ref<number | null>(props.filters.user_id ? Number(props.filters.user_id) : null);
const warehouseId = ref<number | null>(props.filters.warehouse_id ? Number(props.filters.warehouse_id) : null);
const status = ref<string>(props.filters.status ?? '');

const statuses = [
  { value: '', label: 'All' },
  { value: 'open', label: 'Open' },
  { value: 'closed', label: 'Closed' },
];

function apply() {
  router.get(
    route('sales.pos.reports.dayEnd'),
    {
      from: from.value || undefined,
      to: to.value || undefined,
      user_id: userId.value ?? undefined,
      warehouse_id: warehouseId.value ?? undefined,
      status: status.value || undefined,
    },
    { preserveState: true, preserveScroll: true },
  );
}

function reset() {
  from.value = '';
  to.value = '';
  userId.value = null;
  warehouseId.value = null;
  status.value = '';
  apply();
}

const totals = computed(() => props.report.totals);
const rows = computed(() => props.report.rows);
const byUser = computed(() => props.report.byUser);
const money = (value: unknown): number => Number(value ?? 0);

/** Cash drawers that did not count to what the day says they should hold. */
const variances = computed(() => rows.value.filter((r) => r.variance !== null && Math.abs(money(r.variance)) > 0.005));
const varianceTotal = computed(() => variances.value.reduce((sum, r) => sum + money(r.variance), 0));
const openDrawers = computed(() => rows.value.filter((r) => r.status === 'open').length);
// `window` is not in a template's scope for the type-checker; keep it in script.
function printPage(): void {
    printDocument();
}

</script>

<template>
  <AdminLayout :breadcrumbs="[{ title: 'Sales & POS' }, { title: 'POS Day-End' }]">
    <Head title="POS Day-End & Collections" />

    <div class="space-y-4">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 class="flex items-center gap-2 text-xl font-bold"><ReceiptText class="size-5 text-primary" />POS Day-End &amp; Collections</h1>
          <p class="mt-1 max-w-3xl text-sm text-muted-foreground">
            Each drawer posts one journal when it closes — cash, card, credit sales, VAT and cost of goods together. This is that day
            broken back out per cashier, with the dues collected at the counter counted in the day that collected them.
          </p>
        </div>
        <div class="flex gap-2">
          <Button variant="outline" size="sm" @click="printPage"><Printer class="size-4" />Print</Button>
          <Link :href="route('sales.pos.sessions')"><Button variant="outline" size="sm"><CalendarDays class="size-4" />Sessions</Button></Link>
        </div>
      </div>

      <!-- KPI strip -->
      <div class="grid grid-cols-2 gap-3 lg:grid-cols-6">
        <div class="rounded-xl border bg-card p-3">
          <p class="text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">Takings</p>
          <p class="mt-1 font-mono text-lg font-bold">{{ formatCurrency(money(totals.takings)) }}</p>
          <p class="text-xs text-muted-foreground">cash + card + collections</p>
        </div>
        <div class="rounded-xl border bg-card p-3">
          <p class="flex items-center gap-1 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground"><Banknote class="size-3" />Cash</p>
          <p class="mt-1 font-mono text-lg font-bold">{{ formatCurrency(money(totals.cash)) }}</p>
          <p class="text-xs text-muted-foreground">sale tender only</p>
        </div>
        <div class="rounded-xl border bg-card p-3">
          <p class="flex items-center gap-1 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground"><CreditCard class="size-3" />Card / bank</p>
          <p class="mt-1 font-mono text-lg font-bold">{{ formatCurrency(money(totals.bank)) }}</p>
          <p class="text-xs text-muted-foreground">settled per account</p>
        </div>
        <div class="rounded-xl border bg-card p-3">
          <p class="flex items-center gap-1 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground"><HandCoins class="size-3" />Dues collected</p>
          <p class="mt-1 font-mono text-lg font-bold text-emerald-600">{{ formatCurrency(money(totals.collected)) }}</p>
          <p class="text-xs text-muted-foreground">{{ totals.collections ?? 0 }} collection(s)</p>
        </div>
        <div class="rounded-xl border bg-card p-3">
          <p class="flex items-center gap-1 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground"><Landmark class="size-3" />Sold on credit</p>
          <p class="mt-1 font-mono text-lg font-bold text-amber-600">{{ formatCurrency(money(totals.due)) }}</p>
          <p class="text-xs text-muted-foreground">raised to AR 1100</p>
        </div>
        <div class="rounded-xl border p-3" :class="variances.length ? 'border-rose-500/30 bg-rose-500/[0.06]' : 'bg-card'">
          <p class="flex items-center gap-1 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground"><TriangleAlert class="size-3" />Drawer variance</p>
          <p class="mt-1 font-mono text-lg font-bold" :class="variances.length ? 'text-rose-600' : 'text-emerald-600'">
            {{ variances.length ? formatCurrency(varianceTotal) : '—' }}
          </p>
          <p class="text-xs text-muted-foreground">{{ variances.length ? `${variances.length} drawer(s) off` : 'every count matched' }}</p>
        </div>
      </div>

      <!-- Filters -->
      <div class="flex flex-wrap items-end gap-3 rounded-xl border bg-card p-3">
        <div class="space-y-1">
          <label class="text-xs font-medium" for="pdr-from">From</label>
          <Input id="pdr-from" v-model="from" type="date" class="h-9 w-40" />
        </div>
        <div class="space-y-1">
          <label class="text-xs font-medium" for="pdr-to">To</label>
          <Input id="pdr-to" v-model="to" type="date" class="h-9 w-40" />
        </div>
        <div class="w-48 space-y-1">
          <label class="text-xs font-medium">Cashier</label>
          <SearchableSelect v-model="userId" :options="cashiers" placeholder="All cashiers" clearable />
        </div>
        <div class="w-52 space-y-1">
          <label class="text-xs font-medium">Warehouse / till</label>
          <SearchableSelect v-model="warehouseId" :options="warehouses" placeholder="All tills" clearable />
        </div>
        <div class="space-y-1">
          <label class="text-xs font-medium">Drawer</label>
          <div class="flex gap-1">
            <button
              v-for="s in statuses"
              :key="s.value"
              type="button"
              class="rounded-md border px-3 py-2 text-sm transition"
              :class="status === s.value ? 'border-primary bg-primary/10 font-semibold text-primary' : 'hover:bg-muted'"
              @click="status = s.value"
            >
              {{ s.label }}
            </button>
          </div>
        </div>
        <Button size="sm" class="h-9" @click="apply">Apply</Button>
        <Button size="sm" variant="outline" class="h-9" @click="reset">Clear</Button>
        <span class="ml-auto hidden text-xs text-muted-foreground sm:inline">
          {{ rows.length }} drawer(s) · {{ openDrawers }} still open · BDT
        </span>
      </div>

      <!-- By cashier: the user-wise collection report -->
      <div class="rounded-xl border bg-card">
        <div class="border-b p-3">
          <h2 class="flex items-center gap-2 font-semibold"><Users class="size-4 text-primary" />By cashier</h2>
          <p class="text-xs text-muted-foreground">
            Collections are credited to whoever took the money, not whoever made the sale — the ledger posts them in the collecting day.
          </p>
        </div>
        <StaticTable flush>
            <thead class="bg-muted/60 text-xs uppercase tracking-wider text-muted-foreground">
              <tr>
                <th class="px-3 py-2 text-left font-semibold">Cashier</th>
                <th class="px-3 py-2 text-right font-semibold">Drawers</th>
                <th class="px-3 py-2 text-right font-semibold">Sales</th>
                <th class="px-3 py-2 text-right font-semibold">Voids</th>
                <th class="px-3 py-2 text-right font-semibold">Net sales</th>
                <th class="px-3 py-2 text-right font-semibold">VAT</th>
                <th class="px-3 py-2 text-right font-semibold">Cash</th>
                <th class="px-3 py-2 text-right font-semibold">Card / bank</th>
                <th class="px-3 py-2 text-right font-semibold">Credit sold</th>
                <th class="px-3 py-2 text-right font-semibold">Collected</th>
                <th class="px-3 py-2 text-right font-semibold">Takings</th>
                <th class="px-3 py-2 text-right font-semibold">Variance</th>
              </tr>
            </thead>
            <tbody class="divide-y">
              <tr v-for="u in byUser" :key="u.user_id" class="hover:bg-muted/40">
                <td class="px-3 py-2 font-medium">{{ u.cashier }}</td>
                <td class="px-3 py-2 text-right font-mono">{{ u.sessions }}</td>
                <td class="px-3 py-2 text-right font-mono">{{ u.transactions }}</td>
                <td class="px-3 py-2 text-right font-mono" :class="u.voids ? 'text-amber-600' : 'text-muted-foreground'">{{ u.voids }}</td>
                <td class="px-3 py-2 text-right font-mono">{{ formatCurrency(money(u.net)) }}</td>
                <td class="px-3 py-2 text-right font-mono text-muted-foreground">{{ formatCurrency(money(u.vat)) }}</td>
                <td class="px-3 py-2 text-right font-mono">{{ formatCurrency(money(u.cash)) }}</td>
                <td class="px-3 py-2 text-right font-mono">{{ formatCurrency(money(u.bank)) }}</td>
                <td class="px-3 py-2 text-right font-mono text-amber-600">{{ formatCurrency(money(u.due)) }}</td>
                <td class="px-3 py-2 text-right font-mono text-emerald-600">{{ formatCurrency(money(u.collected)) }}</td>
                <td class="px-3 py-2 text-right font-mono font-bold">{{ formatCurrency(money(u.takings)) }}</td>
                <td class="px-3 py-2 text-right font-mono" :class="u.variance === null ? 'text-muted-foreground' : Math.abs(money(u.variance)) > 0.005 ? 'font-bold text-rose-600' : 'text-emerald-600'">
                  {{ u.variance === null ? 'not counted' : formatCurrency(money(u.variance)) }}
                </td>
              </tr>
              <tr v-if="!byUser.length">
                <td colspan="12" class="px-3 py-10 text-center text-muted-foreground">No drawers in this range.</td>
              </tr>
            </tbody>
            <tfoot v-if="byUser.length" class="border-t bg-muted/60 font-bold">
              <tr>
                <td class="px-3 py-2">Total</td>
                <td class="px-3 py-2 text-right font-mono">{{ totals.sessions }}</td>
                <td class="px-3 py-2 text-right font-mono">{{ totals.transactions }}</td>
                <td class="px-3 py-2 text-right font-mono">{{ totals.voids }}</td>
                <td class="px-3 py-2 text-right font-mono">{{ formatCurrency(money(totals.net)) }}</td>
                <td class="px-3 py-2 text-right font-mono">{{ formatCurrency(money(totals.vat)) }}</td>
                <td class="px-3 py-2 text-right font-mono">{{ formatCurrency(money(totals.cash)) }}</td>
                <td class="px-3 py-2 text-right font-mono">{{ formatCurrency(money(totals.bank)) }}</td>
                <td class="px-3 py-2 text-right font-mono">{{ formatCurrency(money(totals.due)) }}</td>
                <td class="px-3 py-2 text-right font-mono">{{ formatCurrency(money(totals.collected)) }}</td>
                <td class="px-3 py-2 text-right font-mono">{{ formatCurrency(money(totals.takings)) }}</td>
                <td class="px-3 py-2 text-right font-mono">{{ totals.variance === null ? '—' : formatCurrency(money(totals.variance)) }}</td>
              </tr>
            </tfoot>
          </StaticTable>
      </div>

      <!-- Per drawer -->
      <div class="rounded-xl border bg-card">
        <div class="border-b p-3">
          <h2 class="flex items-center gap-2 font-semibold"><ReceiptText class="size-4 text-primary" />Drawers</h2>
          <p class="text-xs text-muted-foreground">
            An open drawer is reported from its sales, so a till that is still trading is not shown as empty. Its totals post when it closes.
          </p>
        </div>
        <StaticTable flush>
            <thead class="bg-muted/60 text-xs uppercase tracking-wider text-muted-foreground">
              <tr>
                <th class="px-3 py-2 text-left font-semibold">Drawer</th>
                <th class="px-3 py-2 text-left font-semibold">Cashier</th>
                <th class="px-3 py-2 text-left font-semibold">Till</th>
                <th class="px-3 py-2 text-center font-semibold">Status</th>
                <th class="px-3 py-2 text-right font-semibold">Sales</th>
                <th class="px-3 py-2 text-right font-semibold">Net</th>
                <th class="px-3 py-2 text-right font-semibold">Cash</th>
                <th class="px-3 py-2 text-right font-semibold">Card</th>
                <th class="px-3 py-2 text-right font-semibold">Credit</th>
                <th class="px-3 py-2 text-right font-semibold">Collected</th>
                <th class="px-3 py-2 text-right font-semibold">Expected</th>
                <th class="px-3 py-2 text-right font-semibold">Counted</th>
                <th class="px-3 py-2 text-right font-semibold">Variance</th>
                <th class="px-3 py-2 text-left font-semibold">Journal</th>
              </tr>
            </thead>
            <tbody class="divide-y">
              <tr v-for="r in rows" :key="r.session_id" class="hover:bg-muted/40">
                <td class="px-3 py-2">
                  <Link :href="route('sales.pos.sessions.dayEnd', { session: r.session_id })" class="font-mono text-xs font-bold text-primary hover:underline">
                    {{ r.session_no }}
                  </Link>
                  <span class="block text-xs text-muted-foreground">{{ r.date ?? '—' }}</span>
                </td>
                <td class="px-3 py-2">{{ r.cashier }}</td>
                <td class="px-3 py-2 text-xs text-muted-foreground">{{ r.warehouse }}</td>
                <td class="px-3 py-2 text-center"><StatusBadge :label="r.status" :tone="r.status === 'open' ? 'success' : 'neutral'" /></td>
                <td class="px-3 py-2 text-right font-mono">
                  {{ r.transactions }}
                  <span v-if="r.voids" class="text-xs text-amber-600"> (+{{ r.voids }} void)</span>
                </td>
                <td class="px-3 py-2 text-right font-mono">{{ formatCurrency(money(r.net)) }}</td>
                <td class="px-3 py-2 text-right font-mono">{{ formatCurrency(money(r.cash)) }}</td>
                <td class="px-3 py-2 text-right font-mono">{{ formatCurrency(money(r.bank)) }}</td>
                <td class="px-3 py-2 text-right font-mono text-amber-600">{{ formatCurrency(money(r.due)) }}</td>
                <td class="px-3 py-2 text-right font-mono text-emerald-600">{{ formatCurrency(money(r.collected)) }}</td>
                <td class="px-3 py-2 text-right font-mono text-muted-foreground">{{ r.expected_cash === null ? '—' : formatCurrency(money(r.expected_cash)) }}</td>
                <td class="px-3 py-2 text-right font-mono text-muted-foreground">{{ r.closing_cash === null ? '—' : formatCurrency(money(r.closing_cash)) }}</td>
                <td
                  class="px-3 py-2 text-right font-mono"
                  :class="r.variance === null ? 'text-muted-foreground' : Math.abs(money(r.variance)) > 0.005 ? 'font-bold text-rose-600' : 'text-emerald-600'"
                >
                  {{ r.variance === null ? '—' : formatCurrency(money(r.variance)) }}
                </td>
                <td class="px-3 py-2">
                  <span v-if="r.journal_no" class="font-mono text-xs text-primary">{{ r.journal_no }}</span>
                  <span v-else class="text-xs text-muted-foreground">{{ r.status === 'open' ? 'posts at close' : 'nothing to post' }}</span>
                </td>
              </tr>
              <tr v-if="!rows.length">
                <td colspan="14" class="px-3 py-10 text-center text-muted-foreground">
                  No drawers between {{ report.range.from || 'the beginning' }} and {{ report.range.to || 'today' }}.
                </td>
              </tr>
            </tbody>
          </StaticTable>
      </div>

      <div class="flex items-start gap-2 rounded-xl border bg-muted/30 p-3 text-xs text-muted-foreground">
        <FileText class="mt-0.5 size-3.5 shrink-0" />
        <p>
          Takings = cash + card + dues collected; credit sales are excluded, because they are money owed rather than money in a drawer.
          The cash a drawer should hold also includes counter collections, so reconcile the count against the drawer's own Z-report, not
          against the cash column here. Nothing on this screen writes to the ledger — the posting happens once, when a drawer closes.
        </p>
      </div>
    </div>
  </AdminLayout>
</template>
