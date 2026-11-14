/**
 * F22 — the offline till's queue: the whole policy in one pure module.
 *
 * Why this is not just `localStorage.setItem('sales', …)`. An offline queue fails in ways that only
 * surface at the end of a trading day, and none of them look like a bug while you are testing with
 * the network on:
 *
 *  - **A retry is normal, not exceptional.** The moment a request goes out, the till cannot know
 *    whether the server got it. So a ticket is only ever *removed after the server has said which
 *    verdict it reached*, and the device reference (see `PosOfflineSyncService`) makes the second
 *    arrival harmless.
 *  - **A refused ticket is not a failed one.** "The drawer was closed while you were offline" will
 *    never succeed on retry. Re-sending it every ten seconds for an hour achieves nothing and buries
 *    the one message a human has to act on, so refusals stop the auto-flush and wait for a person.
 *  - **Two tabs, one device.** Everything is keyed on `client_ref`, and the store is the arbiter: a
 *    ticket already in the store is never written twice.
 *
 * Nothing here touches `window`, `fetch` or IndexedDB. The browser glue is `store.ts` and
 * `useOfflineTill.ts`; this file takes its clock, its storage and its transport as arguments, which
 * is what lets `queue.test.ts` drive a whole trading day — including the parts that only break under
 * a simulated bad network — in about four milliseconds.
 */

export const QUEUE_VERSION = 1;

/** The storage key the header badge reads. See `writePendingSignal` for why it is not an import. */
export const PENDING_SIGNAL_KEY = 'sales.offline.pending.v1';

/**
 * `queued`      — waiting to go, retried automatically.
 * `in_flight`   — sent, no answer yet. On the next page load this becomes `queued` again, because an
 *                 unanswered request is exactly the case the server's unique key exists to absorb.
 * `attention`   — the server answered *no*. Auto-flush stops; a person decides.
 * `posted`      — the server answered yes. Kept for a while so a slip can be reprinted without a
 *                 network, then pruned: the ledger is the record, this is the till's copy.
 */
export type TicketStatus = 'queued' | 'in_flight' | 'attention' | 'posted';

export interface SalePayload {
  warehouse_id: number | null;
  customer_id: number | null;
  customer_name: string;
  payment_method: string;
  bank_id: number | null;
  session_id: number | null;
  items: Array<{
    product_id: number;
    variant_id: number | null;
    qty: number;
    unit_price: number;
    discount_pct: number;
    vat_rate: number;
  }>;
  cash_amount?: number;
  bank_amount?: number;
  paid_amount?: number;
  tendered_amount?: number;
  [key: string]: unknown;
}

export interface QueuedTicket {
  client_ref: string;
  status: TicketStatus;
  /** What the till will send, in the shape `PosSaleRequest` validates. */
  payload: SalePayload;
  /** The total the cashier showed the customer, from the device's cached price list. */
  expected_total: number;
  /** One line for the queue list: "3 items · Walk-in Customer". */
  label: string;
  /** ISO stamp of when the money changed hands at the counter. Drives the stale-date rule. */
  created_at: string;
  attempts: number;
  last_error: string | null;
  last_attempt_at: string | null;
  /** What the server said, when it said yes. */
  pos_no: string | null;
  sale_id: number | null;
  posted_at: string | null;
  /** Set when the human overrode the "this was rung up yesterday" refusal. */
  acknowledged_stale_date?: boolean;
}

export interface QueueStore {
  all: () => Promise<QueuedTicket[]>;
  put: (ticket: QueuedTicket) => Promise<void>;
  remove: (clientRef: string) => Promise<void>;
}

/** The transport: post a batch, get the verdicts. Throwing means "no answer", which is not a refusal. */
export type SyncTransport = (rows: Array<Record<string, unknown>>) => Promise<SyncResponse>;

