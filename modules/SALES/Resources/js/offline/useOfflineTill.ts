/**
 * F22 — the browser half: connectivity, the cached catalogue, and when to push the queue.
 *
 * `queue.ts` holds the policy; this file holds the parts that are only true in a real browser:
 *
 *  - **`navigator.onLine` is a fact, but not a promise.** It says "one of the interfaces is up", so a
 *    till on a dead Wi-Fi with a live Ethernet-looking state will be told it is online. That is why
 *    every path here degrades to "the send failed, keep the ticket" rather than trusting the flag, and
 *    why the flag is used to *suppress* pointless traffic, never to decide whether a sale is safe to
 *    queue.
 *  - **`offline` is an event, `online` is a hope.** A browser knows the moment a link drops; it only
 *    knows about a return when the stack says so, which on captive-portal networks (a shop's guest
 *    router after a reboot) can mean "never". Hence the three triggers below, not one.
 *  - **A page load is a recovery point.** `visibilitychange` fires when a tablet wakes from sleep in a
 *    van; that is a plausible moment for a queue to be ninety minutes old, and it is when a cashier
 *    wants the ledger to have it.
 *
 * It never throws and never blocks a sale: if storage, IndexedDB or the timer is unavailable the till
 * still queues to whatever it has and says which engine is in play.
 */

import { onBeforeUnmount, onMounted, ref, shallowRef } from 'vue';

import {
  createQueue,
  summarise,
  writePendingSignal,
  type FlushOutcome,
  type QueueSummary,
  type QueuedTicket,
  type SalePayload,
  type SyncResponse,
  type SyncResult,
} from './queue';
import { createStore, type CreatedStore } from './store';
import { describeCatalog, findInCatalog, loadCatalog, saveCatalog, searchCatalog, type CatalogRow, type CatalogState } from './catalog';

export interface OfflineTillOptions {
  /** `route('sales.pos.offline.sync')`, resolved by the page so this file stays route-agnostic. */
  syncUrl: string;
  /** `route('inventory.api.pos-catalog')`. */
  catalogUrl: string;
  /** `route('inventory.api.pos-product', …)` — the live single-item lookup, used when online. */
  productUrlFor?: (key: string | number) => string;
  /** How often to try the queue while the browser believes it has a network. */
  flushIntervalMs?: number;
  /** The server's batch ceiling, echoed from `PosOfflineSyncService::MAX_ROWS`. */
  maxBatch?: number;
  warehouseId?: () => number | null;
}

function csrfToken(): string {
  // The house pattern (DataTable, NotificationBell, BarcodePrint all read this tag). The shell emits
  // it from `resources/views/app.blade.php`; without it every JSON POST below is a 419.
  return (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null)?.content ?? '';
}

