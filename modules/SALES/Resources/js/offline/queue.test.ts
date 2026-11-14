/**
 * F22 — the offline queue's own behaviour, with no browser and no server.
 *
 * The Pest file (`tests/Feature/SALES/Phase3OfflinePosTest.php`) proves the *server* treats a replay
 * as one sale. This file proves the other half, which is where an offline till actually breaks: a
 * ticket is only ever cleared when the ledger has said which verdict it reached, a refusal stops being
 * retried, a network error does not, and one flush is one batch however many things ask for it.
 *
 * Time, storage and transport are all arguments (see `queue.ts`), so a whole trading day — including
 * a reconnect at the wrong moment — runs in a few milliseconds and deterministically.
 */

import { beforeEach, describe, expect, it } from 'vitest';

import {
  applyResults,
  backoffMs,
  createQueue,
  dueForRetry,
  isStaleDate,
  PENDING_SIGNAL_KEY,
  prunePosted,
  QUEUE_VERSION,
  readPendingSignal,
  sortForReplay,
  summarise,
  syncRow,
  writePendingSignal,
  type QueuedTicket,
  type QueueStore,
  type SyncResponse,
  type SyncResult,
} from './queue';
import { buildSalePayload, cartLabel, cartTotalCents } from './payload';
import { __test as storeInternals } from './store';

let clock = Date.parse('2026-11-09T10:00:00.000Z');
const now = () => clock;
const iso = (offsetMs = 0) => new Date(clock + offsetMs).toISOString();

function ticket(overrides: Partial<QueuedTicket> = {}): QueuedTicket {
  return {
    client_ref: overrides.client_ref ?? 'ref-1',
    status: 'queued',
    payload: {
      warehouse_id: 3,
      customer_id: null,
      customer_name: 'Walk-in Customer',
      payment_method: 'cash',
      bank_id: null,
      session_id: 9,
      items: [{ product_id: 11, variant_id: null, qty: 2, unit_price: 100, discount_pct: 0, vat_rate: 0 }],
    },
    expected_total: 200,
    label: '1 line · 2 units · Walk-in Customer',
    created_at: iso(0),
    attempts: 0,
    last_error: null,
    last_attempt_at: null,
    pos_no: null,
    sale_id: null,
    posted_at: null,
    ...overrides,
  };
}

function store(seed: QueuedTicket[] = []): QueueStore & { rows: Map<string, QueuedTicket> } {
  const rows = new Map(seed.map((t) => [t.client_ref, t]));

  return {
    rows,
    all: async () => [...rows.values()],
    put: async (t) => {
      rows.set(t.client_ref, t);
    },
    remove: async (ref) => {
      rows.delete(ref);
    },
  };
}

function result(overrides: Partial<SyncResult> & { client_ref: string }): SyncResult {
  return { status: 'synced', pos_no: 'POS-00042', sale_id: 77, ...overrides };
}

function response(results: SyncResult[]): SyncResponse {
  return { results, synced: 1, duplicated: 0, rejected: 0 };
}

beforeEach(() => {
  clock = Date.parse('2026-11-09T10:00:00.000Z');
});

describe('enqueue', () => {
  it('mints the device reference the whole design hangs on', async () => {
    const q = store();
    const queue = createQueue({ store: q, send: async () => response([]), now, newRef: () => 'uuid-locked' });

    const t = await queue.enqueue({ payload: ticket().payload, expected_total: 200, label: 'x' });

    expect(t.client_ref).toBe('uuid-locked');
    expect(t.status).toBe('queued');
    expect(q.rows.size).toBe(1);
  });

  it('rounds the money it displays once, on the way in', async () => {
    const queue = createQueue({ store: store(), send: async () => response([]), now, newRef: () => 'r' });
    const t = await queue.enqueue({ payload: ticket().payload, expected_total: 199.999, label: 'x' });

    expect(t.expected_total).toBe(200);
  });

  it('will not hold the same ticket twice, whatever a double-tap did to the button', async () => {
    const q = store([ticket({ client_ref: 'same' })]);
    const queue = createQueue({ store: q, send: async () => response([]), now, newRef: () => 'ignored' });

    const again = await queue.enqueue({ payload: ticket().payload, expected_total: 200, label: 'x', client_ref: 'same' });

    expect(again).toEqual(q.rows.get('same'));
    expect(q.rows.size).toBe(1);
  });
});

