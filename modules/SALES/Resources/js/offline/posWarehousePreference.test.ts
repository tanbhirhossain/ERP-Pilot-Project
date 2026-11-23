import { describe, expect, it } from 'vitest';
import { readWarehouse, saveWarehouse, warehouseKey } from './posWarehousePreference';

function storage() {
  const m = new Map<string, string>();
  return { getItem: (k: string) => m.get(k) ?? null, setItem: (k: string, v: string) => { m.set(k, v); }, removeItem: (k: string) => { m.delete(k); } };
}
describe('POS warehouse preference', () => {
  const options = [{ value: 2 }, { value: 3 }];
  it('remembers the chosen active warehouse per user and browser', () => {
    const s = storage(); saveWarehouse(s, 5, 2, options);
    expect(readWarehouse(s, 5, options)).toBe(2); expect(readWarehouse(s, 6, options)).toBeNull();
    saveWarehouse(s, 5, 3, options); expect(readWarehouse(s, 5, options)).toBe(3);
    saveWarehouse(s, 5, null, options); expect(readWarehouse(s, 5, options)).toBeNull();
  });
  it('rejects stale, invalid and manipulated values', () => {
    const s = storage(); s.setItem(warehouseKey(5), '9'); expect(readWarehouse(s, 5, options)).toBeNull();
    s.setItem(warehouseKey(5), '2junk'); expect(readWarehouse(s, 5, options)).toBeNull();
    saveWarehouse(s, 5, 9, options); expect(readWarehouse(s, 5, options)).toBeNull();
  });
  it('does not break the till when storage is unavailable', () => {
    const s = { getItem: () => { throw Error('denied'); }, setItem: () => { throw Error('denied'); }, removeItem: () => { throw Error('denied'); } };
    expect(readWarehouse(s, 5, options)).toBeNull(); expect(() => saveWarehouse(s, 5, 2, options)).not.toThrow();
  });
});
