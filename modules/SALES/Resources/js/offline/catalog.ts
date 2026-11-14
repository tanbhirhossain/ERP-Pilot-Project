/**
 * F22 — the catalogue snapshot a till keeps on the device.
 *
 * Separated from `queue.ts` because it has the opposite lifecycle: the queue is *durable by default*
 * (losing a ticket loses money) and the cache is *disposable by default* (a stale price is a
 * warning, not a loss). They also move at different times — the cache is rewritten whole on every
 * refresh, the queue one ticket at a time — so one store with one policy would be wrong for both.
 *
 * It lives in `localStorage` as one JSON blob rather than in IndexedDB on purpose. A catalogue is
 * read whole and written whole, and a till that finds the cache missing (private mode, cleared site
 * data, quota) must fall back to "search will not work offline" — not to an error, and not to a
 * half-populated grid.
 */

import type { SalePayload } from './queue';

export const CATALOG_KEY = 'sales.offline.catalog.v1';
export const CATALOG_VERSION = 1;

export interface CatalogRow {
  id: number;
  value: number;
  name: string;
  label: string;
  sku: string | null;
  barcode: string | null;
  /** Decimal strings, exactly as `PosCatalogController` answers: "100.00", never a float. */
  selling_price: string;
  price: string;
  vat_rate: string;
  available: number | string;
}

export interface CatalogSnapshot {
  v: number;
  synced_at: string;
  warehouse_id: number | null;
  total: number;
  truncated: boolean;
  items: CatalogRow[];
}

/** What the till has to sell: the cached rows, plus when they were true. */
export interface CatalogState {
  snapshot: CatalogSnapshot | null;
  /** The snapshot is for a different till/warehouse than the one now selected. */
  mismatched: boolean;
  /** The catalogue the server said was cut short, so a search can honestly say "not all products". */
  partial: boolean;
  /** Minutes since `synced_at`, or null when there is nothing cached. */
  age_minutes: number | null;
}

type StorageLike = Pick<Storage, 'getItem' | 'setItem' | 'removeItem'> | null | undefined;

function storageOr(fallback: StorageLike): StorageLike {
  if (fallback !== undefined && fallback !== null) return fallback;

  return typeof localStorage === 'undefined' ? null : localStorage;
}

export function loadCatalog(storage: StorageLike = undefined): CatalogSnapshot | null {
  const store = storageOr(storage);
  if (!store) return null;

  try {
    const raw = store.getItem(CATALOG_KEY);

    if (!raw) return null;

    const parsed = JSON.parse(raw) as CatalogSnapshot;

    // A snapshot of the wrong shape is treated as absent, not repaired. A till selling from a
    // half-understood cache is worse than one selling from a live search.
    if (!parsed || parsed.v !== CATALOG_VERSION || !Array.isArray(parsed.items)) return null;

    return parsed;
  } catch {
    return null;
  }
}

/** Returns false when the write failed — the caller says "this device cannot keep a catalogue". */
export function saveCatalog(snapshot: Omit<CatalogSnapshot, 'v'>, storage: StorageLike = undefined): boolean {
  const store = storageOr(storage);
  if (!store) return false;

  try {
    store.setItem(CATALOG_KEY, JSON.stringify({ v: CATALOG_VERSION, ...snapshot }));

    return true;
  } catch {
    // Quota is the realistic failure on a shared tablet with 2,000 rows. Drop the cache rather than
    // keep a partial one: a search that quietly returns 400 of 2,000 products is a silent wrong
    // answer, and "no cache" at least tells the cashier why.
    try {
      store.removeItem(CATALOG_KEY);
    } catch {
      /* nothing else to try */
    }

    return false;
  }
}

export function describeCatalog(snapshot: CatalogSnapshot | null, warehouseId: number | null, nowMs = Date.now()): CatalogState {
  if (!snapshot) {
    return { snapshot: null, mismatched: false, partial: false, age_minutes: null };
  }

  const age = nowMs - Date.parse(snapshot.synced_at);

  return {
    snapshot,
    mismatched: snapshot.warehouse_id !== (warehouseId ?? null),
    partial: snapshot.truncated,
    // Negative ages mean a device with a wrong clock; "just now" is the honest floor.
    age_minutes: Number.isFinite(age) ? Math.max(0, Math.round(age / 60_000)) : null,
  };
}

/**
 * The offline twin of `/inventory/api/pos-product/{key}`.
 *
 * The server matches id, sku *or* barcode; the cached lookup has to answer the same three, or a scan
 * that works online stops working the moment the network drops — which is precisely when the till is
 * being used.
 */
export function findInCatalog(snapshot: CatalogSnapshot | null, key: string | number): CatalogRow | null {
  if (!snapshot) return null;

  const needle = String(key).trim().toLowerCase();

  if (!needle) return null;

  return snapshot.items.find((row) => String(row.id) === needle || (row.sku ?? '').toLowerCase() === needle || (row.barcode ?? '').toLowerCase() === needle) ?? null;
}

/** The offline twin of the typeahead: name, sku or barcode, case-insensitive, `limit` rows. */
export function searchCatalog(snapshot: CatalogSnapshot | null, term: string, limit = 60): CatalogRow[] {
  if (!snapshot) return [];

  const needle = term.trim().toLowerCase();
  const matches = needle
    ? snapshot.items.filter(
        (row) =>
          row.name.toLowerCase().includes(needle) ||
          (row.sku ?? '').toLowerCase().includes(needle) ||
          (row.barcode ?? '').toLowerCase().includes(needle),
      )
    : snapshot.items;

  return matches.slice(0, limit);
}

/**
 * The warning a cashier needs *before* the sale, from a cached stock figure.
 *
 * It is deliberately not a block. The offline number is a snapshot of a warehouse that other people
 * are still stocking and selling from, so it can be wrong in both directions; the ledger decides at
 * sync time. What the till owes the cashier is the chance to see it coming.
 */
export function offlineStockWarning(payload: SalePayload, snapshot: CatalogSnapshot | null): string {
  if (!snapshot || !Array.isArray(payload.items)) return '';

  const sold = new Map<number, number>();

  for (const line of payload.items) {
    const id = Number(line.product_id);
    sold.set(id, (sold.get(id) ?? 0) + (Number(line.qty) || 0));
  }

  const short: string[] = [];

  for (const [id, qty] of sold) {
    const row = snapshot.items.find((item) => item.id === id);

    if (!row) {
      short.push(`${qty} of an item that is not in this device’s catalogue`);

      continue;
    }

    if (qty > Number(row.available)) short.push(`${row.name} (${qty} sold, ${row.available} on the shelf as of the last sync)`);
  }

  if (short.length === 0) return '';

  return `Offline stock may be oversold: ${short.join('; ')}. The ledger decides when this syncs.`;
}

export const __test = { CATALOG_VERSION };