describe('the retry ladder', () => {
  it('starts fast and stops climbing', () => {
    expect(backoffMs(0)).toBe(0);
    expect(backoffMs(1)).toBe(2_000);
    expect(backoffMs(2)).toBe(5_000);
    expect(backoffMs(99)).toBe(15 * 60_000);
  });

  it('waits out the ladder, then goes again', () => {
    const t = ticket({ attempts: 2, last_attempt_at: iso(0) });

    expect(dueForRetry(t, clock + 4_000)).toBe(false);
    expect(dueForRetry(t, clock + 5_000)).toBe(true);
  });

  it('never retries a refusal on its own', () => {
    // The server said the drawer is closed. Sending it again in ten seconds says the same thing, and
    // buryes the one message a human has to act on.
    expect(dueForRetry(ticket({ status: 'attention', attempts: 4, last_attempt_at: iso(-10_000) }), clock)).toBe(false);
    expect(dueForRetry(ticket({ status: 'posted' }), clock)).toBe(false);
  });

  it('re-sends what never got an answer, including after a reload', () => {
    // `in_flight` is what a ticket looks like when the tab is closed mid-request. The next load must
    // treat it as "send again", not as "someone else is handling it".
    expect(dueForRetry(ticket({ status: 'in_flight' }), clock)).toBe(true);
  });
});

describe('stale dates', () => {
  it('refuses only the past, because a fast clock must not wedge a queue', () => {
    expect(isStaleDate('2026-11-08T23:50:00.000Z', '2026-11-09')).toBe(true);
    expect(isStaleDate('2026-11-09T00:01:00.000Z', '2026-11-09')).toBe(false);
    expect(isStaleDate('2026-11-09T23:59:00.000Z', '2026-11-09')).toBe(false);
    expect(isStaleDate('2026-11-10T00:01:00.000Z', '2026-11-09')).toBe(false);
  });
});

describe('the row that goes over the wire', () => {
  it('adds exactly the three sync fields to the till payload', () => {
    const t = ticket();
    const row = syncRow(t);
    const extra = Object.keys(row).filter((k) => !(k in t.payload));

    expect(extra.sort()).toEqual(['client_ref', 'expected_total', 'rang_up_at']);
    expect(row.client_ref).toBe('ref-1');
    expect(row.rang_up_at).toBe(iso(0));
  });

  it('carries the human’s override and nothing else', () => {
    expect(syncRow(ticket())).not.toHaveProperty('acknowledge_stale_date');
    expect(syncRow(ticket({ acknowledged_stale_date: true }))).toMatchObject({ acknowledge_stale_date: true });
  });
});

describe('folding verdicts back', () => {
  it('files a synced ticket as posted, with the ledger’s number on it', () => {
    const { tickets, posted } = applyResults([ticket()], [result({ client_ref: 'ref-1' })], iso(1_000));

    expect(posted).toHaveLength(1);
    expect(tickets[0]).toMatchObject({ status: 'posted', pos_no: 'POS-00042', sale_id: 77, posted_at: iso(1_000) });
  });

  it('treats a duplicate as posted, because the ledger has it — that is the whole point', () => {
    const { tickets } = applyResults([ticket()], [result({ client_ref: 'ref-1', status: 'duplicate', reason: 'already-posted' })], iso(0));

    expect(tickets[0].status).toBe('posted');
    expect(tickets[0].pos_no).toBe('POS-00042');
  });

  it('keeps the server’s own sentence on a refusal and counts the attempt', () => {
    const { tickets, attention } = applyResults(
      [ticket({ attempts: 1 })],
      [result({ client_ref: 'ref-1', status: 'rejected', message: 'Session POS-SESSION-3 is closed, so it cannot take a sale.', kind: 'business_rule' })],
      iso(0),
    );

    expect(attention).toHaveLength(1);
    expect(tickets[0]).toMatchObject({ status: 'attention', attempts: 2, last_error: expect.stringContaining('closed') });
  });

  it('leaves a ticket nobody answered alone', async () => {
    const kept = ticket({ client_ref: 'quiet' });
    const { tickets } = applyResults([kept, ticket({ client_ref: 'loud' })], [result({ client_ref: 'loud' })], iso(0));

    expect(tickets.find((t) => t.client_ref === 'quiet')).toEqual(kept);
  });

  it('surfaces a price drift as the error line of an otherwise posted ticket', () => {
    const { tickets } = applyResults(
      [ticket()],
      [result({ client_ref: 'ref-1', amount_drift: '20.00', message: 'Posted, but the total moved: the price list on the device was stale.' })],
      iso(0),
    );

    expect(tickets[0].status).toBe('posted');
    expect(tickets[0].last_error).toContain('stale');
  });
});

