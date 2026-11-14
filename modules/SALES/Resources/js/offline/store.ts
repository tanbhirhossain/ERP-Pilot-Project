/**
 * F22 — where the queue physically lives.
 *
 * IndexedDB, because a till queues whole tickets (payloads, not scalars) and `localStorage`'s 5 MB
 * string-per-key model is the wrong shape for a record you read as a list. But this module's real job
 * is the *fallback*: a tablet in private browsing, or a browser that has simply decided to deny
 * storage, must still queue the sale. Losing a ticket because the storage layer chose a different
 * engine is not an edge case at a counter, it is the day's takings.
 *
 * The fallback is a JSON array in `localStorage` under the version key. Same interface, same records,
 * slower and smaller — which is fine, because it is a till with a queue of dozens, not a database.
 */

import type { QueuedTicket, QueueStore } from './queue';

export const IDB_NAME = 'erp-pos-offline';
export const IDB_STORE = 'sales';
export const LS_KEY = 'sales.offline.queue.v1';

function memoryStore(seed: QueuedTicket[] = []): QueueStore {
  const rows = new Map(seed.map((t) => [t.client_ref, t]));

  return {
    all: async () => [...rows.values()],
    put: async (ticket) => {
      rows.set(ticket.client_ref, ticket);
    },
    remove: async (ref) => {
      rows.delete(ref);
    },
  };
}

/**
 * IndexedDB, or `null` when it is unavailable or refuses to open.
 *
 * The `onupgradeneeded` path creates the object store only — no keyPath, because every record is
 * already keyed by `client_ref` in the logical sense and re-opening with a new key configuration would
 * need a version bump that loses the queue of a till that is *mid-outage*. Reading and writing by
 * out-of-line key keeps the schema boring, which is what a queue store should be.
 */
function openIdb(): Promise<IDBDatabase | null> {
  return new Promise((resolve) => {
    let request: IDBOpenDBRequest;

    try {
      if (typeof indexedDB === 'undefined') {
        resolve(null);

        return;
      }

      request = indexedDB.open(IDB_NAME, 1);
    } catch {
      resolve(null);

      return;
    }

    request.onupgradeneeded = () => {
      const db = request.result;

      if (!db.objectStoreNames.contains(IDB_STORE)) db.createObjectStore(IDB_STORE);
    };
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => resolve(null);
    // A blocked or permission-denied open must not hang the till: the fallback is chosen instead.
    request.onblocked = () => resolve(null);
  });
}

function idbStore(db: IDBDatabase): QueueStore {
  function tx(mode: IDBTransactionMode) {
    return db.transaction(IDB_STORE, mode).objectStore(IDB_STORE);
  }

  function wrap<T>(request: IDBRequest<T>): Promise<T> {
    return new Promise((resolve, reject) => {
      request.onsuccess = () => resolve(request.result);
      request.onerror = () => reject(request.error);
    });
  }

  return {
    all: async () => ((await wrap(tx('readonly').getAll() as IDBRequest<QueuedTicket[]>)) ?? []) as QueuedTicket[],
    put: async (ticket) => {
      await wrap(tx('readwrite').put(ticket, ticket.client_ref));
    },
    remove: async (ref) => {
      await wrap(tx('readwrite').delete(ref));
    },
  };
}

function localStore(storage: Storage): QueueStore {
  function read(): QueuedTicket[] {
    try {
      const raw = storage.getItem(LS_KEY);

      return raw ? (JSON.parse(raw) as QueuedTicket[]) : [];
    } catch {
      // Unreadable is not "empty": a corrupt entry is dropped, the rest of the day survives.
      return [];
    }
  }

  function write(rows: QueuedTicket[]): void {
    try {
      storage.setItem(LS_KEY, JSON.stringify(rows));
    } catch {
      // Out of space with a ticket in hand is the worst case there is. Nothing here can fix it, so
      // say so loudly instead of swallowing it: the queue keeps living in memory for this session.
      console.warn('[pos] offline queue could not be persisted to local storage');
    }
  }

  return {
    all: async () => read(),
    put: async (ticket) => {
      const rows = read().filter((r) => r.client_ref !== ticket.client_ref);
      rows.push(ticket);
      write(rows);
    },
    remove: async (ref) => {
      write(read().filter((r) => r.client_ref !== ref));
    },
  };
}

export interface CreatedStore {
  store: QueueStore;
  /** Which engine is in play, so the desk can tell the cashier where their tickets are. */
  engine: 'indexeddb' | 'localstorage' | 'memory';
}

/** Called once per page load. Never throws: a till must open even when storage says no. */
export async function createStore(): Promise<CreatedStore> {
  if (typeof localStorage === 'undefined') return { store: memoryStore(), engine: 'memory' };

  const db = await openIdb();

  if (db) return { store: idbStore(db), engine: 'indexeddb' };

  try {
    // Probe before committing: Safari in private mode hands you a `localStorage` that throws on write.
    localStorage.setItem('erp.pos.probe', '1');
    localStorage.removeItem('erp.pos.probe');

    return { store: localStore(localStorage), engine: 'localstorage' };
  } catch {
    return { store: memoryStore(), engine: 'memory' };
  }
}

export const __test = { memoryStore, localStore, LS_KEY };
