/* AgriMatch PH service worker — offline shell + resilient reads.
 *
 *  - Navigations: network-first, falling back to the cached app shell so the
 *    PWA opens in low-signal rural areas.
 *  - Built assets (/assets/*, icons, fonts): cache-first (filenames are hashed).
 *  - Public GET API reads (lookups, listings, price index): stale-while-revalidate
 *    so farmers can still browse the last-known market offline.
 *  - Authenticated / mutating API calls are never cached; the app's IndexedDB
 *    outbox handles offline writes. A Background Sync event asks open tabs to
 *    flush that outbox.
 */
const VERSION = 'agrimatch-v5';
const SHELL_CACHE = `${VERSION}-shell`;
const ASSET_CACHE = `${VERSION}-assets`;
const API_CACHE = `${VERSION}-api`;
const SHELL = ['/', '/index.html', '/manifest.webmanifest', '/icons/icon-192.png', '/icons/icon-512.png'];

const CACHEABLE_API = [
  /^\/api\/v1\/lookups\//,
  /^\/api\/v1\/harvest-listings(\?|$)/,
  /^\/api\/v1\/price-index\//,
  /^\/api\/v1\/fuel-prices/,
  /^\/api\/v1\/coop-pools/,
];

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(SHELL_CACHE).then((c) => c.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches
      .keys()
      .then((keys) => Promise.all(keys.filter((k) => !k.startsWith(VERSION)).map((k) => caches.delete(k))))
      .then(() => self.clients.claim()),
  );
});

async function networkFirstShell(request) {
  try {
    const fresh = await fetch(request);
    const cache = await caches.open(SHELL_CACHE);
    cache.put('/index.html', fresh.clone());
    return fresh;
  } catch {
    return (await caches.match('/index.html')) || (await caches.match('/')) || Response.error();
  }
}

async function cacheFirst(request) {
  const cached = await caches.match(request);
  if (cached) return cached;
  const fresh = await fetch(request);
  if (fresh.ok) (await caches.open(ASSET_CACHE)).put(request, fresh.clone());
  return fresh;
}

async function staleWhileRevalidate(request) {
  const cache = await caches.open(API_CACHE);
  const cached = await cache.match(request);
  const network = fetch(request)
    .then((res) => {
      if (res.ok) cache.put(request, res.clone());
      return res;
    })
    .catch(() => null);
  return cached || (await network) || new Response(JSON.stringify({ message: 'Offline and not cached yet.' }), {
    status: 503,
    headers: { 'Content-Type': 'application/json' },
  });
}

self.addEventListener('fetch', (event) => {
  const { request } = event;
  if (request.method !== 'GET') return;
  const url = new URL(request.url);
  if (url.origin !== self.location.origin) {
    if (url.hostname.endsWith('fonts.gstatic.com') || url.hostname.endsWith('fonts.googleapis.com')) {
      event.respondWith(cacheFirst(request));
    }
    return;
  }

  if (request.mode === 'navigate') {
    event.respondWith(networkFirstShell(request));
  } else if (url.pathname.startsWith('/assets/') || url.pathname.startsWith('/icons/')) {
    event.respondWith(cacheFirst(request));
  } else if (CACHEABLE_API.some((re) => re.test(url.pathname + url.search))) {
    event.respondWith(staleWhileRevalidate(request));
  }
  // Everything else (auth, notifications, /sanctum) goes straight to the network.
});

// Background Sync: wake any open client to replay its IndexedDB outbox
// (the outbox needs the page's session cookie + CSRF token to send).
self.addEventListener('sync', (event) => {
  if (event.tag === 'agrimatch-outbox') {
    event.waitUntil(
      self.clients.matchAll({ includeUncontrolled: true }).then((clients) => clients.forEach((c) => c.postMessage({ type: 'agrimatch:flush-outbox' }))),
    );
  }
});