describe('summaries', () => {
  it('separates what the drawer is waiting on from what a human is waiting on', () => {
    const summary = summarise(
      [
        ticket({ client_ref: 'a', expected_total: 100 }),
        ticket({ client_ref: 'b', status: 'attention', expected_total: 50, created_at: iso(-60_000) }),
        ticket({ client_ref: 'c', status: 'posted' }),
      ],
      '2026-11-09',
    );

    expect(summary).toMatchObject({ queued: 1, attention: 1, posted: 1, total: 3, pending_value: 150, has_stale: false });
    // Oldest *waiting*, never the posted one: this is the "waiting since" the till shows.
    expect(summary.oldest_waiting).toBe(iso(-60_000));
  });

  it('flags a ticket from yesterday so the desk can warn before a futile sync', () => {
    const summary = summarise([ticket({ created_at: '2026-11-08T09:00:00.000Z' })], '2026-11-09');

    expect(summary.has_stale).toBe(true);
  });

  it('sorts by the counter clock, because a day is reconciled in the order it happened', () => {
    const sorted = sortForReplay([ticket({ client_ref: 'b', created_at: iso(2_000) }), ticket({ client_ref: 'a', created_at: iso(1_000) })]);

    expect(sorted.map((t) => t.client_ref)).toEqual(['a', 'b']);
  });
});

