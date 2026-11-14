<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { AlertTriangle, CheckCircle2, CloudOff, Download, Printer, RefreshCw, RotateCcw, Smartphone, Trash2, Wifi } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import { confirmDialog } from '@core/Composables/useConfirm';
import { printDocument } from '@core/Composables/usePrint';
import { useLocale } from '@core/Composables/useLocale';
import { formatCurrency } from '@core/Utils/format';
import { cartTotalCents, type CartLine } from '@sales/offline/payload';
import { useOfflineTill } from '@sales/offline/useOfflineTill';
import type { QueuedTicket } from '@sales/offline/queue';

const props = defineProps<{
  /** What the ledger has accepted from *any* offline queue this month, newest first. */
  posted: Array<{
    id: number;
    pos_no: string;
    client_ref: string;
    sale_date: string;
    rang_up_at: string | null;
    customer_name: string;
    total_amount: string;
    payment_method: string;
    warehouse: string | null;
    session: string | null;
    session_status: string | null;
    status: string;
  }>;
  postedToday: number;
  maxBatch: number;
  canSell: boolean;
}>();

const { t: tr } = useLocale();

const till = useOfflineTill({
  syncUrl: route('sales.pos.offline.sync'),
  catalogUrl: route('inventory.api.pos-catalog'),
  maxBatch: props.maxBatch,
});

const selected = ref<QueuedTicket | null>(null);
const lastOutcome = ref('');

const waiting = computed(() => till.tickets.value.filter((ticket) => ticket.status !== 'posted'));
const postedLocally = computed(() => till.tickets.value.filter((ticket) => ticket.status === 'posted'));
const attention = computed(() => till.tickets.value.filter((ticket) => ticket.status === 'attention'));

const engineLabel = computed(() => ({
  indexeddb: tr('pos.offlineEngineIdb'),
  localstorage: tr('pos.offlineEngineLocal'),
  memory: tr('pos.offlineEngineMemory'),
  none: tr('pos.offlineEngineNone'),
}[till.engine.value] ?? till.engine.value));

/** The gap the ledger date cannot show: when the money actually changed hands. */
function gapMinutes(sale: { sale_date: string; rang_up_at: string | null }): string {
  if (!sale.rang_up_at) return '';

  const minutes = Math.round((Date.parse(`${sale.sale_date}T00:00:00`) - Date.parse(sale.rang_up_at)) / 60_000);

  if (!Number.isFinite(minutes) || minutes < 1) return '';
  if (minutes < 60) return tr('pos.offlineLateMinutes').replace(':min', String(minutes));

  return tr('pos.offlineLateHours').replace(':hours', String(Math.round((minutes / 60) * 10) / 10));
}

function ticketTotalCents(ticket: QueuedTicket | null): number {
  if (!ticket) return 0;

  return cartTotalCents((ticket.payload.items ?? []) as unknown as CartLine[]);
}

const slip = computed(() => {
  const ticket = selected.value;

  if (!ticket) return null;

  const items = (ticket.payload.items ?? []) as unknown as Array<CartLine & { product_name?: string; sku?: string }>;
  const lines = items.map((line) => ({
    name: line.product_name ?? `#${line.product_id}`,
    qty: Number(line.qty) || 0,
    unit_price: Number(line.unit_price) || 0,
    total: cartTotalCents([line]) / 100,
  }));

  return {
    ticket,
    lines,
    total: ticketTotalCents(ticket) / 100,
    customer: (ticket.payload.customer_name as string) || 'Walk-in Customer',
    method: (ticket.payload.payment_method as string) || 'cash',
  };
});

function choose(ticket: QueuedTicket) {
  selected.value = ticket;
}

/**
 * Only the slip is printed, and it is printed from the device — there is no server page to send the
 * cashier to while the network is down, which is exactly when a slip is needed most.
 */
function printSlip() {
  printDocument({ portrait: true });
}

async function sendQueue() {
  const outcome = await till.flushNow();

  if (!outcome) {
    lastOutcome.value = tr('pos.offlineNoNetwork');

    return;
  }

  lastOutcome.value = outcome.transport_failed
    ? tr('pos.offlineSendFailed')
    : tr('pos.offlineSendDone')
        .replace(':posted', String(outcome.posted))
        .replace(':attention', String(outcome.attention));

  if (outcome.posted > 0 && selected.value?.status === 'posted') selected.value = null;
}

async function retryTicket(ticket: QueuedTicket, acknowledgeStaleDate = false) {
  await till.retryTicket(ticket.client_ref, acknowledgeStaleDate);
  const outcome = await till.flushNow(true);

  lastOutcome.value = outcome?.transport_failed ? tr('pos.offlineSendFailed') : tr('pos.offlineSendDone').replace(':posted', String(outcome?.posted ?? 0)).replace(':attention', String(outcome?.attention ?? 0));
}

