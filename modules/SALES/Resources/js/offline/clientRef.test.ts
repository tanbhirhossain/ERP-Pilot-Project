/**
 * `newClientRef` — the one minter behind the live till and the offline queue.
 *
 * The bug this pins: both call sites inlined "a UUID, unless the browser cannot make one", and the
 * fallback half minted `t-<millis>-<rand>` references the server's `uuid` rule then refused — a sale
 * that died with "The client ref field must be a valid UUID" on every till served over plain HTTP.
 * The minter ladder (real UUID, UUID from random bytes, legacy shape) is covered rung by rung.
 */

import { afterEach, describe, expect, it, vi } from 'vitest';

import { newClientRef } from './clientRef';

const UUID_V4 = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;

describe('newClientRef', () => {
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('mints a UUID when the platform can', () => {
    expect(newClientRef()).toMatch(UUID_V4);
  });

  it('never mints the same reference twice', () => {
    expect(newClientRef()).not.toBe(newClientRef());
  });

  it('assembles a UUID from random bytes where randomUUID is missing', () => {
    // A till over plain HTTP: `getRandomValues` survives where `randomUUID` does not.
    vi.stubGlobal('crypto', {
      getRandomValues: (arr: Uint8Array): Uint8Array => {
        for (let i = 0; i < arr.length; i++) arr[i] = i * 17;

        return arr;
      },
    });

    expect(newClientRef()).toMatch(UUID_V4);
  });

  it('falls back to the short legacy shape with no crypto at all', () => {
    vi.stubGlobal('crypto', undefined);

    const ref = newClientRef();

    expect(ref).toMatch(/^t-\d+-[0-9a-z]+$/);
    expect(ref.length).toBeLessThanOrEqual(36);
  });
});