describe('flush', () => {
  it('sends what is due and clears it when the ledger says yes', async () => {
    const q = store([ticket()]);
    const sent: Array<Array<Record<string, unknown>>> = [];
    const queue = createQueue({
      store: q,
      now,
      newRef: () => 'r',
      send: async (rows) => {
        sent.push(rows);

        return response([result({ client_ref: 'ref-1' })]);
      },
    });

    const outcome = await queue.flush();

    expect(sent[0]).toMatchObject([{ client_ref: 'ref-1', expected_total: 200 }]);
    expect(outcome).toMatchObject({ sent: 1, posted: 1, attention: 0, transport_failed: false });
    expect(q.rows.get('ref-1')!.status).toBe('posted');
  });

  it('marks the batch in flight while it is out, so a reload mid-request is recoverable', async () => {
    const q = store([ticket()]);
    let seen: string | undefined;
    const queue = createQueue({
      store: q,
      now,
      newRef: () => 'r',
      send: async () => {
        seen = (await q.all())[0].status;

        return response([result({ client_ref: 'ref-1' })]);
      },
    });

    await queue.flush();

    expect(seen).toBe('in_flight');
  });

  it('does not send twice when three things ask at once', async () => {
    // "reconnect", "tab became visible" and the 30-second timer all firing in one tick is the normal
    // shape of a network coming back.
    const q = store([ticket()]);
    let calls = 0;
    const queue = createQueue({
      store: q,
      now,
      newRef: () => 'r',
      send: async () => {
        calls++;

        return response([]);
      },
    });

    const [a, b, c] = await Promise.all([queue.flush(), queue.flush(), queue.flush()]);

    expect(calls).toBe(1);
    expect(a).toBe(b);
    expect(b).toBe(c);
  });

  it('treats no answer as a network problem, not a refusal', async () => {
    const q = store([ticket()]);
    const queue = createQueue({
      store: q,
      now,
      newRef: () => 'r',
      send: async () => {
        throw new TypeError('Failed to fetch');
      },
    });

    const outcome = await queue.flush();

    expect(outcome).toMatchObject({ sent: 1, posted: 0, attention: 0, transport_failed: true });
    expect(q.rows.get('ref-1')).toMatchObject({ status: 'queued', attempts: 1, last_error: 'Failed to fetch' });
  });

  it('caps a batch at what the server will accept', async () => {
    const rows = Array.from({ length: 30 }, (_, i) => ticket({ client_ref: `r${i}` }));
    const q = store(rows);
    let size = 0;
    const queue = createQueue({
      store: q,
      now,
      newRef: () => 'r',
      maxBatch: 25,
      send: async (batch) => {
        size = batch.length;

        return response([]);
      },
    });

    await queue.flush();

    expect(size).toBe(25);
  });

  it('sends nothing when every ticket is still in its backoff', async () => {
    const q = store([ticket({ attempts: 5, last_attempt_at: iso(0) })]);
    let calls = 0;
    const queue = createQueue({ store: q, now, newRef: () => 'r', send: async () => { calls++; return response([]); } });

    expect((await queue.flush()).sent).toBe(0);
    expect(calls).toBe(0);
  });

  it('skips what the ledger already has, but a human can push it again', async () => {
    const q = store([ticket({ status: 'attention', last_error: 'closed drawer' })]);
    const sent: Array<Array<Record<string, unknown>>> = [];
    const queue = createQueue({
      store: q,
      now,
      newRef: () => 'r',
      send: async (rows) => {
        sent.push(rows);

        return response([]);
      },
    });

    expect((await queue.flush()).sent).toBe(0);
    expect((await queue.flush({ force: true })).sent).toBe(1);
    expect(sent[0][0].client_ref).toBe('ref-1');
  });

  it('gives a refused ticket back to the queue when a person says they fixed it', async () => {
    const q = store([ticket({ status: 'attention', attempts: 3, last_error: 'nope' })]);
    const queue = createQueue({ store: q, now, newRef: () => 'r', send: async () => response([]) });

    await queue.retry('ref-1', { acknowledgeStaleDate: true });

    expect(q.rows.get('ref-1')).toMatchObject({ status: 'queued', attempts: 0, last_error: null, acknowledged_stale_date: true });
  });

  it('forgets a ticket the ledger never accepted, and nothing else', async () => {
    const q = store([ticket({ client_ref: 'gone' }), ticket({ client_ref: 'kept', status: 'posted' })]);
    const queue = createQueue({ store: q, now, newRef: () => 'r', send: async () => response([]) });

    await queue.discard('gone');

    expect([...q.rows.keys()]).toEqual(['kept']);
  });
});

describe('the local copy of a posted ticket', () => {
  it('is kept for a few days and then thins out, so a tablet does not grow forever', () => {
    const old = ticket({ client_ref: 'old', status: 'posted', posted_at: iso(-10 * 86_400_000) });
    const fresh = ticket({ client_ref: 'fresh', status: 'posted', posted_at: iso(0) });
    const waiting = ticket({ client_ref: 'waiting' });

    const kept = prunePosted([old, fresh, waiting], { keepPostedDays: 3, keepPostedCount: 80, nowIso: iso(0) });

    expect(kept.map((t) => t.client_ref).sort()).toEqual(['fresh', 'waiting']);
  });

  it('never prunes something the ledger does not have', () => {
    const rows = Array.from({ length: 10 }, (_, i) => ticket({ client_ref: `w${i}` }));
    const kept = prunePosted(rows, { keepPostedDays: 0, keepPostedCount: 0, nowIso: iso(0) });

    expect(kept).toHaveLength(10);
  });
});

