/**
 * The device reference every till mints when the cashier presses Sell — one function, because two
 * copies of "a UUID, unless the browser cannot make one" is how the live path and the offline queue
 * diverged into minting references the server refused.
 *
 * `crypto.randomUUID` needs a secure context, and tills are served over plain HTTP more often than
 * anyone admits. `getRandomValues` survives where `randomUUID` does not, so the fallback ladder is: a
 * real UUID, a UUID assembled from random bytes, and only then the legacy `t-<millis>-<rand>` shape the
 * server still accepts for tickets queued before this helper existed.
 */
export function newClientRef(): string {
  const c = globalThis.crypto as Crypto | undefined;

  if (c && typeof c.randomUUID === 'function') {
    return c.randomUUID();
  }

  if (c && typeof c.getRandomValues === 'function') {
    const bytes = new Uint8Array(16);
    c.getRandomValues(bytes);
    // Version 4, variant 10 — the two nibbles that make random bytes a UUID rather than hex noise.
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');

    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
  }

  return `t-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
}
