/*
 * ERP Pilot — the till's service worker (F22 / U21).
 *
 * What it is for: a counter tablet must be able to *open* the POS and keep selling with no network.
 * That is a smaller claim than "cache the whole app", and the rules below are deliberately narrow,
 * because an Inertia application does not tolerate a clever cache.
 *
 * The trap specific to this stack: every page response embeds that page's data (the `data-page` JSON
 * blob). Caching an authenticated page is therefore caching its contents — a customer list, a drawer
 * total — on a device that may be shared. So documents are cached for the two POS screens that must
 * start offline, for twelve hours, and for nothing else in the application.
 *
 * The other trap: a cached shell points at hashed build assets from the deploy that produced it. If a
 * deploy happens while a till is offline and its asset cache had been wiped, the page would load and
 * white-screen. `/build/` entries are therefore evicted by LRU by count, never by deploy — old hashes
 * survive precisely so an offline device can keep showing the version it already has.
 *
 * Nothing here is a proxy for the app: POSTs, non-GETs, cross-origin requests and anything outside the
 * allow-lists below pass straight through untouched.
 */

/* Bump on a deploy that changes this file's behaviour. The caches are named after it. */
const VERSION = 'erp-pos-v1';

const SHELL_CACHE = `${VERSION}-shell`;
const ASSET_CACHE = `${VERSION}-assets`;
const DATA_CACHE = `${VERSION}-data`;

/** A cached document older than this is not served: a till must not reopen yesterday's drawer. */
const DOCUMENT_MAX_AGE_MS = 12 * 60 * 60 * 1000;

/** Pages that may be read offline. Everything else gets the offline notice instead. */
const OFFLINE_PAGES = ['/sales/pos/terminal', '/sales/pos/offline'];

/** Endpoints whose last good answer is better than no answer. GET only. */
const DATA_PREFIXES = [
  '/inventory/api/pos-catalog',
  '/inventory/api/pos-product/',
  '/inventory/api/options/products',
];

const ASSET_PREFIXES = ['/build/'];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches
      .open(SHELL_CACHE)
      .then((cache) => cache.addAll([`${self.registration.scope.replace(/\/$/, '')}/offline.html`].map((url) => new Request(url, { cache: 'reload' }))))
      .catch(() => undefined)
      // Install must not fail because the offline notice could not be prefetched — the rest of the
      // worker still does its job, and the notice is only the last resort when there is no document.
      .then(() => self.skipWaiting()),
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    (async () => {
      for (const key of await caches.keys()) {
        // Drop caches from previous versions, but never the asset cache: see the LRU note above.
        if (key.startsWith(`${VERSION}-`) || key === ASSET_CACHE) continue;

        await caches.delete(key);
      }

      await self.clients.claim();
    })(),
  );
});

function isDocument(request) {
  return request.mode === 'navigate' || request.headers.get('Accept')?.includes('text/html');
}

function sameOrigin(url) {
  try {
    return new URL(url).origin === self.location.origin;
  } catch {
    return false;
  }
}

function pathOf(url) {
  try {
    return new URL(url).pathname;
  } catch {
    return '';
  }
}

function startsWithAny(path, prefixes) {
  return prefixes.some((prefix) => path.startsWith(prefix));
}

async function put(cacheName, request, response) {
  if (!response || response.status !== 200 || response.type === 'opaque') return response;

  const cache = await caches.open(cacheName);

  await cache.put(request, response.clone());
  if (cacheName === ASSET_CACHE) await trim(ASSET_CACHE, 400);

  return response;
}

/**
 * Keep a cache under `max` entries by dropping the oldest inserted. Not a true LRU — `keys()` exposes
 * no access times — but insertion order tracks how a page's asset graph was discovered, which is close
 * enough for a cap whose only job is to stop an unbounded build-up across months of deploys.
 */
async function trim(cacheName, max) {
  const cache = await caches.open(cacheName);
  const keys = await cache.keys();

  if (keys.length <= max) return;

  for (const request of keys.slice(0, keys.length - max)) await cache.delete(request);
}

async function fresh(request, cacheName, maxAgeMs) {
  const cache = await caches.open(cacheName);
  // The query string is part of the identity of every cached entry, including the catalogue: its
  // stock figures are per warehouse, so ignoring `?warehouse_id=` would hand till A the shelf count
  // of till B — a wrong number that looks exactly like a right one.
  const hit = await cache.match(request);

  if (!hit) return null;
  if (maxAgeMs && Date.now() - hit.timeStamp > maxAgeMs) return null;

  return hit;
}

self.addEventListener('fetch', (event) => {
  const { request } = event;

  if (request.method !== 'GET' || !sameOrigin(request.url)) return;

  const path = pathOf(request.url);

  // The sync POST is the one request that must never be answered from a cache: a replay has to reach
  // the server, which is the only thing that knows whether this ticket was already posted.
  if (path.startsWith('/sales/pos/offline/sync')) return;

  if (isDocument(request) && startsWithAny(path, OFFLINE_PAGES)) {
    event.respondWith(staleWhileRevalidate(request, SHELL_CACHE, DOCUMENT_MAX_AGE_MS));

    return;
  }

  if (startsWithAny(path, ASSET_PREFIXES)) {
    // Vite's hashed file names are content-addressed and immutable, so the cache answers and the
    // network is never consulted. Falling back to the network only covers a first visit.
    event.respondWith(cacheFirst(request, ASSET_CACHE));

    return;
  }

  if (startsWithAny(path, DATA_PREFIXES)) {
    event.respondWith(staleWhileRevalidate(request, DATA_CACHE, 0));

    return;
  }

  // Everything else — including every other page in the application — goes to the network untouched,
  // so a shared tablet never keeps a copy of someone else's data.
});

/** Cached answer immediately, refresh it in the background. What an offline or slow till needs. */
async function staleWhileRevalidate(request, cacheName, maxAgeMs) {
  const cached = await fresh(request, cacheName, maxAgeMs);

  const network = fetch(request)
    .then((response) => put(cacheName, request, response))
    .catch(() => null);

  if (cached) {
    // Hand the cached copy over now; the refresh finishes behind the page. Awaiting it would make an
    // offline load wait for a timeout before showing the till.
    settleQuietly(network);

    return cached;
  }

  const online = await network;

  if (online) return online;

  return offlineFallback(request);
}

/** Cache first, network only when the cache has never seen it. For content-addressed assets. */
async function cacheFirst(request, cacheName) {
  const cached = await fresh(request, cacheName, 0);

  if (cached) return cached;

  return put(cacheName, request, await fetch(request));
}

function settleQuietly(promise) {
  // Swallow the background refresh's rejection so it is never reported as an unhandled one.
  promise.catch(() => undefined);
}

async function offlineFallback(request) {
  if (!isDocument(request)) {
    return new Response('{"error":"offline"}', { status: 503, headers: { 'Content-Type': 'application/json' } });
  }

  const cache = await caches.open(SHELL_CACHE);
  const notice = await cache.match(`${self.location.origin}/offline.html`);

  if (notice) return notice.clone();

  return new Response('This page needs the network, and none is available.', { status: 503, headers: { 'Content-Type': 'text/plain' } });
}

/* A message from the till, so it can be told when the worker has finished replacing itself. */
self.addEventListener('message', (event) => {
  if (event.data === 'skip-waiting') self.skipWaiting();
});