export interface SyncResult {
  client_ref: string;
  status: 'synced' | 'duplicate' | 'rejected';
  reason?: string | null;
  kind?: string;
  message?: string;
  pos_no?: string;
  sale_id?: number;
  amount_drift?: string;
  errors?: Record<string, string[]>;
}

export interface SyncResponse {
  results: SyncResult[];
  synced?: number;
  duplicated?: number;
  rejected?: number;
}

export interface QueueDeps {
  store: QueueStore;
  send: SyncTransport;
  /** Milliseconds. Injected so a test can move time without waiting for it. */
  now: () => number;
  /** A UUID. Injected for the same reason. */
  newRef: () => string;
  /** Called after every mutation with the new summary, for the badge and the page. */
  onChange?: (summary: QueueSummary) => void;
  /** The batch size the server will accept, echoed from `PosOfflineSyncService::MAX_ROWS`. */
  maxBatch?: number;
  /** How long a posted ticket's local copy survives, in days. */
  keepPostedDays?: number;
  /** Cap on posted tickets kept, whatever their age — a tablet should not grow forever. */
  keepPostedCount?: number;
}

export interface QueueSummary {
  queued: number;
  attention: number;
  posted: number;
  total: number;
  /** Oldest unposted ticket's stamp, or null. The till shows this: "waiting since 11:42". */
  oldest_waiting: string | null;
  /** Sum of the money in tickets the ledger does not have yet. A drawer reconciles against this. */
  pending_value: number;
  /** True when any ticket was rung up on an earlier date, so the desk can warn before syncing. */
  has_stale: boolean;
}

const MINUTES = 60_000;
const HOURS = 60 * MINUTES;

/** The retry ladder: quick while a glitch clears, then patient, capped so a long outage costs no traffic. */
const BACKOFF_STEPS_MS = [2_000, 5_000, 15_000, 60_000, 5 * 60_000, 15 * 60_000];

export function backoffMs(attempts: number): number {
  const index = Math.min(Math.max(attempts, 0), BACKOFF_STEPS_MS.length) - 1;

  return index < 0 ? 0 : BACKOFF_STEPS_MS[index];
}

/**
 * Whether a ticket is due to be sent again.
 *
 * `attention` rows answer `false` unconditionally: the server refused them for a reason the device
 * cannot fix, and hammering it turns one real message into background noise.
 */
export function dueForRetry(ticket: QueuedTicket, nowMs: number): boolean {
  if (ticket.status === 'posted' || ticket.status === 'attention') return false;
  if (ticket.status === 'in_flight') return true;
  if (!ticket.last_attempt_at) return true;

  return nowMs - Date.parse(ticket.last_attempt_at) >= backoffMs(ticket.attempts);
}

/**
 * True when the ticket was rung up on a date before `todayIso`.
 *
 * Only *past* dates count. A device whose clock runs a few minutes fast must not wedge its own queue
 * at midnight, whereas a ticket from yesterday genuinely belongs to a day that may already be
 * counted — which is the case the server refuses and the cashier has to decide about.
 */
export function isStaleDate(createdIso: string, todayIso: string): boolean {
  const created = createdIso.slice(0, 10);

  return created < todayIso;
}

export function summarise(tickets: QueuedTicket[], todayIso?: string): QueueSummary {
  const today = todayIso ?? new Date().toISOString().slice(0, 10);
  const summary: QueueSummary = {
    queued: 0,
    attention: 0,
    posted: 0,
    total: tickets.length,
    oldest_waiting: null,
    pending_value: 0,
    has_stale: false,
  };

  for (const ticket of tickets) {
    if (ticket.status === 'posted') {
      summary.posted++;

      continue;
    }

    summary.pending_value += Number(ticket.expected_total) || 0;

    if (ticket.status === 'attention') summary.attention++;
    else summary.queued++;

    if (!summary.oldest_waiting || ticket.created_at < summary.oldest_waiting) {
      summary.oldest_waiting = ticket.created_at;
    }

    if (isStaleDate(ticket.created_at, today)) summary.has_stale = true;
  }

  return summary;
}