export function useOfflineTill(options: OfflineTillOptions) {
  const online = ref(typeof navigator === 'undefined' ? true : navigator.onLine !== false);
  const ready = ref(false);
  const engine = ref<CreatedStore['engine'] | 'none'>('none');
  const summary = ref<QueueSummary>(summarise([]));
  const tickets = shallowRef<QueuedTicket[]>([]);
  const catalog = ref<CatalogState>(describeCatalog(loadCatalog(), options.warehouseId?.() ?? null));
  const syncError = ref('');
  const syncing = ref(false);

  let queue: ReturnType<typeof createQueue> | null = null;
  let timer: number | undefined;

  const currentWarehouse = () => options.warehouseId?.() ?? null;

  async function transport(rows: Array<Record<string, unknown>>): Promise<SyncResponse> {
    const response = await fetch(options.syncUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken() },
      body: JSON.stringify({ sales: rows }),
    });

    // A non-2xx with a JSON body is still a set of verdicts only if the server sent verdicts. A 419
    // or a 500 is "no usable answer", and the difference decides whether tickets are dropped.
    let body: { results?: SyncResult[] } | null = null;

    try {
      body = (await response.json()) as { results?: SyncResult[] };
    } catch {
      body = null;
    }

    if (!response.ok || !body || !Array.isArray(body.results)) {
      throw new Error(
        response.status === 419
          ? 'Your session expired. Reload the till, then sync again — the tickets are safe on this device.'
          : `The server answered ${response.status} without a verdict.`,
      );
    }

    return { ...body, results: body.results } as SyncResponse;
  }

  function bind(list: QueuedTicket[]) {
    tickets.value = list;
    // The arithmetic belongs to `queue.ts`, including the "today" the stale flag needs; a second
    // local tally would be a second answer to "how much money is waiting", which is the one number
    // a cashier must be able to reconcile against the drawer.
    summary.value = summarise(list, new Date().toISOString().slice(0, 10));
    writePendingSignal(summary.value);
  }

  async function reload(): Promise<void> {
    if (!queue) return;

    bind(await queue.list());
  }

  async function flushNow(force = false): Promise<FlushOutcome | null> {
    if (!queue) return null;

    // Deliberate: while the browser says there is no network, do not spend attempts. A day-long outage
    // would otherwise end with every ticket at "maximum backoff" for no reason.
    if (!online.value && !force) return null;

    syncing.value = true;
    syncError.value = '';

    try {
      const outcome = await queue.flush({ force });

      await reload();

      return outcome;
    } catch (error) {
      // `flush` already swallows transport failures; getting here means something below the queue
      // broke, and the till must not lose a sale to that.
      syncError.value = error instanceof Error ? error.message : 'The sync could not run.';

      return null;
    } finally {
      syncing.value = false;
    }
  }

  async function queueSale(payload: SalePayload, expectedTotal: number, label: string): Promise<QueuedTicket | null> {
    if (!queue) return null;

    const ticket = await queue.enqueue({ payload, expected_total: expectedTotal, label });

    await reload();

    return ticket;
  }

  async function retryTicket(ref: string, acknowledgeStaleDate = false): Promise<void> {
    await queue?.retry(ref, { acknowledgeStaleDate });
    await reload();
  }

  async function discardTicket(ref: string): Promise<void> {
    await queue?.discard(ref);
    await reload();
  }

  /**
   * Pull the catalogue and keep it. Silent on failure by design: the previous snapshot, however old,
   * is strictly better than none, so a network hiccup must not clear what the till can sell from.
   */
  async function refreshCatalog(): Promise<{ ok: boolean; rows: number; truncated: boolean; saved: boolean }> {
    if (!online.value) return { ok: false, rows: catalog.value.snapshot?.items.length ?? 0, truncated: catalog.value.partial, saved: false };

    try {
      const warehouse = currentWarehouse();
      const url = warehouse ? `${options.catalogUrl}?warehouse_id=${warehouse}` : options.catalogUrl;
      const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });

      if (!response.ok) return { ok: false, rows: catalog.value.snapshot?.items.length ?? 0, truncated: catalog.value.partial, saved: false };

      const body = (await response.json()) as { items?: CatalogRow[]; synced_at?: string; warehouse_id?: number | null; total?: number; truncated?: boolean };
      const saved = saveCatalog({
        synced_at: body.synced_at ?? new Date().toISOString(),
        warehouse_id: body.warehouse_id ?? warehouse,
        total: Number(body.total ?? (body.items?.length ?? 0)),
        truncated: Boolean(body.truncated),
        items: body.items ?? [],
      });

      catalog.value = describeCatalog(saved ? loadCatalog() : null, currentWarehouse());

      return { ok: true, rows: body.items?.length ?? 0, truncated: Boolean(body.truncated), saved };
    } catch {
      return { ok: false, rows: catalog.value.snapshot?.items.length ?? 0, truncated: catalog.value.partial, saved: false };
    }
  }

  /**
   * One product, from wherever it can be had: the live lookup when there is a network, the cache when
   * there is not. The till calls this from scan, from search, and from a catalogue tile click, and it
   * must not care which world it is in.
   */
  async function lookupProduct(key: string | number): Promise<CatalogRow | null> {
    if (online.value && options.productUrlFor) {
      try {
        const warehouse = currentWarehouse();
        const url = `${options.productUrlFor(key)}${warehouse ? `?warehouse_id=${warehouse}` : ''}`;
        const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        const body = (await response.json()) as { found?: boolean; data?: CatalogRow };

        if (body?.found && body.data) return body.data;
      } catch {
        // Fall through to the cache. A scan that fails because of the network and then fails again
        // because the code did not try the cache is the exact thing this slice exists to stop.
      }
    }

    return findInCatalog(catalog.value.snapshot, key);
  }

  function searchCached(term: string, limit = 60): CatalogRow[] {
    return searchCatalog(catalog.value.snapshot, term, limit);
  }

  function goOnline() {
    online.value = true;

    // The moment a link returns is the moment a queue should start moving; no click required.
    void flushNow();
  }

  function goOffline() {
    online.value = false;
  }

  function onVisibility() {
    if (document.visibilityState === 'visible') void flushNow();
  }

  onMounted(async () => {
    const created = await createStore();

    engine.value = created.engine;

    queue = createQueue({
      store: created.store,
      send: transport,
      now: () => Date.now(),
      newRef: () => (typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : `t-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`),
      maxBatch: options.maxBatch,
      onChange: (s) => {
        summary.value = s;
        writePendingSignal(s);
      },
    });

    // `reload()` has already written the signal, so the header badge agrees with this page from the
    // first paint rather than after the first change.

    window.addEventListener('online', goOnline);
    window.addEventListener('offline', goOffline);
    document.addEventListener('visibilitychange', onVisibility);

    timer = window.setInterval(() => {
      if (summary.value.queued + summary.value.attention > 0) void flushNow();
    }, options.flushIntervalMs ?? 30_000);

    ready.value = true;
  });

  onBeforeUnmount(() => {
    window.removeEventListener('online', goOnline);
    window.removeEventListener('offline', goOffline);
    document.removeEventListener('visibilitychange', onVisibility);

    if (timer) window.clearInterval(timer);
  });

  return {
    online,
    ready,
    engine,
    summary,
    tickets,
    catalog,
    syncing,
    syncError,
    queueSale,
    flushNow,
    retryTicket,
    discardTicket,
    refreshCatalog,
    lookupProduct,
    searchCached,
    reload,
  };
}
