<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  ShoppingCart, Scan, Trash2, CreditCard, Banknote, Wallet, Smartphone, Percent, AlertCircle,
  CheckCircle, Package, Search, Plus, Minus, X, Coins, Split, ArrowLeftRight,
} from 'lucide-vue-next';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import SearchableSelect from '@core/Components/SearchableSelect.vue';
import { CloudOff, RefreshCw } from 'lucide-vue-next';
// F22 — one payload builder for the live POST and the offline queue, so the two can never drift.
import { buildSalePayload, cartLabel } from '@sales/offline/payload';
import { offlineStockWarning } from '@sales/offline/catalog';
import { useOfflineTill } from '@sales/offline/useOfflineTill';
import { unwrapOptions } from '@core/Utils/optionsPayload';
// The house unwrapper: INVENTORY answers `{data:[…]}`, CORE and HRM answer `{items:[…]}`, and a
// paginated resource nests twice. Hand-rolling one shape here is how a picker comes to look empty.
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';

const props = defineProps<{
  warehouses: any[];
  customers: any[];
  banks: any[];
  openSession: any;
  /** Every open drawer, so the till can sell into the one belonging to its warehouse. */
  openSessions?: any[];
  canManageSessions?: boolean;
  canViewReports?: boolean;
}>();

const warehouseId = ref<number | null>(null);
const customerId = ref<number | null>(null);
const paymentMethod = ref('cash');
const bankId = ref<number | null>(null);
const sessionId = ref<number | null>(null);
const cart = ref<any[]>([]);
const searchProduct = ref<number | null>(null);
const barcodeInput = ref('');
const barcodeRef = ref<HTMLInputElement | null>(null);
const cashOpening = ref(0);
/*
 * The counted drawer, held as text. Blank means "closed without a count", which the service records
 * as no variance at all; a number ref cannot tell an untouched field from a counted zero, and
 * sending 0 is how every uncounted drawer used to be reported short by its entire takings.
 */
const closingCount = ref('');
const drawerError = ref('');
const cashAmt = ref<number | null>(null);
const bankAmt = ref<number | null>(null);
const lastScanned = ref<string>('');
const scanError = ref('');
const adding = ref(false);
const stockWarning = ref('');

/*
 * Tendering.
 *
 * `tendered` is the note the customer actually handed over, so a 600 BDT sale paid with a 1,000
 * note can tell the cashier to give back 400 — the till used to have a `change_amount` column that
 * nothing ever wrote to. `paidNow` is the money taken on a credit sale, which makes "half payment"
 * a thing the till can record: the rest becomes the due, against a named customer, and the day-end
 * posting raises a receivable for exactly that remainder.
 */
const tendered = ref<number | null>(null);
const paidNow = ref<number | null>(null);

// Catalogue grid
const catalog = ref<any[]>([]);
const catalogSearch = ref('');
const catalogLoading = ref(false);
const catalogError = ref('');
let catalogTimer: number | undefined;

const page = usePage() as any;

/*
 * F22 — the offline till.
 *
 * Two things arrive here, and only one of them is about a dead network:
 *
 *  1. `useOfflineTill` keeps the product catalogue on the device and holds the queue. A cashier can
 *     scan and ring up with no server at all; the tickets go to the ledger by themselves when the
 *     link returns.
 *  2. `submissionRef` is the device reference minted *when Sell is pressed*. It makes a repeat of the
 *     same submission one sale instead of two — which is worth having even on a perfect network,
 *     because a slow response and an impatient cashier is the oldest till bug there is. It is cleared
 *     whenever the cart changes, so a *corrected* sale always gets a fresh key rather than being
 *     silently swallowed by the first attempt.
 */
const offline = useOfflineTill({
  syncUrl: route('sales.pos.offline.sync'),
  catalogUrl: route('inventory.api.pos-catalog'),
  productUrlFor: (key) => `/inventory/api/pos-product/${encodeURIComponent(String(key))}`,
  warehouseId: () => warehouseId.value,
});
const submissionRef = ref<string | null>(null);
const queuedNote = ref('');