describe('the signal the header badge reads', () => {
  function fakeStorage() {
    const map = new Map<string, string>();

    return {
      setItem: (k: string, v: string) => map.set(k, v),
      getItem: (k: string) => map.get(k) ?? null,
      map,
    };
  }

  it('round-trips the money that is waiting', () => {
    const s = fakeStorage();

    writePendingSignal(summarise([ticket({ expected_total: 120.5 }), ticket({ client_ref: 'x', status: 'attention', expected_total: 30 })]), s as unknown as Storage);

    expect(s.map.get(PENDING_SIGNAL_KEY)).toBeDefined();
    expect(readPendingSignal(s as unknown as Storage)).toEqual({ waiting: 2, attention: 1, value: '150.50' });
  });

  it('ignores a foreign or half-written blob instead of guessing', () => {
    const s = fakeStorage();
    s.map.set(PENDING_SIGNAL_KEY, JSON.stringify({ v: 99, waiting: 4 }));

    expect(readPendingSignal(s as unknown as Storage)).toBeNull();

    s.map.set(PENDING_SIGNAL_KEY, 'not json');

    expect(readPendingSignal(s as unknown as Storage)).toBeNull();
  });

  it('survives a storage that throws, because a badge is not worth losing a sale over', () => {
    const hostile = { setItem: () => { throw new Error('QuotaExceeded'); }, getItem: () => { throw new Error('nope'); } };

    expect(() => writePendingSignal(summarise([ticket()]), hostile as unknown as Storage)).not.toThrow();
    expect(readPendingSignal(hostile as unknown as Storage)).toBeNull();
  });
});

describe('the payload the till builds', () => {
  const lines = [{ product_id: 11, qty: 2, unit_price: 100, discount_pct: 0, vat_rate: 0 }];

  it('is the same body whether the network is up or not', () => {
    const built = buildSalePayload({ warehouseId: 3, customerId: null, paymentMethod: 'cash', bankId: null, sessionId: 9, lines, tenderedCents: 50000 });
    const queued = syncRow(ticket({ payload: built }));

    // Online posts `built`; offline posts `queued`. The only difference is the sync metadata.
    expect(Object.keys(queued).filter((k) => !Object.keys(built).includes(k))).toEqual(['client_ref', 'rang_up_at', 'expected_total']);
    expect(queued.payload).toBeUndefined();
    expect(queued.warehouse_id).toBe(3);
    expect(queued.items).toEqual([{ product_id: 11, variant_id: null, qty: 2, unit_price: 100, discount_pct: 0, vat_rate: 0 }]);
  });

  it('sends tender in currency, from the cents the screen showed', () => {
    const payload = buildSalePayload({ warehouseId: 1, customerId: null, paymentMethod: 'cash', bankId: null, sessionId: null, lines, tenderedCents: 100_199 });

    expect(payload.tendered_amount).toBe(1001.99);
    expect(payload.paid_amount).toBeUndefined();
  });

  it('sends what was taken now on a credit sale, and no change', () => {
    const payload = buildSalePayload({ warehouseId: 1, customerId: 4, customerName: 'Acme', paymentMethod: 'due', bankId: null, sessionId: null, lines, paidNowCents: 5_000 });

    expect(payload).toMatchObject({ customer_name: 'Acme', paid_amount: 50 });
    expect(payload.tendered_amount).toBeUndefined();
  });

  it('sends both halves of a split and neither tender figure', () => {
    const payload = buildSalePayload({ warehouseId: 1, customerId: null, paymentMethod: 'split', bankId: 2, sessionId: null, lines, cashAmount: 60, bankAmount: 140, tenderedCents: 20_000, paidNowCents: 20_000 });

    expect(payload).toMatchObject({ cash_amount: 60, bank_amount: 140 });
    expect(payload.tendered_amount).toBeUndefined();
    expect(payload.paid_amount).toBeUndefined();
  });

  it('names the walk-in customer rather than sending a null the server would have to invent', () => {
    expect(buildSalePayload({ warehouseId: 1, customerId: null, customerName: '   ', paymentMethod: 'cash', bankId: null, sessionId: null, lines }).customer_name).toBe('Walk-in Customer');
  });

  it('computes the total the way the ledger does: gross, less discount, then VAT', () => {
    // 3 × 250 = 750, less 10% = 675, plus 5% VAT = 708.75; second line 1 × 40 with no VAT.
    const cents = cartTotalCents([
      { product_id: 1, qty: 3, unit_price: 250, discount_pct: 10, vat_rate: 5 },
      { product_id: 2, qty: 1, unit_price: 40 },
    ]);

    expect(cents).toBe(74_875);
  });

  it('labels a ticket with the things a cashier recognises', () => {
    expect(cartLabel([{ product_id: 1, qty: 1, unit_price: 10 }], 'Tea House')).toBe('1 line · 1 unit · Tea House');
    expect(cartLabel([], null)).toBe('0 lines · 0 units · Walk-in Customer');
  });
});

