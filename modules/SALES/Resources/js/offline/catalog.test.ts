/**
 * F22 — the cached catalogue, tested where it actually lives: in a browser, offline, with a blob from
 * `localStorage` that may be stale, truncated, foreign or corrupt.
 *
 * The backend endpoint has its own Pest test; what would rot quietly is the *reading* of it. Every
 * rule here exists because a till that answers from a cache has to be as strict about what it will
 * believe as a server is: a snapshot from another warehouse has different prices, a truncated snapshot
 * cannot claim to have found nothing, and a row missing from the cache must not look like zero stock.
 */

import { describe, expect, it } from 'vitest';

import {
  CATALOG_KEY,
  describeCatalog,
  findInCatalog,
  loadCatalog,
  offlineStockWarning,
  saveCatalog,
  searchCatalog,
  type CatalogRow,
  type CatalogSnapshot,
} from './catalog';
import type { SalePayload } from './queue';

function row(over: Partial<CatalogRow> = {}): CatalogRow {
  return {
    id: 1,
    value: 1,
    name: 'Basmati Rice 5kg',
    label: 'Basmati Rice 5kg — 1,250.00',
    sku: 'RICE-5',
    barcode: '8901234567890',
    selling_price: '1250.00',
    price: '1250.00',
    vat_rate: '5.00',
    available: 12,
    ...over,
  };
}

function snapshot(over: Partial<CatalogSnapshot> = {}): CatalogSnapshot {
  return {
    v: 1,
    synced_at: '2026-11-09T09:00:00+00:00',
    warehouse_id: 7,
    total: 2,
    truncated: false,
    items: [row(), row({ id: 2, value: 2, name: 'Sunflower Oil 5L', sku: 'OIL-5', barcode: '8900000000001', selling_price: '980.50', available: 3 })],
    ...over,
  };
}

/**
 * `saveCatalog` takes the snapshot with `v` left off — the writer is the one that stamps it, which is
 * the point: a caller cannot smuggle a version in. Spelled out field by field rather than destructured
 * so that adding a column to `CatalogSnapshot` fails to typecheck here instead of silently arriving.
 */
function body(over: Partial<CatalogSnapshot> = {}): Omit<CatalogSnapshot, 'v'> {
  const s = snapshot(over);

  return {
    synced_at: s.synced_at,
    warehouse_id: s.warehouse_id,
    total: s.total,
    truncated: s.truncated,
    items: s.items,
  };
}

function fakeStorage(initial: Record<string, string> = {}): Storage {
  const map = new Map(Object.entries(initial));

  return {
    getItem: (k: string) => map.get(k) ?? null,
    setItem: (k: string, v: string) => void map.set(k, v),
    removeItem: (k: string) => void map.delete(k),
    clear: () => map.clear(),
    key: (i: number) => [...map.keys()][i] ?? null,
    get length() {
      return map.size;
    },
  } as unknown as Storage;
}

const at = (iso: string) => Date.parse(iso);

describe('the cache read', () => {
  it('round-trips a snapshot through the store', () => {
    const s = fakeStorage();

    expect(saveCatalog(body(), s)).toBe(true);

    const back = loadCatalog(s);

    expect(back?.items.map((r) => r.name)).toEqual(['Basmati Rice 5kg', 'Sunflower Oil 5L']);
    // Money survives as text: 1250 becomes '1250' and the till's totals move to the cent.
    expect(back?.items[0].selling_price).toBe('1250.00');
  });

  it('stamps the version on write and refuses a foreign one on read', () => {
    const s = fakeStorage({ [CATALOG_KEY]: JSON.stringify({ v: 99, items: [] }) });

    expect(loadCatalog(s)).toBeNull();
  });

  it('treats anything it does not understand as absent, not repairable', () => {
    for (const raw of ['', '{', 'null', '[]', '"x"', JSON.stringify({ v: 1 }), JSON.stringify({ v: 1, items: {} })]) {
      expect(loadCatalog(fakeStorage({ [CATALOG_KEY]: raw }))).toBeNull();
    }
  });

  it('drops the cache when a write fails, rather than keeping a partial one', () => {
    const map = new Map<string, string>();
    const s = {
      getItem: (k: string) => map.get(k) ?? null,
      setItem: (k: string, v: string) => {
        if (v.length > 10) throw new Error('QuotaExceededError');
        map.set(k, v);
      },
      removeItem: (k: string) => void map.delete(k),
    } as unknown as Storage;

    map.set(CATALOG_KEY, JSON.stringify(snapshot()));

    expect(saveCatalog(body({ total: 900 }), s)).toBe(false);
    // The old snapshot is gone: a stale-while-wrong cache would be worse than none at all, because
    // the till would keep selling prices it can no longer explain.
    expect(loadCatalog(s)).toBeNull();
  });

  it('falls back to the environment when no store is handed in', () => {
    // `undefined` is not "no storage", it is "use this browser's localStorage" — which is how the
    // till calls these without threading a store through every component. `null` is the store that
    // genuinely is not there (a worker, or a hardened browser), and it must not throw.
    expect(saveCatalog(body())).toBe(true);
    expect(loadCatalog()?.items).toHaveLength(2);

    window.localStorage.removeItem(CATALOG_KEY);

    expect(loadCatalog()).toBeNull();
  });
});

