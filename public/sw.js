// Safara app service worker. The chat is live data, so nothing is cached;
// this exists so the app can be installed to the home screen.
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()));
self.addEventListener('fetch', () => {});