describe('the storage fallbacks', () => {
  it('the memory engine behaves like the real one', async () => {
    const s = storeInternals.memoryStore([ticket({ client_ref: 'a' })]);

    await s.put(ticket({ client_ref: 'b' }));
    expect((await s.all()).map((t) => t.client_ref).sort()).toEqual(['a', 'b']);

    await s.remove('a');
    expect((await s.all()).map((t) => t.client_ref)).toEqual(['b']);
  });

  it('the local-storage engine overwrites by reference and ignores a corrupt row set', async () => {
    const map = new Map<string, string>();
    const storage = {
      getItem: (k: string) => map.get(k) ?? null,
      setItem: (k: string, v: string) => map.set(k, v),
      removeItem: (k: string) => map.delete(k),
    } as unknown as Storage;
    const s = storeInternals.localStore(storage);

    await s.put(ticket({ client_ref: 'a', expected_total: 1 }));
    await s.put(ticket({ client_ref: 'a', expected_total: 2 }));

    expect((await s.all())).toHaveLength(1);
    expect((await s.all())[0].expected_total).toBe(2);

    map.set('sales.offline.queue.v1', '{{{');
    expect(await s.all()).toEqual([]);
  });
});

describe('the queue endures a whole outage', () => {
  it('rings up three sales, syncs two, refuses one, and lands the last one after a fix', async () => {
    const q = store();
    const queue = createQueue({
      store: q,
      now,
      newRef: (() => {
        let n = 0;

        return () => `t${++n}`;
      })(),
      send: async (rows) => {
        // The third ticket names a drawer that has been closed; the server answers per row.
        return response(rows.map((row, i) =>
          i === 2
            ? result({ client_ref: String(row.client_ref), status: 'rejected', message: 'Session POS-3 is closed, so it cannot take a sale.', pos_no: undefined, sale_id: undefined })
            : result({ client_ref: String(row.client_ref), pos_no: `POS-0000${i + 1}` }),
        ));
      },
    });

    for (let i = 0; i < 3; i++) {
      await queue.enqueue({ payload: ticket({ client_ref: `t${i + 1}` }).payload, expected_total: 100 + i, label: `ticket ${i + 1}` });
    }

    const first = await queue.flush();

    expect(first).toMatchObject({ sent: 3, posted: 2, attention: 1 });

    // The refused one is still there, with the server’s words on it, and is not retried on its own.
    expect(q.rows.get('t3')).toMatchObject({ status: 'attention', last_error: expect.stringContaining('closed') });
    expect((await queue.flush()).sent).toBe(0);

    // A supervisor opens a drawer. The cashier retries that one ticket, and the two posted ones stay put.
    const q2 = store([q.rows.get('t3')!]);
    const queue2 = createQueue({
      store: q2,
      now: () => clock + 600_000,
      newRef: () => 'x',
      send: async (rows) => response(rows.map((row) => result({ client_ref: String(row.client_ref), pos_no: 'POS-00099' }))),
    });

    await queue2.retry('t3');

    const second = await queue2.flush();

    expect(second).toMatchObject({ sent: 1, posted: 1, attention: 0 });
    expect(q2.rows.get('t3')).toMatchObject({ status: 'posted', pos_no: 'POS-00099' });

    // Nothing the ledger has is ever counted as waiting.
    expect(summarise([q2.rows.get('t3')!]).pending_value).toBe(0);
  });
});

describe('version key', () => {
  it('is the version the reader demands', () => {
    expect(QUEUE_VERSION).toBe(1);
    expect(PENDING_SIGNAL_KEY).toBe('sales.offline.pending.v1');
  });
});
