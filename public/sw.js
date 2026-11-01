// Safara app service worker.
//
// Makes the app installable and keeps its shell (styles, icons, an offline page) available without
// a connection. The chat itself is live, private data, so it is NEVER cached: only the static
// files matched by STATIC are stored; every other request goes straight to the network.
const VERSION = 'safara-v1';
const SHELL = ['/offline.html', '/css/app.css', '/brand/icon.svg', '/brand/icon-192.png', '/brand/icon-512.png'];
const STATIC = /^\/(css\/|brand\/|manifest\.webmanifest$|offline\.html$)/;

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(VERSION).then((cache) => cache.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== VERSION).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return; // fonts and other sites: leave to the browser

  // Pages: always try the network (they carry the session and live data); show the offline page if it is down.
  if (req.mode === 'navigate') {
    event.respondWith(fetch(req).catch(() => caches.match('/offline.html')));
    return;
  }

  // Static files: answer from cache at once, refresh the copy in the background.
  if (STATIC.test(url.pathname)) {
    event.respondWith(
      caches.open(VERSION).then(async (cache) => {
        const cached = await cache.match(req, { ignoreSearch: true });
        const fresh = fetch(req).then((res) => {
          if (res.ok) cache.put(url.pathname, res.clone()); // keyed without ?v=… so each file is stored once
          return res;
        }).catch(() => cached);
        return cached || fresh;
      })
    );
  }
  // Anything else (/app/*, /pay/*, documents, the desk): not handled here, so never cached.
});