describe('describeCatalog', () => {
  it('flags a snapshot taken for a different till', () => {
    const s = snapshot({ warehouse_id: 7 });

    expect(describeCatalog(s, 7).mismatched).toBe(false);
    expect(describeCatalog(s, 8).mismatched).toBeTruthy();
    // No warehouse selected server-side is `null`, which is *not* a mismatch with a cached null.
    expect(describeCatalog(snapshot({ warehouse_id: null }), null).mismatched).toBeFalsy();
    expect(describeCatalog(snapshot({ warehouse_id: null }), 4).mismatched).toBeTruthy();
  });

  it('passes the truncation flag through as `partial`', () => {
    expect(describeCatalog(snapshot({ truncated: true }), 7).partial).toBeTruthy();
  });

  it('clamps a clock from the future to zero minutes', () => {
    const s = snapshot({ synced_at: '2026-11-09T10:00:00+00:00' });

    expect(describeCatalog(s, 7, at('2026-11-09T09:00:00+00:00')).age_minutes).toBe(0);
    expect(describeCatalog(snapshot(), 7, at('2026-11-09T11:00:00+00:00')).age_minutes).toBe(120);
  });

  it('reports no age and no mismatch when nothing is cached', () => {
    expect(describeCatalog(null, 7)).toEqual({ snapshot: null, mismatched: false, partial: false, age_minutes: null });
  });
});

describe('the offline twins of the two lookups', () => {
  it('finds by id, sku or barcode, case-insensitively and trimmed', () => {
    const s = snapshot();

    expect(findInCatalog(s, 2)?.name).toBe('Sunflower Oil 5L');
    expect(findInCatalog(s, '2')?.id).toBe(2);
    expect(findInCatalog(s, ' oil-5 ')?.id).toBe(2);
    expect(findInCatalog(s, '8901234567890')?.id).toBe(1);
    // A numeric key that is not an id must not grab a row by accident: '5' is no id here.
    expect(findInCatalog(s, '5')).toBeNull();
    expect(findInCatalog(s, '   ')).toBeNull();
    expect(findInCatalog(null, '1')).toBeNull();
  });

  it('searches name, sku and barcode and honours the limit', () => {
    const s = snapshot({
      items: [
        row({ id: 10, name: 'Nut 1', sku: 'SKU-1' }),
        row({ id: 11, name: 'Nut 12', sku: 'SKU-12' }),
        row({ id: 12, name: 'Ghee 120', sku: 'SKU-120' }),
        row({ id: 13, name: 'Oil 5L', sku: 'SKU-7', barcode: '111' }),
      ],
    });

    // Substring, not prefix — like the server's typeahead, `Nut 1` is also inside `Nut 12`.
    expect(searchCatalog(s, 'nut').map((r) => r.id)).toEqual([10, 11]);
    expect(searchCatalog(s, 'NUT 1').map((r) => r.id)).toEqual([10, 11]);
    expect(searchCatalog(s, 'SKU-1').map((r) => r.id)).toEqual([10, 11, 12]);
    expect(searchCatalog(s, ' 120 ').map((r) => r.id)).toEqual([12]);
    expect(searchCatalog(s, '111').map((r) => r.id)).toEqual([13]);
    expect(searchCatalog(s, 'nothing like it')).toEqual([]);
    // The limit is what keeps a 900-row answer off the screen; empty term = the first page of all.
    expect(searchCatalog(s, '', 2)).toHaveLength(2);
    expect(searchCatalog(s, 'sku', 1)).toHaveLength(1);
    expect(searchCatalog(null, 'anything')).toEqual([]);
  });
});

describe('offlineStockWarning', () => {
  const payload = (items: SalePayload['items']): SalePayload => ({ items } as SalePayload);

  it('warns about overselling with the numbers a cashier can check', () => {
    const s = snapshot();
    const warning = offlineStockWarning(payload([{ product_id: 2, qty: 4, unit_price: '980.50', vat_rate: '5.00', discount: '0' }]), s);

    expect(warning).toContain('Sunflower Oil 5L');
    expect(warning).toContain('4 sold, 3 on the shelf');
    // It is a warning, not a refusal — the ledger decides at sync time.
    expect(warning).toContain('The ledger decides when this syncs.');
  });

  it('adds the lines that share a product before comparing', () => {
    const s = snapshot();
    const two = payload([
      { product_id: 2, qty: 2, unit_price: '980.50', vat_rate: '5.00', discount: '0' },
      { product_id: 2, qty: 2, unit_price: '980.50', vat_rate: '5.00', discount: '0' },
    ]);

    expect(offlineStockWarning(two, s)).toContain('4 sold, 3 on the shelf');
  });

  it('stays quiet when the cached stock covers the cart', () => {
    expect(offlineStockWarning(payload([{ product_id: 1, qty: 12, unit_price: '1250.00', vat_rate: '5.00', discount: '0' }]), snapshot())).toBe('');
  });

  it('never reads a missing row as zero stock', () => {
    const warning = offlineStockWarning(payload([{ product_id: 99, qty: 1, unit_price: '1.00', vat_rate: '0', discount: '0' }]), snapshot());

    expect(warning).toContain('not in this device’s catalogue');
    expect(warning).not.toContain('99 sold, 0');
  });

  it('says nothing when there is no cache to be wrong about', () => {
    expect(offlineStockWarning(payload([{ product_id: 1, qty: 1e6, unit_price: '1', vat_rate: '0', discount: '0' }]), null)).toBe('');
  });

  it('compares on the decimal string a cached `available` may be', () => {
    const s = snapshot({ items: [row({ available: '3' })] });

    expect(offlineStockWarning(payload([{ product_id: 1, qty: 3, unit_price: '1', vat_rate: '0', discount: '0' }]), s)).toBe('');
    expect(offlineStockWarning(payload([{ product_id: 1, qty: 4, unit_price: '1', vat_rate: '0', discount: '0' }]), s)).toContain('4 sold, 3');
  });
});