function nextSubmissionRef(): string {
  if (!submissionRef.value) {
    submissionRef.value = typeof crypto !== 'undefined' && 'randomUUID' in crypto
      ? crypto.randomUUID()
      : `t-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
  }

  return submissionRef.value;
}

/** Any change to the cart is a different sale, so it needs a different key. */
watch(cart, () => { submissionRef.value = null; queuedNote.value = ''; }, { deep: true });

const waitingCount = computed(() => offline.summary.value.queued + offline.summary.value.attention);
const offlineMode = computed(() => !offline.online.value);

/*
 * A cashier works the scanner, not the mouse: both banners clear themselves so
 * the next scan is not read against a stale message, and they are announced
 * because the field keeps focus and the eye stays on the barcode input.
 */
let feedbackTimer: number | undefined;
function announceSuccess(message: string) {
  lastScanned.value = message;
  scanError.value = '';
  stockWarning.value = '';
  window.clearTimeout(feedbackTimer);
  feedbackTimer = window.setTimeout(() => { lastScanned.value = ''; }, 4000);
}
function announceError() {
  lastScanned.value = '';
  window.clearTimeout(feedbackTimer);
  feedbackTimer = window.setTimeout(() => { scanError.value = ''; }, 6000);
}
onBeforeUnmount(() => { window.clearTimeout(feedbackTimer); window.clearTimeout(catalogTimer); });

function lineGross(it: any) { return (Number(it.qty) || 0) * (Number(it.unit_price) || 0); }
function lineDisc(it: any) { return lineGross(it) * ((Number(it.discount_pct) || 0) / 100); }
function lineNet(it: any) { return lineGross(it) - lineDisc(it); }
function lineVat(it: any) { return lineNet(it) * ((Number(it.vat_rate) || 0) / 100); }
function lineTotal(it: any) { return lineNet(it) + lineVat(it); }

const subtotal = computed(() => cart.value.reduce((s, it) => s + lineGross(it), 0));
const discountTotal = computed(() => cart.value.reduce((s, it) => s + lineDisc(it), 0));
const vatTotal = computed(() => cart.value.reduce((s, it) => s + lineVat(it), 0));
const grandTotal = computed(() => subtotal.value - discountTotal.value + vatTotal.value);
const itemCount = computed(() => cart.value.reduce((s, it) => s + (Number(it.qty) || 0), 0));
const money = (v: number) => (Number(v) || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const splitValid = computed(() => {
  if (paymentMethod.value !== 'split') return true;
  const c = Number(cashAmt.value) || 0, b = Number(bankAmt.value) || 0;
  return Math.abs((c + b) - grandTotal.value) < 0.02;
});
const needsBank = computed(() => ['bank','bkash','card','split'].includes(paymentMethod.value));
// A credit sale has to be owed by somebody: the day-end posting raises a receivable per customer, and
// a debt against "Walk-in Customer" can never be aged, chased or collected.
const dueNeedsCustomer = computed(() => paymentMethod.value === 'due' && !customerId.value);

/** What was actually taken now, and what is still owed. Both clamped to the sale. */
const paidNowCents = computed(() => {
  const raw = Math.round((Number(paidNow.value) || 0) * 100);
  return Math.max(0, Math.min(raw, Math.round(grandTotal.value * 100)));
});
const balanceDue = computed(() => (paymentMethod.value === 'due'
  ? (Math.round(grandTotal.value * 100) - paidNowCents.value) / 100
  : 0));
const isPartPaid = computed(() => paymentMethod.value === 'due' && paidNowCents.value > 0 && balanceDue.value > 0.005);

/** Change is only meaningful once the whole sale is being settled in hand. */
const tenderedCents = computed(() => Math.max(0, Math.round((Number(tendered.value) || 0) * 100)));
const changeDue = computed(() => {
  if (paymentMethod.value === 'due' || paymentMethod.value === 'split') return 0;
  const total = Math.round(grandTotal.value * 100);
  return tenderedCents.value > total ? (tenderedCents.value - total) / 100 : 0;
});
const tenderShort = computed(() => {
  if (paymentMethod.value === 'due' || paymentMethod.value === 'split') return 0;
  if (!tenderedCents.value) return 0;
  const total = Math.round(grandTotal.value * 100);
  return tenderedCents.value < total ? (total - tenderedCents.value) / 100 : 0;
});
const tenderIncomplete = computed(() => tenderShort.value > 0.005);

const canSell = computed(() =>
  !!warehouseId.value
  && cart.value.length > 0
  && (paymentMethod.value !== 'split' || splitValid.value)
  && !dueNeedsCustomer.value
  && !tenderIncomplete.value,
);
const selectedCustomer = computed(() => props.customers.find((c: any) => c.value === customerId.value));

/**
 * The drawer this till is selling into.
 *
 * `show()` and `collect()` both resolve "the open drawer" by warehouse, but this page used to
 * recognise only a drawer the signed-in user had opened themselves — so when a supervisor opened the
 * drawer for a till, the cashier's screen reported no session, sales were written with no drawer
 * against them, and the cashier's only apparent option was to open a second drawer for the same
 * till. Same question, same answer everywhere now: the warehouse's drawer, else my own, else the
 * most recently opened.
 */
const activeDrawer = computed(() => {
  const drawers: any[] = props.openSessions?.length ? props.openSessions : (props.openSession ? [props.openSession] : []);
  if (!drawers.length) return null;
  if (warehouseId.value) {
    const forWarehouse = drawers.find((d: any) => Number(d.warehouse_id) === Number(warehouseId.value));
    if (forWarehouse) return forWarehouse;
  }

  return drawers.find((d: any) => d.mine || d.id === props.openSession?.id) ?? drawers[0];
});
watch(activeDrawer, (d) => { sessionId.value = d?.id ?? null; }, { immediate: true });

const METHODS = [
  { id: 'cash', label: 'Cash', icon: Banknote },
  { id: 'bank', label: 'Bank', icon: CreditCard },
  { id: 'bkash', label: 'bKash', icon: Smartphone },
  { id: 'card', label: 'Card', icon: CreditCard },
  { id: 'split', label: 'Split', icon: Split },
  { id: 'due', label: 'Due / Part paid', icon: Wallet },
];

/** Notes a Bangladeshi till actually keeps in the drawer, plus the exact amount. */
const quickCash = computed(() => {
  const total = Math.round(grandTotal.value * 100);
  const notes = [50, 100, 500, 1000, 2000, 5000]
    .map((n) => Math.ceil(total / (n * 100)) * n)
    .filter((n, i, arr) => n * 100 >= total && arr.indexOf(n) === i);
  return [...new Set(notes)].slice(0, 4);
});

function setPayment(id: string) {
  paymentMethod.value = id;
  if (id !== 'cash') tendered.value = null;
  if (id !== 'due') paidNow.value = null;
}
function setTendered(v: number) { tendered.value = v; }
function payHalf() { paidNow.value = Math.round(grandTotal.value * 100 / 2) / 100; }
function payNothing() { paidNow.value = 0; }
function payAll() { paymentMethod.value = 'cash'; tendered.value = Math.round(grandTotal.value * 100) / 100; }

/* ── catalogue ─────────────────────────────────────────────────────────── */
/** Serve the grid from the device's snapshot. Returns whether anything was there. */
function useCachedCatalog(term = '') {
  const cached = offline.searchCached(term.trim(), 60);

  if (!cached.length) return false;

  catalog.value = cached as any[];
  catalogError.value = '';

  return true;
}

async function loadCatalog(term = '') {
  catalogLoading.value = true;
  catalogError.value = '';

  if (!offline.online.value) {
    if (!useCachedCatalog(term)) {
      catalogError.value = 'No network, and this device has no saved catalogue. Open the offline desk and refresh it while the link is up.';
    }
    catalogLoading.value = false;

    return;
  }

  try {
    const url = `/inventory/api/options/products?limit=60${term ? `&search=${encodeURIComponent(term)}` : ''}`;
    const res = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
    if (!res.ok) throw new Error(String(res.status));
    const j = await res.json();
    // Tiles carry sku, price, barcode and vat_rate beyond the label/value contract, so the rows are
    // kept loose; unwrapOptions has already established that they are option objects.
    catalog.value = unwrapOptions(j) as any[];
  } catch {
    // No server is not no catalogue: the snapshot from the last successful refresh answers name, sku,
    // barcode, price, VAT and the stock figure the grid warns with. Only if even that is missing is
    // the till really blind.
    if (!useCachedCatalog(term)) {
      catalogError.value = 'Could not load the product grid, and this device has no saved catalogue. Sync it from the offline desk when the network is back.';
    }
  } finally {
    catalogLoading.value = false;
  }
}
watch(catalogSearch, (term) => {
  window.clearTimeout(catalogTimer);
  catalogTimer = window.setTimeout(() => loadCatalog(term.trim()), 300);
});

function pushLine(data: any) {
  const existing = cart.value.find((c) => c.product_id === data.id);
  if (existing) { existing.qty = Number(existing.qty) + 1; }
  else {
    cart.value.push({
      product_id: data.id, product_name: data.name ?? data.label, sku: data.sku, barcode: data.barcode,
      qty: 1, unit_price: Number(data.selling_price ?? data.price), discount_pct: 0,
      vat_rate: Number(data.vat_rate) || 0, available: data.available,
    });
  }
  announceSuccess(`${data.name ?? data.label} — ${Number(data.selling_price ?? data.price).toFixed(2)} BDT`);
  if (data.available !== undefined) checkStock(data);
}

/**
 * A tile carries name, sku, barcode, price and VAT but not the stock figure, so the line goes into
 * the cart straight away and `available` is filled in behind it — the till stays instant and the
 * oversell warning still arrives before the sale is submitted.
 */
async function addFromCatalog(p: any) {
  pushLine({ id: p.value, name: p.label, sku: p.sku, barcode: p.barcode, selling_price: p.price, vat_rate: p.vat_rate });
  const full = await fetchProduct(p.value);
  if (full) {
    const line = cart.value.find((c) => c.product_id === full.id);
    if (line) line.available = full.available;
    checkStock(full);
  }
}

async function fetchProduct(key: string | number) {
  if (!key) return null;

  // `lookupProduct` tries the live endpoint when the browser believes it has a network and falls back
  // to the cached snapshot otherwise — and, importantly, also when the live call *fails*, which is
  // the case a captive-portal router produces: "online" according to the OS, useless in practice.
  const row = await offline.lookupProduct(key);

  return row ? { id: row.id, name: row.name, sku: row.sku, barcode: row.barcode, selling_price: row.selling_price, vat_rate: row.vat_rate, available: row.available } : null;
}

async function addItem() {
  if (!searchProduct.value) return;
  adding.value = true;
  const data = await fetchProduct(searchProduct.value);
  if (data) {
    pushLine(data);
    checkStock(data);
  } else {
    // Used to push {qty:1, unit_price:0} with no product_name — a priceless
    // ghost line that submitted as a real item and silently zeroed the sale.
    scanError.value = 'Could not load that product. Pick it again or scan its barcode.';
    announceError();
    adding.value = false;
    return;
  }
  searchProduct.value = null;
  scanError.value = '';
  adding.value = false;
}

async function scanBarcode() {
  const code = barcodeInput.value.trim();
  if (!code) return;
  adding.value = true;
  scanError.value = '';
  const data = await fetchProduct(code);
  if (!data) { scanError.value = `No product for barcode/SKU "${code}"`; announceError(); adding.value = false; return; }
  pushLine(data);
  checkStock(data);
  barcodeInput.value = '';
  adding.value = false;
  // The Input primitive now exposes focus(); before that this was a silent
  // no-op and the scanner typed into whatever held focus last.
  setTimeout(() => barcodeRef.value?.focus(), 50);
}

function remove(i: number) { cart.value.splice(i, 1); }
function bump(it: any, delta: number) {
  const next = Math.round(((Number(it.qty) || 0) + delta) * 100) / 100;
  it.qty = next > 0 ? next : 0.01;
  if (it.available !== undefined) checkStock(it);
}

function clearCart() {
  if (!cart.value.length) return;
  cart.value = [];
  stockWarning.value = '';
  lastScanned.value = '';
  scanError.value = '';
  tendered.value = null;
  paidNow.value = null;
}

/** Warn before the sale, not after the invoice fails to post. */
function checkStock(data: any) {
  const available = Number(data.available ?? 0);
  const line = cart.value.find((c) => c.product_id === data.id);
  const qty = Number(line?.qty ?? 0);
  stockWarning.value = qty > available
    ? `Only ${available} of "${data.name ?? data.label}" in this warehouse — the sale will oversell by ${(qty - available).toFixed(2)}.`
    : '';
}

function lineOverStock(it: any): boolean {
  return it.available !== undefined && Number(it.qty) > Number(it.available);
}

watch(cart, () => {
  if (!cart.value.some(lineOverStock)) stockWarning.value = '';
}, { deep: true });

watch(paymentMethod, (v) => {
  if (v === 'split' && (cashAmt.value === null && bankAmt.value === null)) {
    // default split: 50/50 suggestion
    cashAmt.value = Math.round(grandTotal.value / 2);
    bankAmt.value = Math.round(grandTotal.value - cashAmt.value);
  }
  if (v === 'cash' && tendered.value === null) tendered.value = Math.round(grandTotal.value * 100) / 100;
});

// Keep an "exact" cash tender following the total while the cart is being built.
watch(grandTotal, (t) => {
  if (paymentMethod.value === 'cash' && (tendered.value === null || Math.abs((Number(tendered.value) || 0) - (t + changeDue.value)) < 0.005)) {
    tendered.value = Math.round(t * 100) / 100;
  }
});

function sell() {
  // One builder for both worlds. The live POST and the queued ticket carry the same fields, because
  // the server validates them with the same rules (`PosSaleRequest::rowRules`) either way.
  const payload: any = buildSalePayload({
    warehouseId: warehouseId.value,
    customerId: customerId.value,
    customerName: selectedCustomer.value?.name,
    paymentMethod: paymentMethod.value,
    bankId: bankId.value,
    sessionId: sessionId.value ?? props.openSession?.id ?? null,
    lines: cart.value,
    cashAmount: cashAmt.value,
    bankAmount: bankAmt.value,
    paidNowCents: paidNowCents.value,
    tenderedCents: tenderedCents.value,
  });
  payload.client_ref = nextSubmissionRef();

  if (!offline.online.value) {
    void queueOfflineSale(payload);

    return;
  }

  router.post(route('sales.pos.sell'), payload, {
    // The key survives a failed submission on purpose: the till re-sends the *same* sale, and the
    // server answers with the one it already has. `onSuccess` never fires on a redirect away from this
    // page, so nothing else has to reset it — the cart watcher above covers a corrected sale.
    preserveScroll: true,
  });
}

/**
 * Ring the sale up on the device and let the queue carry it.
 *
 * The confirmation says what is true rather than what the live path says: the money has been taken,
 * the goods have been handed over, and the ledger has *not* seen either yet. Saying "completed" here
 * is how a shop ends a day with a drawer full of cash and a report that disagrees.
 */
async function queueOfflineSale(payload: any) {
  const ticket = await offline.queueSale(payload, Math.round(grandTotal.value * 100) / 100, cartLabel(cart.value, payload.customer_name));

  if (!ticket) {
    scanError.value = 'This device cannot keep an offline queue right now, and the sale was not sent. Ask an administrator to check its storage, or wait for the network.';
    announceError();
    submissionRef.value = null;

    return;
  }

  const warning = offlineStockWarning(payload, offline.catalog.value.snapshot);
  const note = `Queued on this device: ${money(ticket.expected_total)} BDT · ${ticket.label}. ${
    warning ? `${warning} ` : ''
  }It posts as soon as the connection returns — nothing else to do.`;

  // After the cart is emptied, not before: emptying it trips the watcher that clears this very note,
  // and a sale the cashier cannot see confirmation of is a sale they will ring up twice.
  clearCart();
  announceSuccess('');
  queuedNote.value = note;
}

/*
 * Both drawer posts used to be fire-and-forget: `router.post` with no `onError`, on a page that
 * renders `flash` but never `errors`. Opening a drawer without a warehouse picked is a validation
 * failure, so the request bounced back with an error bag the till threw away and the Open button
 * simply appeared to do nothing. Every refusal is surfaced now, client-side before the post and
 * server-side through `onError`.
 */
function openSessionFn() {
  drawerError.value = '';
  if (!warehouseId.value) {
    drawerError.value = 'Choose the warehouse this drawer is for first — a till cannot open a drawer for nowhere.';

    return;
  }
  router.post(route('sales.pos.sessions.open'), {
    warehouse_id: warehouseId.value,
    opening_cash: Number(cashOpening.value) || 0,
  }, {
    preserveScroll: true,
    onError: (errors) => { drawerError.value = String(Object.values(errors)[0] ?? 'The drawer could not be opened.'); },
  });
}

function closeSessionFn() {
  drawerError.value = '';
  const drawer = activeDrawer.value;
  if (!drawer) {
    drawerError.value = 'There is no open drawer to close.';

    return;
  }
  const counted = closingCount.value.trim();
  // Absent, not zero: the service then records no count and no variance, rather than a shortage
  // equal to everything the drawer took.
  const payload = counted === '' ? {} : { closing_cash: Number(counted) };
  router.post(route('sales.pos.sessions.close', drawer.id), payload, {
    preserveScroll: true,
    onError: (errors) => { drawerError.value = String(Object.values(errors)[0] ?? 'The drawer could not be closed.'); },
  });
  closingCount.value = '';
}

onMounted(() => {
  // The drawer is resolved by `activeDrawer`'s watcher, which also runs immediately.
  loadCatalog();
  // Refresh the device's catalogue snapshot every time a till loads while the network is up: the
  // cache is only worth having if it is the newest thing on the tablet.
  void offline.refreshCatalog();
  setTimeout(() => barcodeRef.value?.focus(), 400);
});
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Sales & POS' }, { title: 'POS Terminal' }]">
    <Head title="POS Terminal" />

    <!--
      Connectivity, stated before anything else on the screen, because it changes what "Sell" means:
      online it posts a sale, offline it queues one. A cashier who has not noticed the difference is
      reconciling a drawer against a ledger that has not been told about the last hour.
    -->
    <div
      v-if="offlineMode || waitingCount > 0"
      class="mb-3 flex flex-wrap items-center gap-x-3 gap-y-1.5 rounded-xl border px-3 py-2 text-xs"
      :class="offlineMode ? 'border-warning/30 bg-warning/[0.07] text-warning' : 'border-border bg-card text-muted-foreground'"
      role="status"
    >
      <span class="flex items-center gap-1.5 font-semibold">
        <CloudOff v-if="offlineMode" class="size-3.5" aria-hidden="true" />
        {{ offlineMode ? 'Offline — the till keeps working' : 'Back online' }}
      </span>
      <span v-if="offlineMode">Sales are saved on this device and post by themselves when the link returns. Stock figures are as of the last catalogue refresh.</span>
      <span v-else-if="waitingCount > 0" class="flex items-center gap-1.5">
        <RefreshCw v-if="offline.syncing.value" class="size-3.5 animate-spin" aria-hidden="true" />
        {{ waitingCount }} ticket{{ waitingCount === 1 ? '' : 's' }} waiting · ৳{{ money(offline.summary.value.pending_value) }}
        <span v-if="offline.summary.value.has_stale"> · one or more were rung up on an earlier date and will need a decision</span>
      </span>
      <a :href="route('sales.pos.offline')" class="ml-auto font-semibold underline decoration-dotted hover:opacity-80">Queue desk</a>
    </div>

    <div class="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1fr)_26rem] 2xl:grid-cols-[minmax(0,1fr)_29rem]">
      <!-- ══ Left: scan, then pick from the grid ══════════════════════════ -->
      <div class="space-y-4">
        <div class="rounded-xl border bg-card p-4 shadow-sm">
          <div class="flex flex-wrap items-start gap-3">
            <div class="min-w-0">
              <h1 class="flex items-center gap-2 text-lg font-bold tracking-tight">
                <ShoppingCart class="size-5 text-primary" aria-hidden="true" /> POS Terminal
              </h1>
              <p class="mt-0.5 text-xs text-muted-foreground">
                Scan, tap a tile, tender. Stock leaves the shelf at once; the day's takings, VAT and cost post in one journal when the drawer closes.
              </p>
            </div>
            <div class="ml-auto flex flex-wrap items-center gap-2">
              <div class="w-44"><Label class="sr-only">Warehouse</Label><SearchableSelect v-model="warehouseId" :options="warehouses" placeholder="Warehouse *" /></div>
              <div class="w-44"><Label class="sr-only">Customer</Label><SearchableSelect v-model="customerId" :options="customers" placeholder="Walk-in" clearable /></div>
            </div>
          </div>

          <div class="mt-3 flex flex-col gap-2 sm:flex-row">
            <div class="relative flex-1">
              <Scan class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
              <Input
                ref="barcodeRef" v-model="barcodeInput"
                placeholder="Scan barcode / SKU → Enter (880…)"
                class="h-11 pl-9 font-mono" aria-label="Barcode or SKU"
                @keydown.enter.prevent="scanBarcode"
              />
            </div>
            <Button class="h-11" variant="outline" @click="scanBarcode" :disabled="!barcodeInput.trim() || adding">
              <Scan class="size-4" />Scan
            </Button>
            <div class="sm:w-64"><SearchableSelect v-model="searchProduct" endpoint="/inventory/api/options/products" placeholder="Search name / SKU…" /></div>
            <Button class="h-11" @click="addItem" :disabled="!searchProduct || adding"><Plus class="size-4" />Add</Button>
          </div>

          <div aria-live="polite" role="status" class="mt-2 space-y-1.5 empty:space-y-0">
            <div v-if="lastScanned" class="flex items-start gap-1.5 rounded-lg border border-emerald-500/25 bg-emerald-500/10 px-2.5 py-1.5 text-xs text-emerald-700 dark:text-emerald-400">
              <CheckCircle class="mt-px size-3.5 shrink-0" aria-hidden="true" />{{ lastScanned }}
            </div>
            <div v-if="stockWarning" class="flex items-start gap-1.5 rounded-lg border border-amber-500/25 bg-amber-500/10 px-2.5 py-1.5 text-xs text-amber-700 dark:text-amber-400">
              <AlertCircle class="mt-px size-3.5 shrink-0" aria-hidden="true" />{{ stockWarning }}
            </div>
            <div v-if="scanError" role="alert" class="flex items-start gap-1.5 rounded-lg border border-destructive/25 bg-destructive/10 px-2.5 py-1.5 text-xs text-destructive">
              <AlertCircle class="mt-px size-3.5 shrink-0" aria-hidden="true" />{{ scanError }}
            </div>
            <div v-if="queuedNote" class="flex items-start gap-1.5 rounded-lg border border-info/25 bg-info/[0.07] px-2.5 py-1.5 text-xs text-info">
              <CloudOff class="mt-px size-3.5 shrink-0" aria-hidden="true" />{{ queuedNote }}
            </div>
          </div>
        </div>

        <!-- ── Product grid: six across on a wide till, scrollable ──────── -->
        <div class="flex min-h-0 flex-col rounded-xl border bg-card shadow-sm">
          <div class="flex flex-wrap items-center gap-2 border-b bg-muted/30 px-4 py-3">
            <Package class="size-4 text-primary" aria-hidden="true" />
            <h2 class="text-sm font-semibold">Products</h2>
            <span class="rounded-full bg-muted px-2 py-0.5 text-[11px] font-semibold text-muted-foreground">
              {{ catalog.length }} shown
            </span>
            <div class="relative ml-auto w-full sm:w-64">
              <Search class="pointer-events-none absolute left-2.5 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
              <Input v-model="catalogSearch" placeholder="Filter the grid…" class="h-9 pl-8" aria-label="Filter products" />
              <button
                v-if="catalogSearch" type="button" aria-label="Clear filter"
                class="absolute right-2 top-1/2 -translate-y-1/2 rounded p-0.5 text-muted-foreground hover:text-foreground"
                @click="catalogSearch = ''"
              ><X class="size-3.5" /></button>
            </div>
          </div>

          <div class="max-h-[34rem] min-h-[16rem] overflow-y-auto p-3 2xl:max-h-[calc(100vh-24rem)]">
            <p v-if="catalogError" class="rounded-lg border border-amber-500/25 bg-amber-500/10 px-3 py-2 text-xs text-amber-700 dark:text-amber-400">{{ catalogError }}</p>
            <div
              class="grid grid-cols-2 gap-2.5 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 2xl:grid-cols-6"
              :class="catalogLoading && 'opacity-60 transition-opacity'"
              role="group" aria-label="Product grid"
            >
              <button
                v-for="p in catalog" :key="p.value" type="button"
                class="group flex flex-col justify-between gap-2 rounded-xl border bg-card p-3 text-left transition hover:-translate-y-0.5 hover:border-primary/50 hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring active:translate-y-0"
                :aria-label="`Add ${p.label}, ${p.meta} BDT`"
                @click="addFromCatalog(p)"
              >
                <div class="min-w-0">
                  <p class="truncate text-[13px] font-semibold leading-tight">{{ p.label }}</p>
                  <p class="mt-0.5 truncate font-mono text-[11px] text-muted-foreground">{{ p.sku }}</p>
                </div>
                <div class="flex items-end justify-between gap-1">
                  <span class="font-mono text-sm font-bold tabular-nums text-primary">{{ p.meta }}</span>
                  <Plus class="size-4 shrink-0 text-muted-foreground transition group-hover:text-primary" aria-hidden="true" />
                </div>
              </button>
            </div>
            <p v-if="!catalog.length && !catalogLoading && !catalogError" class="py-10 text-center text-sm text-muted-foreground">
              No products match “{{ catalogSearch }}”.
            </p>
          </div>
        </div>

        <!-- ── Drawer ──────────────────────────────────────────────────── -->
        <div class="rounded-xl border bg-card p-4 shadow-sm">
          <div class="flex flex-wrap items-center gap-2">
            <h3 class="flex items-center gap-2 text-sm font-bold">
              <ArrowLeftRight class="size-4 text-primary" aria-hidden="true" /> Drawer
            </h3>
            <span
              v-if="activeDrawer"
              class="rounded-full bg-emerald-500/10 px-2 py-0.5 text-[11px] font-semibold text-emerald-700 dark:text-emerald-400"
            >{{ activeDrawer.session_no }} · open</span>
            <Link
              v-if="activeDrawer && canViewReports"
              :href="route('sales.pos.sessions.dayEnd', activeDrawer.id)"
              class="ml-auto text-xs font-semibold text-primary hover:underline"
            >Open Z-report</Link>
          </div>

          <p v-if="!canManageSessions" class="mt-2 text-xs leading-relaxed text-muted-foreground">
            You can sell, but opening and closing a drawer needs the <span class="font-semibold text-foreground">POS Drawers</span> permission.
            <template v-if="activeDrawer">This till is selling into {{ activeDrawer.session_no }}<template v-if="activeDrawer.warehouse"> at {{ activeDrawer.warehouse }}</template>.</template>
          </p>

          <template v-else>
            <div
              v-if="activeDrawer"
              class="mt-2 rounded-lg border border-emerald-500/25 bg-emerald-500/10 p-2.5 text-xs text-emerald-800 dark:text-emerald-300"
            >
              <p class="font-semibold">
                {{ activeDrawer.session_no }}<template v-if="activeDrawer.warehouse"> · {{ activeDrawer.warehouse }}</template>
                · float {{ activeDrawer.opening_cash }} BDT<template v-if="activeDrawer.cashier"> · opened by {{ activeDrawer.cashier }}</template>
              </p>
              <p v-if="!warehouseId" class="mt-1 opacity-85">Pick the warehouse you are selling from and the till will use that warehouse's drawer.</p>
            </div>
            <p v-else class="mt-2 text-xs text-muted-foreground">No drawer is open. Sales still ring up, but they will not belong to a day-end until one is.</p>

            <div class="mt-3 grid gap-3 sm:grid-cols-2">
              <div class="space-y-1">
                <Label class="text-xs" for="pos-opening-cash">Open a drawer</Label>
                <div class="flex gap-2">
                  <Input id="pos-opening-cash" v-model.number="cashOpening" type="number" min="0" step="0.01" placeholder="Float" aria-label="Opening cash" />
                  <Button size="sm" :disabled="!warehouseId" @click="openSessionFn">Open</Button>
                </div>
                <p class="text-[11px] text-muted-foreground">{{ warehouseId ? 'Opens against the warehouse selected above.' : 'Select a warehouse first.' }}</p>
              </div>
              <div class="space-y-1">
                <Label class="text-xs" for="pos-closing-cash">Close the drawer</Label>
                <div class="flex gap-2">
                  <Input
                    id="pos-closing-cash" v-model="closingCount" type="number" min="0" step="0.01"
                    placeholder="Counted cash" aria-label="Counted closing cash"
                  />
                  <Button size="sm" variant="outline" :disabled="!activeDrawer" @click="closeSessionFn">Close</Button>
                </div>
                <p class="text-[11px] text-muted-foreground">
                  Leave blank to close without a count — then no variance is recorded. Closing posts the day and lands on its Z-report.
                </p>
              </div>
            </div>
          </template>

          <p
            v-if="drawerError" role="alert"
            class="mt-2 flex items-start gap-1.5 rounded-lg border border-destructive/25 bg-destructive/10 px-2.5 py-1.5 text-xs font-medium text-destructive"
          ><AlertCircle class="mt-px size-3.5 shrink-0" aria-hidden="true" />{{ drawerError }}</p>
        </div>
      </div>

      <!-- ══ Right: the till ═════════════════════════════════════════════ -->
      <div class="space-y-3 xl:sticky xl:top-3 xl:self-start">
        <div class="flex min-h-0 flex-col rounded-xl border bg-card shadow-sm">
          <div class="flex items-center gap-2 border-b bg-muted/30 px-4 py-3">
            <h2 class="text-sm font-semibold">Current sale</h2>
            <span class="rounded-full bg-muted px-2 py-0.5 text-[11px] font-semibold text-muted-foreground">
              {{ cart.length }} line{{ cart.length === 1 ? '' : 's' }} · {{ money(itemCount) }} unit{{ itemCount === 1 ? '' : 's' }}
            </span>
            <Button v-if="cart.length" variant="ghost" size="sm" class="ml-auto h-7 text-xs text-muted-foreground hover:text-destructive" @click="clearCart">
              <Trash2 class="size-3" />Clear
            </Button>
          </div>

          <div class="max-h-[22rem] min-h-[7rem] overflow-y-auto p-3">
            <div v-if="!cart.length" class="grid place-items-center rounded-xl border-2 border-dashed py-9 text-center">
              <ShoppingCart class="size-7 text-muted-foreground/50" aria-hidden="true" />
              <p class="mt-2 text-sm font-medium text-muted-foreground">No items yet</p>
              <p class="mt-0.5 max-w-[16rem] text-xs text-muted-foreground/80">Scan a barcode or tap a product tile — totals and change update live.</p>
            </div>

            <!-- Keyed by product, not by index: splicing a row with an index key
                 re-used the DOM node and left the next line showing stale input. -->
            <div
              v-for="(it, i) in cart" :key="it.product_id ?? i"
              class="mb-2 rounded-xl border p-2.5 transition-colors last:mb-0"
              :class="lineOverStock(it) ? 'border-amber-500/40 bg-amber-500/5' : 'bg-muted/10 hover:border-primary/30'"
            >
              <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                  <p class="truncate text-[13px] font-semibold">{{ it.product_name || `Product #${it.product_id}` }}</p>
                  <p class="truncate font-mono text-[11px] text-muted-foreground">
                    {{ it.sku || '' }}<span v-if="it.barcode"> · {{ it.barcode }}</span>
                    <span v-if="it.available !== undefined" :class="lineOverStock(it) ? 'font-semibold text-amber-700 dark:text-amber-400' : ''"> · avl {{ it.available }}</span>
                  </p>
                </div>
                <div class="shrink-0 text-right">
                  <p class="font-mono text-sm font-bold tabular-nums">{{ money(lineTotal(it)) }}</p>
                  <button
                    type="button" class="mt-0.5 rounded p-0.5 text-muted-foreground transition hover:bg-destructive/10 hover:text-destructive"
                    :aria-label="`Remove ${it.product_name || 'line ' + (i + 1)}`" @click="remove(i)"
                  ><Trash2 class="size-3.5" /></button>
                </div>
              </div>
              <div class="mt-2 grid grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-1.5">
                <div class="flex items-center rounded-lg border bg-card">
                  <button type="button" class="grid size-7 place-items-center rounded-l-lg text-muted-foreground transition hover:bg-muted hover:text-foreground" :aria-label="`Decrease quantity of ${it.product_name || 'line'}`" @click="bump(it, -1)"><Minus class="size-3.5" /></button>
                  <Input v-model.number="it.qty" type="number" step="0.01" min="0.01" class="h-7 w-14 border-0 text-center font-mono shadow-none focus-visible:ring-0" :aria-label="`Quantity of ${it.product_name || 'line'}`" />
                  <button type="button" class="grid size-7 place-items-center rounded-r-lg text-muted-foreground transition hover:bg-muted hover:text-foreground" :aria-label="`Increase quantity of ${it.product_name || 'line'}`" @click="bump(it, 1)"><Plus class="size-3.5" /></button>
                </div>
                <Input v-model.number="it.unit_price" type="number" step="0.01" class="h-7 font-mono text-right" aria-label="Unit price" />
                <div class="flex items-center gap-1">
                  <Input v-model.number="it.discount_pct" type="number" step="0.01" min="0" max="100" class="h-7 w-14 font-mono text-right" aria-label="Discount percent" />
                  <Input v-model.number="it.vat_rate" type="number" step="0.01" min="0" max="100" class="h-7 w-14 font-mono text-right" aria-label="VAT percent" />
                </div>
              </div>
            </div>
          </div>

          <div class="space-y-1.5 border-t bg-muted/20 px-4 py-3 text-sm">
            <div class="flex justify-between"><span class="text-muted-foreground">Subtotal</span><span class="font-mono tabular-nums">{{ money(subtotal) }}</span></div>
            <div class="flex justify-between">
              <span class="flex items-center gap-1 text-muted-foreground"><Percent class="size-3" aria-hidden="true" /> Discount</span>
              <span class="font-mono tabular-nums text-emerald-700 dark:text-emerald-400">−{{ money(discountTotal) }}</span>
            </div>
            <div class="flex justify-between">
              <span class="flex items-center gap-1 text-muted-foreground"><Coins class="size-3" aria-hidden="true" /> VAT (2110)</span>
              <span class="font-mono tabular-nums text-amber-700 dark:text-amber-400">+{{ money(vatTotal) }}</span>
            </div>
          </div>
        </div>

        <!-- ── Tender ─────────────────────────────────────────────────── -->
        <div class="rounded-xl border bg-card p-4 shadow-sm">
          <div class="grid grid-cols-3 gap-1.5" role="group" aria-label="Payment method">
            <button
              v-for="m in METHODS" :key="m.id" type="button"
              class="flex flex-col items-center gap-1 rounded-xl border px-2 py-2 text-[11px] font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              :class="paymentMethod === m.id ? 'border-primary bg-primary text-primary-foreground shadow-sm' : 'bg-card text-muted-foreground hover:border-primary/40 hover:text-foreground'"
              :aria-pressed="paymentMethod === m.id" @click="setPayment(m.id)"
            >
              <component :is="m.icon" class="size-4" aria-hidden="true" />{{ m.label }}
            </button>
          </div>

          <div v-if="needsBank" class="mt-3">
            <Label class="text-xs">Bank / Wallet</Label>
            <SearchableSelect v-model="bankId" :options="banks" placeholder="Select bank — optional for bKash" clearable />
          </div>

          <!-- split -->
          <div v-if="paymentMethod === 'split'" class="mt-3 grid grid-cols-2 gap-2 rounded-lg border border-amber-500/20 bg-amber-500/10 p-3">
            <div class="space-y-1"><Label class="text-xs">Cash amount</Label><Input v-model.number="cashAmt" type="number" step="0.01" placeholder="0.00" class="font-mono" /></div>
            <div class="space-y-1"><Label class="text-xs">Bank amount</Label><Input v-model.number="bankAmt" type="number" step="0.01" placeholder="0.00" class="font-mono" /></div>
            <p v-if="!splitValid" class="col-span-2 flex items-center gap-1 text-xs text-amber-700 dark:text-amber-400">
              <AlertCircle class="size-3" aria-hidden="true" /> Cash + Bank ({{ money((Number(cashAmt)||0)+(Number(bankAmt)||0)) }}) must equal {{ money(grandTotal) }} BDT
            </p>
            <p v-else class="col-span-2 flex items-center gap-1 text-xs text-emerald-700 dark:text-emerald-400"><CheckCircle class="size-3" aria-hidden="true" /> Split balanced</p>
          </div>

          <!-- cash tender + change -->
          <div v-if="paymentMethod !== 'due' && paymentMethod !== 'split'" class="mt-3 space-y-2">
            <Label class="text-xs" for="tendered">Amount received</Label>
            <Input id="tendered" v-model.number="tendered" type="number" step="0.01" min="0" class="h-11 font-mono text-lg font-bold tabular-nums" placeholder="0.00" />
            <div class="flex flex-wrap gap-1.5">
              <Button type="button" variant="outline" size="sm" class="h-8 font-mono" @click="setTendered(Math.round(grandTotal * 100) / 100)">Exact {{ money(grandTotal) }}</Button>
              <Button v-for="n in quickCash" :key="n" type="button" variant="outline" size="sm" class="h-8 font-mono" @click="setTendered(n)">{{ n }}</Button>
            </div>

            <div
              v-if="changeDue > 0"
              class="flex items-center justify-between gap-2 rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-3 py-2.5"
              role="status"
            >
              <span class="flex items-center gap-1.5 text-xs font-semibold text-emerald-800 dark:text-emerald-300">
                <Banknote class="size-4" aria-hidden="true" /> Give back as change
              </span>
              <span class="font-mono text-xl font-bold tabular-nums text-emerald-800 dark:text-emerald-300">{{ money(changeDue) }}</span>
            </div>
            <p v-else-if="tenderIncomplete" class="flex items-start gap-1.5 rounded-xl border border-destructive/30 bg-destructive/10 px-3 py-2 text-xs font-medium text-destructive">
              <AlertCircle class="mt-px size-3.5 shrink-0" aria-hidden="true" />
              Short by {{ money(tenderShort) }} BDT. Take the rest, or switch to Due / Part paid and record what was paid.
            </p>
          </div>

          <!-- part payment -->
          <div v-if="paymentMethod === 'due'" class="mt-3 space-y-2 rounded-lg border border-violet-500/25 bg-violet-500/10 p-3">
            <p class="flex items-center gap-1.5 text-xs font-bold text-violet-800 dark:text-violet-300">
              <Wallet class="size-3.5" aria-hidden="true" /> Due — take a part payment or none
            </p>
            <p v-if="dueNeedsCustomer" class="flex items-start gap-1.5 text-xs text-amber-700 dark:text-amber-400">
              <AlertCircle class="mt-px size-3.5 shrink-0" aria-hidden="true" /> Choose the customer who owes it — a credit sale cannot be owed by “Walk-in”.
            </p>
            <div class="space-y-1">
              <Label class="text-xs" for="paidnow">Received now</Label>
              <Input id="paidnow" v-model.number="paidNow" type="number" step="0.01" min="0" class="h-10 bg-card font-mono text-base font-bold tabular-nums" placeholder="0.00" />
            </div>
            <div class="flex flex-wrap gap-1.5">
              <Button type="button" variant="outline" size="sm" class="h-8 bg-card font-mono" @click="payNothing">Nothing now</Button>
              <Button type="button" variant="outline" size="sm" class="h-8 bg-card font-mono" @click="payHalf">Half — {{ money(grandTotal / 2) }}</Button>
              <Button type="button" variant="outline" size="sm" class="h-8 bg-card font-mono" @click="payAll">Settle in full</Button>
            </div>
            <div class="flex items-center justify-between gap-2 border-t border-violet-500/25 pt-2 text-xs">
              <span class="font-semibold text-violet-800 dark:text-violet-300">Balance due</span>
              <span class="font-mono text-lg font-bold tabular-nums text-violet-800 dark:text-violet-300">{{ money(balanceDue) }}</span>
            </div>
            <p class="text-[11px] leading-relaxed text-violet-800/85 dark:text-violet-300/85">
              <template v-if="isPartPaid">{{ money(paidNowCents / 100) }} BDT is taken now and {{ money(balanceDue) }} BDT posts to Accounts Receivable (1100) against {{ selectedCustomer?.name ?? 'the customer' }} when this drawer closes.</template>
              <template v-else>No money is taken now. The full {{ money(grandTotal) }} BDT posts to Accounts Receivable (1100) when this drawer closes, and can be collected at any till afterwards.</template>
            </p>
          </div>
        </div>

        <!-- ── Total + charge ─────────────────────────────────────────── -->
        <div class="overflow-hidden rounded-xl border border-primary/20 bg-primary text-primary-foreground shadow-lg">
          <div class="px-4 pt-4">
            <p class="text-[11px] font-semibold uppercase tracking-widest opacity-80">Total to pay</p>
            <p class="mt-0.5 font-mono text-4xl font-bold leading-none tabular-nums">{{ money(grandTotal) }}<span class="ml-1.5 text-sm font-semibold opacity-80">BDT</span></p>
            <p class="mt-1.5 text-xs opacity-85">
              {{ cart.length }} line{{ cart.length === 1 ? '' : 's' }} · {{ money(itemCount) }} unit{{ itemCount === 1 ? '' : 's' }}
              <span v-if="changeDue > 0"> · change {{ money(changeDue) }}</span>
              <span v-else-if="isPartPaid"> · {{ money(balanceDue) }} due</span>
            </p>
          </div>
          <div class="p-4 pt-3">
            <Button
              class="h-12 w-full bg-primary-foreground text-base font-bold text-primary shadow-sm hover:bg-primary-foreground/90"
              size="lg" @click="sell" :disabled="!canSell || adding"
            >
              <template v-if="paymentMethod === 'cash'"><Banknote class="size-5" />{{ changeDue > 0 ? `Take ${money(tenderedCents / 100)} · change ${money(changeDue)}` : 'Charge cash' }}</template>
              <template v-else-if="paymentMethod === 'bank'"><CreditCard class="size-5" />Charge to bank</template>
              <template v-else-if="paymentMethod === 'bkash'"><Smartphone class="size-5" />Charge bKash</template>
              <template v-else-if="paymentMethod === 'card'"><CreditCard class="size-5" />Charge card</template>
              <template v-else-if="paymentMethod === 'due'"><Wallet class="size-5" />{{ isPartPaid ? `Take ${money(paidNowCents / 100)} · ${money(balanceDue)} due` : 'Sell on credit' }}</template>
              <template v-else><Split class="size-5" />Charge split</template>
            </Button>

            <p v-if="!warehouseId" class="mt-2 text-xs font-medium opacity-90">Select a warehouse to enable selling.</p>
            <p v-else-if="!cart.length" class="mt-2 text-xs font-medium opacity-90">Add at least one item to enable selling.</p>
            <p v-else-if="paymentMethod === 'split' && !splitValid" class="mt-2 text-xs font-medium opacity-90">Cash + Bank must equal the total.</p>
            <p v-else-if="dueNeedsCustomer" class="mt-2 text-xs font-medium opacity-90">A credit or part-paid sale needs a customer.</p>
            <p v-else-if="tenderIncomplete" class="mt-2 text-xs font-medium opacity-90">The amount received is short of the total.</p>
            <p v-else class="mt-2 text-xs opacity-80">Stock deducted now. Nothing posts yet — the whole day goes to one journal when this drawer closes (Sales 4001 · VAT 2110 · COGS 5001 · Inventory 1200).</p>

            <div v-if="page.props?.flash?.error" role="alert" class="mt-2 rounded-lg border border-destructive/30 bg-destructive/15 p-2 text-xs font-medium">{{ page.props.flash.error }}</div>
            <div v-if="page.props?.flash?.success" class="mt-2 rounded-lg bg-primary-foreground/15 p-2 text-xs font-medium">{{ page.props.flash.success }}</div>
          </div>
        </div>
      </div>
    </div>
  </AdminLayout>
</template>
