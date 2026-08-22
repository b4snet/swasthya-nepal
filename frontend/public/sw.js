/**
 * Swasthya PWA Service Worker — Phase 120 Enhanced
 *
 * Provides offline caching for static assets and navigation.
 * Supports background sync for offline clinical actions.
 * Does NOT cache sensitive clinical data — only shell assets.
 * API calls are always fresh; the SW only caches the app shell.
 */

const CACHE_NAME = 'swasthya-shell-v2';
const SHELL_ASSETS = [
  '/',
  '/index.html',
  '/manifest.json',
];

/* ── Install: pre-cache the app shell ── */
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(SHELL_ASSETS)).then(() => self.skipWaiting()),
  );
});

/* ── Activate: clean old caches ── */
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches
      .keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k))))
      .then(() => self.clients.claim()),
  );
});

/* ── Fetch: network-first for API, cache-first for static assets ── */
self.addEventListener('fetch', (event) => {
  const { request } = event;
  const url = new URL(request.url);

  // Never cache API or auth requests — always go to network
  if (url.pathname.startsWith('/api/') || url.pathname.startsWith('/auth/')) {
    event.respondWith(fetch(request));
    return;
  }

  // For navigation and static assets: try network, fall back to cache
  event.respondWith(
    fetch(request)
      .then((response) => {
        // Only cache successful same-origin responses
        if (response.ok && response.type === 'basic') {
          const clone = response.clone();
          caches.open(CACHE_NAME).then((cache) => cache.put(request, clone));
        }
        return response;
      })
      .catch(() => caches.match(request)),
  );
});

/* ── Background Sync: replay offline actions when connectivity returns ── */
self.addEventListener('sync', (event) => {
  if (event.tag === 'swasthya-offline-sync') {
    event.waitUntil(replayOfflineActions());
  }
});

async function replayOfflineActions() {
  // Notify all clients that sync is starting
  const clients = await self.clients.matchAll();
  for (const client of clients) {
    client.postMessage({ type: 'SYNC_START' });
  }

  try {
    // The IndexedDB replay is handled by the useOfflineQueue hook in the app.
    // The service worker's role is to trigger the sync event and notify clients.
    for (const client of clients) {
      client.postMessage({ type: 'SYNC_COMPLETE' });
    }
  } catch (err) {
    for (const client of clients) {
      client.postMessage({ type: 'SYNC_FAILED', error: err?.message ?? 'Unknown error' });
    }
  }
}

/* ── Push notifications (placeholder) ── */
self.addEventListener('push', (event) => {
  if (!event.data) return;
  const data = event.data.json();

  const options = {
    body: data.body ?? 'New notification from Swasthya',
    icon: '/icons/icon-192.png',
    badge: '/icons/icon-192.png',
    vibrate: [200, 100, 200],
    tag: data.tag ?? 'swasthya-notification',
    renotify: true,
    data: { url: data.url ?? '/' },
  };

  event.waitUntil(
    self.registration.showNotification(data.title ?? 'Swasthya', options),
  );
});

/* ── Notification click ── */
self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const url = event.notification.data?.url ?? '/';

  event.waitUntil(
    self.clients.matchAll({ type: 'window' }).then((clients) => {
      // Focus existing window if possible
      for (const client of clients) {
        if (client.url.includes(url) && 'focus' in client) {
          return client.focus();
        }
      }
      // Otherwise open new window
      return self.clients.openWindow(url);
    }),
  );
});
