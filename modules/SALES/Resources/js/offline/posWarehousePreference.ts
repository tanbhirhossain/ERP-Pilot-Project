/** Browser-local till preference; never grants access or overrides the server's active options. */
type WarehouseOption = { value: number | string };
type StorageLike = Pick<Storage, 'getItem' | 'setItem' | 'removeItem'>;

export function warehouseKey(userId: number | string): string {
  return `pos.defaultWarehouse.v1.user.${userId}`;
}

export function readWarehouse(storage: StorageLike, userId: number | string, options: WarehouseOption[]): number | null {
  try {
    const raw = storage.getItem(warehouseKey(userId));
    if (!raw || !/^[1-9]\d*$/.test(raw)) return null;
    const id = Number(raw);
    if (!Number.isSafeInteger(id) || !options.some(option => String(option.value) === raw)) {
      storage.removeItem(warehouseKey(userId));
      return null;
    }
    return id;
  } catch { return null; }
}

export function saveWarehouse(storage: StorageLike, userId: number | string, value: number | null, options: WarehouseOption[]): void {
  try {
    const key = warehouseKey(userId);
    if (value != null && options.some(option => Number(option.value) === Number(value))) storage.setItem(key, String(value));
    else storage.removeItem(key);
  } catch { /* Private mode / storage disabled: selection still works for this visit. */ }
}