/** FIFO by counter time: the drawer's day is built in the order the customers were served. */
export function sortForReplay(tickets: QueuedTicket[]): QueuedTicket[] {
  return [...tickets].sort((a, b) => (a.created_at < b.created_at ? -1 : a.created_at > b.created_at ? 1 : 0));
}

/** The row the server sees. Rebuilt rather than stored, so a hand-edited ticket cannot skip a field. */
export function syncRow(ticket: QueuedTicket): Record<string, unknown> {
  const row: Record<string, unknown> = {
    ...ticket.payload,
    client_ref: ticket.client_ref,
    rang_up_at: ticket.created_at,
    expected_total: ticket.expected_total,
  };

  if (ticket.acknowledged_stale_date) row.acknowledge_stale_date = true;

  return row;
}

/**
 * Fold one batch of verdicts back into the queue.
 *
 * Returns the updated list; the caller persists it. Anything the response did not mention is left
 * exactly as it was, because "the server did not answer about this ticket" must never read as
 * "this ticket is fine".
 */
export function applyResults(
  tickets: QueuedTicket[],
  results: SyncResult[],
  nowIso: string,
): { tickets: QueuedTicket[]; posted: SyncResult[]; attention: SyncResult[] } {
  const byRef = new Map(results.map((r) => [String(r.client_ref), r]));
  const out: QueuedTicket[] = [];
  const posted: SyncResult[] = [];
  const attention: SyncResult[] = [];

  for (const ticket of tickets) {
    const result = byRef.get(ticket.client_ref);

    if (!result) {
      // No verdict for this ticket. It stays as it is; a later flush picks it up.
      out.push(ticket);

      continue;
    }

    if (result.status === 'synced' || result.status === 'duplicate') {
      posted.push(result);
      out.push({
        ...ticket,
        status: 'posted',
        pos_no: result.pos_no ?? ticket.pos_no,
        sale_id: typeof result.sale_id === 'number' ? result.sale_id : ticket.sale_id,
        posted_at: nowIso,
        last_error: result.amount_drift ? result.message ?? null : null,
        last_attempt_at: nowIso,
      });

      continue;
    }

    attention.push(result);
    out.push({
      ...ticket,
      status: 'attention',
      attempts: ticket.attempts + 1,
      last_attempt_at: nowIso,
      // The server's own sentence, because it is the one that knows whether it was the drawer, the
      // credit limit or the shelf that said no.
      last_error: result.message ?? 'The server refused this ticket.',
    });
  }

  return { tickets: out, posted, attention };
}

/** Posted copies age out: the ledger is the record, the till only needs enough to reprint today. */
export function prunePosted(
  tickets: QueuedTicket[],
  opts: { keepPostedDays: number; keepPostedCount: number; nowIso: string },
): QueuedTicket[] {
  const posted = tickets.filter((t) => t.status === 'posted');
  const rest = tickets.filter((t) => t.status !== 'posted');
  const cutoff = Date.parse(opts.nowIso) - opts.keepPostedDays * HOURS * 24;

  const fresh = posted
    .filter((t) => Date.parse(t.posted_at ?? t.created_at) >= cutoff)
    .sort((a, b) => (a.posted_at ?? a.created_at) < (b.posted_at ?? b.created_at) ? 1 : -1)
    .slice(0, opts.keepPostedCount);

  return [...rest, ...fresh];
}

/**
 * The queue, with its storage and transport wired in.
 *
 * The one thing a queue like this gets wrong in production is re-entrancy, so it is the one thing this
 * class exists to guarantee: `flush()` sets `running` before the first `await`, and a second caller
 * gets the in-flight promise instead of a second batch. Without that, "reconnect", "page becomes
 * visible" and "the 30-second timer" firing together send the same rows three times in the same tick —
 * harmless to the ledger (that is what the unique key is for) and fatal to the cashier's confidence
 * in what the screen is showing.
 */
