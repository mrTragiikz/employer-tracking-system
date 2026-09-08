/* field/sw.js - Rajdoot Field service worker.
 *
 * Registered with scope = <base>/field/  (this file sits in field/). Works
 * whether the app is at a domain root (prabinsharma.com/field/) or in a
 * subfolder (localhost/try/field/) - every path below is derived from the
 * registration scope, never hard-coded.
 *
 * What it does, deliberately minimal:
 *   1. Pre-caches a small "shell" (CSS, shared JS, icons, the offline page)
 *      so repeat page loads skip re-downloading the styling - noticeably
 *      snappier on weak signal.
 *   2. Serves field/offline.html when a PAGE navigation fails with no network.
 *
 * What it deliberately does NOT do:
 *   - Never caches HTML pages or /api/ responses. Every page and every
 *     check-in / visit / checkout always goes to the network, so a worker
 *     never sees or submits stale data and a bad cached page can't trap the
 *     app on an old version.
 *   - No background sync / offline queue yet - a later, separate step.
 *
 * Updating: bump CACHE_VERSION on any change here or to a shell asset. The
 * old cache is dropped on activate. sw.js is served no-cache (root .htaccess)
 * so a new version is picked up on the next load.
 */

'use strict';

const CACHE_VERSION = 'rajdoot-field-v3';

/* <origin>/<base>/field/  - the exact scope this SW was registered with.
   Every cached URL and every lookup uses this absolute base, so cache.match()
   and cache.addAll() agree on the key (a path-only string resolves against
   the SW file's location, which is fine, but being explicit avoids surprises
   when the app is in a subfolder). */
const SCOPE = self.registration.scope.replace(/\/$/, '');   // e.g. https://host/try/field
const OFFLINE_URL = SCOPE + '/offline.html';

/* Small, stable, safe-to-cache assets. If any one 404s at install, the
   precache is skipped (a console warning) and the SW still activates - it
   just runs without a warm cache until the next successful install. */
const SHELL = [
  OFFLINE_URL,
  SCOPE + '/components/header/css/header.css',
  SCOPE + '/components/footer/css/footer.css',
  SCOPE + '/components/announcement/css/announcement.css',
  SCOPE + '/components/photo-compress.js',
  SCOPE + '/pwa.js',
  SCOPE + '/icons/icon-192.png',
  SCOPE + '/icons/icon-512.png',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_VERSION)
      .then((cache) => cache.addAll(SHELL))
      .catch((err) => { console.warn('[sw] shell precache failed:', err); })
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(
        keys.filter((k) => k !== CACHE_VERSION).map((k) => caches.delete(k))
      ))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;

  // Same-origin GET only. POST check-ins, cross-origin fonts/maps, etc. pass
  // straight through untouched.
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;

  // API traffic is always live.
  if (url.pathname.includes('/api/')) return;

  // PAGE navigations: network-first, fall back to the offline page.
  if (req.mode === 'navigate') {
    event.respondWith(
      fetch(req).catch(() =>
        caches.open(CACHE_VERSION)
          .then((c) => c.match(OFFLINE_URL))
          .then((m) => m || new Response(
            '<!doctype html><meta charset="utf-8"><title>Offline</title>'
            + '<body style="font-family:system-ui;text-align:center;padding:40px">'
            + '<h1>You’re offline</h1><p>Reconnect and try again.</p>',
            { headers: { 'Content-Type': 'text/html; charset=utf-8' }, status: 503 }
          ))
      )
    );
    return;
  }

  // Shell assets (css / js / icons): cache-first, then network, and refresh
  // the cached copy in the background while online.
  const isShellType = ['style', 'script', 'image', 'font'].includes(req.destination);
  if (isShellType) {
    event.respondWith(
      caches.open(CACHE_VERSION).then((cache) =>
        cache.match(req, { ignoreSearch: true }).then((cached) => {
          const network = fetch(req).then((res) => {
            if (res && res.ok && res.type === 'basic') {
              cache.put(req, res.clone());
            }
            return res;
          }).catch(() => cached);
          return cached || network;
        })
      )
    );
  }
  // everything else: default browser handling
});