async function discardTicket(ticket: QueuedTicket) {
  const yes = await confirmDialog({
    title: tr('pos.offlineDiscardTitle'),
    description: tr('pos.offlineDiscardBody'),
    confirmLabel: tr('pos.offlineDiscard'),
    danger: true,
  });

  if (!yes) return;

  await till.discardTicket(ticket.client_ref);

  if (selected.value?.client_ref === ticket.client_ref) selected.value = null;
}

function stamp(iso: string | null): string {
  if (!iso) return '—';

  return new Date(iso).toLocaleString('en-BD', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
}

/* Install (U21). The prompt event is fire-and-forget, so it is captured in a ref and offered until used. */
const installEvent = ref<Event | null>(null);
const standalone = ref(false);

function captureInstall(event: Event) {
  event.preventDefault();
  installEvent.value = event;
}

async function installNow() {
  // Named rather than `prompt`: a local `prompt` shadows `window.prompt` for the whole file, which is
  // the one thing `NativeDialogTest` forbids outright.
  const install = installEvent.value as (Event & { prompt?: () => Promise<void> }) | null;

  if (!install?.prompt) return;

  await install.prompt();
  installEvent.value = null;
}

onMounted(() => {
  window.addEventListener('beforeinstallprompt', captureInstall);
  standalone.value = window.matchMedia?.('(display-mode: standalone)').matches ?? false;

  if (till.tickets.value.length) selected.value = waiting.value[0] ?? till.tickets.value[0];
});
onBeforeUnmount(() => window.removeEventListener('beforeinstallprompt', captureInstall));
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Sales & POS' }, { title: tr('Offline POS Queue') }]">
    <Head :title="tr('Offline POS Queue')" />

    <PageHeader
      :title="tr('Offline POS Queue')"
      :description="tr('pos.offlineIntro')"
      :icon="CloudOff"
      :stats="[
        { label: tr('pos.offlineWaiting'), value: String(waiting.length) },
        { label: tr('pos.offlineNeedsAttention'), value: String(attention.length) },
        { label: tr('pos.offlinePostedToday'), value: String(postedToday) },
        { label: tr('pos.offlineMoneyWaiting'), value: formatCurrency(till.summary.value.pending_value) },
      ]"
    >
      <template #actions>
        <div class="flex flex-wrap items-center gap-2">
          <Link :href="route('sales.pos.terminal')"><Button variant="outline" size="sm" class="rounded-full">{{ tr('pos.offlineBackToTill') }}</Button></Link>
          <Button size="sm" class="rounded-full" :disabled="!till.ready.value || till.syncing.value || waiting.length === 0" @click="sendQueue">
            <RefreshCw class="size-4" :class="{ 'animate-spin': till.syncing.value }" /> {{ tr('pos.offlineSendNow') }}
          </Button>
        </div>
      </template>
    </PageHeader>

    <!-- Connectivity and storage. Both are stated because both can be wrong in ways the cashier cannot
         see: a browser that reports "online" over a dead router, and a private window where tickets
         live only as long as the tab does. -->
    <div class="mb-4 grid gap-3 lg:grid-cols-3">
      <div class="rounded-xl border bg-card p-4">
        <p class="flex items-center gap-2 text-sm font-semibold">
          <component :is="till.online.value ? Wifi : CloudOff" class="size-4" :class="till.online.value ? 'text-success' : 'text-warning'" />
          {{ till.online.value ? tr('pos.offlineOnline') : tr('pos.offlineOffline') }}
        </p>
        <p class="mt-1 text-xs text-muted-foreground">{{ tr('pos.offlineOnlineNote') }}</p>
      </div>
      <div class="rounded-xl border bg-card p-4">
        <p class="text-sm font-semibold">{{ tr('pos.offlineEngine') }}</p>
        <p class="mt-1 text-xs text-muted-foreground">{{ engineLabel }}</p>
      </div>
      <div class="rounded-xl border bg-card p-4">
        <p class="text-sm font-semibold">{{ tr('pos.offlineCatalog') }}</p>
        <p v-if="till.catalog.value.snapshot" class="mt-1 text-xs text-muted-foreground">
          {{ till.catalog.value.snapshot.items.length }} / {{ till.catalog.value.snapshot.total }}
          · {{ tr('pos.offlineCatalogAge').replace(':min', String(till.catalog.value.age_minutes ?? 0)) }}
          <span v-if="till.catalog.value.partial" class="ml-1 inline-flex items-center gap-1 rounded-full bg-warning/10 px-1.5 py-0.5 text-[10px] font-medium text-warning ring-1 ring-warning/25">{{ tr('pos.offlineCatalogPartial') }}</span>
          <span v-if="till.catalog.value.mismatched" class="ml-1 inline-flex items-center gap-1 rounded-full bg-warning/10 px-1.5 py-0.5 text-[10px] font-medium text-warning ring-1 ring-warning/25">{{ tr('pos.offlineCatalogOtherTill') }}</span>
        </p>
        <p v-else class="mt-1 text-xs text-muted-foreground">{{ tr('pos.offlineCatalogEmpty') }}</p>
        <Button size="sm" variant="outline" class="mt-2 rounded-full" @click="till.refreshCatalog()"><Download class="size-3.5" />{{ tr('pos.offlineCatalogRefresh') }}</Button>
      </div>
    </div>

    <div v-if="till.summary.value.has_stale" class="mb-4 flex items-start gap-2 rounded-xl border border-warning/25 bg-warning/[0.06] p-4 text-sm">
      <AlertTriangle class="mt-0.5 size-4 shrink-0 text-warning" />
      <p>{{ tr('pos.offlineStaleWarning') }}</p>
    </div>

    <div v-if="lastOutcome || till.syncError.value" class="mb-4 rounded-xl border bg-background p-3 text-sm">
      {{ till.syncError.value || lastOutcome }}
    </div>

    <div class="grid gap-4 lg:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)]">
      <div class="space-y-3">
        <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">{{ tr('pos.offlineQueue') }}</p>

        <div v-if="waiting.length === 0 && postedLocally.length === 0" class="rounded-xl border border-dashed p-8 text-center">
          <CheckCircle2 class="mx-auto size-6 text-muted-foreground" />
          <p class="mt-2 text-sm font-medium">{{ tr('pos.offlineEmpty') }}</p>
          <p class="mt-1 text-xs text-muted-foreground">{{ tr('pos.offlineEmptyNote') }}</p>
        </div>

        <button
          v-for="ticket in [...waiting, ...postedLocally]"
          :key="ticket.client_ref"
          type="button"
          class="block w-full rounded-xl border bg-card p-3 text-left transition hover:bg-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
          :class="{ 'ring-1 ring-ring': selected?.client_ref === ticket.client_ref }"
          @click="choose(ticket)"
        >
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <p class="truncate text-sm font-semibold">{{ ticket.label }}</p>
              <p class="mt-0.5 text-xs text-muted-foreground">
                {{ stamp(ticket.created_at) }}
                <template v-if="ticket.status === 'posted'"> · <span class="font-mono text-success">{{ ticket.pos_no }}</span></template>
                <template v-if="ticket.attempts > 0 && ticket.status !== 'posted'"> · {{ tr('pos.offlineAttempts').replace(':n', String(ticket.attempts)) }}</template>
              </p>
            </div>
            <div class="shrink-0 text-right">
              <p class="text-sm font-semibold tabular-nums">{{ formatCurrency(Number(ticket.expected_total) || 0) }}</p>
              <span
                class="mt-1 inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-medium ring-1"
                :class="{
                  'bg-muted text-muted-foreground ring-border': ticket.status === 'queued',
                  'bg-info/10 text-info ring-info/25': ticket.status === 'in_flight',
                  'bg-warning/10 text-warning ring-warning/25': ticket.status === 'attention',
                  'bg-success/10 text-success ring-success/25': ticket.status === 'posted',
                }"
              >
                {{ tr(`pos.offlineStatus.${ticket.status}`) }}
              </span>
            </div>
          </div>
          <p v-if="ticket.last_error" class="mt-2 rounded-lg bg-destructive/5 p-2 text-xs text-destructive-foreground ring-1 ring-destructive/20">{{ ticket.last_error }}</p>
          <div v-if="ticket.status !== 'posted'" class="mt-2 flex flex-wrap gap-2 print:hidden">
            <Button size="sm" variant="outline" class="h-7 rounded-full text-xs" @click.stop="retryTicket(ticket)"><RotateCcw class="size-3.5" />{{ tr('pos.offlineTryAgain') }}</Button>
            <Button v-if="ticket.last_error?.toLowerCase().includes('not today')" size="sm" variant="outline" class="h-7 rounded-full text-xs" @click.stop="retryTicket(ticket, true)">{{ tr('pos.offlinePostToToday') }}</Button>
            <Button size="sm" variant="ghost" class="h-7 rounded-full text-xs text-destructive" @click.stop="discardTicket(ticket)"><Trash2 class="size-3.5" />{{ tr('pos.offlineDiscard') }}</Button>
          </div>
          <p v-else-if="canSell" class="mt-2 text-xs text-muted-foreground print:hidden">
            <Link :href="route('sales.pos.receipt', ticket.sale_id)" class="underline">{{ tr('pos.offlineOpenReceipt') }}</Link>
          </p>
        </button>

        <p v-if="waiting.length > maxBatch" class="text-xs text-muted-foreground">{{ tr('pos.offlineBatchNote').replace(':max', String(maxBatch)) }}</p>
      </div>

      <div class="space-y-4">
        <!-- THE print region: the slip alone, never the queue around it. -->
        <div v-if="slip" class="rounded-xl border bg-card p-4">
          <div class="mb-3 flex items-center justify-between print:hidden">
            <p class="text-sm font-semibold">{{ tr('pos.offlineSlip') }}</p>
            <Button size="sm" variant="outline" class="rounded-full" @click="printSlip"><Printer class="size-4" />{{ tr('pos.offlinePrintSlip') }}</Button>
          </div>
          <div class="rounded-lg border bg-background p-4 font-mono text-xs print:border-none print:bg-transparent" data-core-print>
            <div class="border-b pb-2 text-center">
              <p class="text-sm font-bold tracking-wide">{{ tr('pos.offlineSlipShop') }}</p>
              <p class="mt-0.5 text-[10px] text-muted-foreground">{{ slip.customer }} · {{ stamp(slip.ticket.created_at) }}</p>
              <p v-if="slip.ticket.status === 'posted'" class="mt-0.5 text-[10px] font-semibold text-success">{{ tr('pos.offlineSlipLedger').replace(':no', slip.ticket.pos_no ?? '') }}</p>
              <p v-else class="mt-1 rounded bg-warning/10 px-1 py-0.5 text-[10px] font-semibold text-warning">{{ tr('pos.offlineSlipPending') }}</p>
            </div>
            <table class="mt-2 w-full">
              <tbody>
                <tr v-for="(line, i) in slip.lines" :key="i" class="border-b border-dashed align-top">
                  <td class="py-1 pr-1">{{ line.name }}</td>
                  <td class="py-1 text-right whitespace-nowrap">{{ line.qty }} × {{ formatCurrency(line.unit_price) }}</td>
                  <td class="py-1 pl-2 text-right font-semibold tabular-nums">{{ formatCurrency(line.total) }}</td>
                </tr>
              </tbody>
            </table>
            <div class="mt-2 flex justify-between border-t pt-2 text-sm font-bold tabular-nums">
              <span>{{ tr('pos.offlineSlipTotal') }}</span>
              <span>{{ formatCurrency(slip.total) }}</span>
            </div>
            <p class="mt-2 text-center text-[10px] text-muted-foreground">{{ tr('pos.offlineSlipFooter') }}</p>
          </div>
        </div>

        <div class="rounded-xl border bg-card p-4">
          <p class="text-sm font-semibold">{{ tr('pos.offlineLedgerHeading') }}</p>
          <p class="mt-1 text-xs text-muted-foreground">{{ tr('pos.offlineLedgerIntro') }}</p>
          <table class="mt-3 w-full text-xs">
            <thead>
              <tr class="border-b text-left text-muted-foreground">
                <th class="py-1 font-medium">{{ tr('pos.offlineColSale') }}</th>
                <th class="py-1 font-medium">{{ tr('pos.offlineColRung') }}</th>
                <th class="py-1 text-right font-medium">{{ tr('pos.offlineColAmount') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in props.posted.slice(0, 20)" :key="row.client_ref" class="border-b border-dashed last:border-0">
                <td class="py-1.5">
                  <Link :href="route('sales.pos.show', row.id)" class="font-mono underline decoration-dotted">{{ row.pos_no }}</Link>
                  <p class="text-[10px] text-muted-foreground">{{ row.warehouse ?? '—' }} · {{ row.session ?? tr('pos.offlineNoDrawer') }} {{ row.session_status ? `(${row.session_status})` : '' }}</p>
                </td>
                <td class="py-1.5 text-muted-foreground">
                  {{ stamp(row.rang_up_at) }}
                  <p v-if="gapMinutes(row)" class="text-[10px] text-warning">{{ gapMinutes(row) }}</p>
                </td>
                <td class="py-1.5 text-right font-semibold tabular-nums">{{ formatCurrency(Number(row.total_amount)) }}</td>
              </tr>
              <tr v-if="props.posted.length === 0">
                <td colspan="3" class="py-3 text-center text-muted-foreground">{{ tr('pos.offlineLedgerEmpty') }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div v-if="!standalone" class="rounded-xl border bg-card p-4">
          <p class="flex items-center gap-2 text-sm font-semibold"><Smartphone class="size-4" />{{ tr('pos.offlineInstall') }}</p>
          <p class="mt-1 text-xs text-muted-foreground">{{ tr('pos.offlineInstallNote') }}</p>
          <Button v-if="installEvent" size="sm" class="mt-2 rounded-full" @click="installNow">{{ tr('pos.offlineInstallButton') }}</Button>
          <p v-else class="mt-2 text-xs text-muted-foreground">{{ tr('pos.offlineInstallManual') }}</p>
        </div>
      </div>
    </div>
  </AdminLayout>
</template>