export function createQueue(deps: QueueDeps) {
  const maxBatch = deps.maxBatch ?? 25;
  const keepPostedDays = deps.keepPostedDays ?? 3;
  const keepPostedCount = deps.keepPostedCount ?? 80;
  let running: Promise<FlushOutcome> | null = null;

  async function emit(tickets: QueuedTicket[]): Promise<void> {
    deps.onChange?.(summarise(tickets));
  }

  async function list(): Promise<QueuedTicket[]> {
    return sortForReplay(await deps.store.all());
  }

  async function putAll(tickets: QueuedTicket[]): Promise<void> {
    for (const ticket of tickets) await deps.store.put(ticket);
  }

  /** Queue one sale. Returns the ticket, or the existing one if the ref was already there. */
  async function enqueue(input: {
    payload: SalePayload;
    expected_total: number;
    label: string;
    client_ref?: string;
  }): Promise<QueuedTicket> {
    const existing = await deps.store.all();
    const ref = input.client_ref ?? deps.newRef();
    const found = existing.find((t) => t.client_ref === ref);

    if (found) return found;

    const ticket: QueuedTicket = {
      client_ref: ref,
      status: 'queued',
      payload: input.payload,
      expected_total: Math.round((Number(input.expected_total) || 0) * 100) / 100,
      label: input.label,
      created_at: new Date(deps.now()).toISOString(),
      attempts: 0,
      last_error: null,
      last_attempt_at: null,
      pos_no: null,
      sale_id: null,
      posted_at: null,
    };

    await deps.store.put(ticket);
    await emit([...existing, ticket]);

    return ticket;
  }

  /** Anything the ledger does not have yet. */
  function unsynced(tickets: QueuedTicket[]): QueuedTicket[] {
    return tickets.filter((t) => t.status !== 'posted');
  }

  /**
   * Send what is due. Safe to call from anywhere, at any time, as often as you like.
   *
   * `force` also sends tickets the server has refused — the desk's "try again" button, where a human
   * has just changed something (opened a drawer, restocked a shelf) and the refusal is now stale.
   */
  async function flush(opts: { force?: boolean } = {}): Promise<FlushOutcome> {
    if (running) return running;

    running = doFlush(opts).finally(() => {
      running = null;
    });

    return running;
  }

  async function doFlush(opts: { force?: boolean }): Promise<FlushOutcome> {
    const all = await list();
    const nowMs = deps.now();
    const due = unsynced(all).filter((t) => (opts.force ? true : dueForRetry(t, nowMs))).slice(0, maxBatch);

    if (due.length === 0) {
      const summary = summarise(all);

      return { sent: 0, posted: 0, attention: 0, transport_failed: false, summary };
    }

    const stamped = due.map((t) => ({ ...t, status: 'in_flight' as const }));
    await putAll(stamped);

    let response: SyncResponse;

    try {
      response = await deps.send(due.map(syncRow));
    } catch (error) {
      // No answer at all: back to `queued` with one attempt spent. The distinction from a refusal is
      // the whole point — a network error must not be filed under "the server said no".
      const nowIso = new Date(deps.now()).toISOString();
      await putAll(
        due.map((t) => ({
          ...t,
          status: 'queued' as const,
          attempts: t.attempts + 1,
          last_attempt_at: nowIso,
          last_error: error instanceof Error ? error.message : 'No response from the server.',
        })),
      );
      const after = await list();
      await emit(after);

      return { sent: due.length, posted: 0, attention: 0, transport_failed: true, summary: summarise(after) };
    }

    const nowIso = new Date(deps.now()).toISOString();
    const { tickets, posted, attention } = applyResults(await list(), response.results ?? [], nowIso);
    await putAll(tickets);
    const trimmed = prunePosted(tickets, { keepPostedDays, keepPostedCount, nowIso });

    const kept = new Set(trimmed.map((t) => t.client_ref));

    for (const ticket of tickets) {
      if (!kept.has(ticket.client_ref)) await deps.store.remove(ticket.client_ref);
    }

    const after = await list();
    await emit(after);

    return {
      sent: due.length,
      posted: posted.length,
      attention: attention.length,
      transport_failed: false,
      summary: summarise(after),
    };
  }

  /** A human has fixed whatever the server objected to; put it back in line. */
  async function retry(clientRef: string, opts: { acknowledgeStaleDate?: boolean } = {}): Promise<void> {
    const all = await deps.store.all();
    const ticket = all.find((t) => t.client_ref === clientRef);

    if (!ticket) return;

    await deps.store.put({
      ...ticket,
      status: 'queued',
      attempts: 0,
      last_error: null,
      last_attempt_at: null,
      acknowledged_stale_date: opts.acknowledgeStaleDate ? true : ticket.acknowledged_stale_date,
    });

    await emit(await list());
  }

  /** Forgetting a ticket entirely. Only ever offered for something the server has never accepted. */
  async function discard(clientRef: string): Promise<void> {
    await deps.store.remove(clientRef);
    await emit(await list());
  }

  async function refresh(): Promise<QueueSummary> {
    const tickets = await list();
    const summary = summarise(tickets);
    deps.onChange?.(summary);

    return summary;
  }

  return { enqueue, flush, retry, discard, list, refresh, summarise: () => list().then(summarise) };
}

export interface FlushOutcome {
  sent: number;
  posted: number;
  attention: number;
  transport_failed: boolean;
  summary: QueueSummary;
}

/**
 * The one deliberate leak to the rest of the app: a summary written to `localStorage`.
 *
 * The header badge lives in CORE and the queue lives in SALES, and CORE must not import a module it is
 * supposed to know nothing about — the whole point of the modular monolith is that SALES can be
 * removed without CORE noticing. A versioned JSON blob under a shared key says exactly that: the badge
 * reads a number, and if the writer is gone the badge says nothing.
 */
export function writePendingSignal(summary: QueueSummary, storage: Pick<Storage, 'setItem'> | null | undefined = typeof localStorage === 'undefined' ? null : localStorage): void {
  const waiting = summary.queued + summary.attention;

  const payload = JSON.stringify({
    v: QUEUE_VERSION,
    waiting,
    attention: summary.attention,
    value: summary.pending_value.toFixed(2),
    at: new Date().toISOString(),
  });

  try {
    storage?.setItem(PENDING_SIGNAL_KEY, payload);
  } catch {
    // A private-mode or full store is not a reason a sale cannot be queued.
  }

  // The `storage` event only reaches *other* tabs, and a till spends its life in one, so say it out
  // loud as well. The header badge listens for both; neither side knows the other exists.
  try {
    window.dispatchEvent(new CustomEvent('sales.offline.signal'));
  } catch {
    /* no window (tests, workers) — the badge just reads the key on its next event */
  }
}

export function readPendingSignal(storage: Pick<Storage, 'getItem'> | null | undefined = typeof localStorage === 'undefined' ? null : localStorage): { waiting: number; attention: number; value: string } | null {
  if (!storage) return null;

  try {
    const raw = storage.getItem(PENDING_SIGNAL_KEY);

    if (!raw) return null;

    const parsed = JSON.parse(raw) as { v?: number; waiting?: number; attention?: number; value?: string };

    // A stale shape is ignored rather than guessed at: a badge that lies about money waiting is
    // worse than one that shows nothing.
    if (!parsed || parsed.v !== QUEUE_VERSION || typeof parsed.waiting !== 'number') return null;

    return { waiting: parsed.waiting, attention: parsed.attention ?? 0, value: parsed.value ?? '0.00' };
  } catch {
    return null;
  }
}

export const QUEUE_TIMING = { HOURS, MINUTES };
