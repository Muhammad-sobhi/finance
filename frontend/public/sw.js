// Service worker: offline fallback page, web-push alerts, and opening the right page when tapped.
// Financial data is never cached here: only the offline page and its icon.
const CACHE = 'dinar-offline-v1'
const OFFLINE = ['/offline.html', '/icon-192.png']

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(CACHE).then((c) => c.addAll(OFFLINE)).then(() => self.skipWaiting()))
})
self.addEventListener('activate', (e) => e.waitUntil((async () => {
  for (const key of await caches.keys()) if (key !== CACHE) await caches.delete(key)
  await self.clients.claim()
})()))

// Page loads go to the network; if it's unreachable, show the offline page. API calls are untouched.
self.addEventListener('fetch', (event) => {
  const req = event.request
  if (req.mode === 'navigate') {
    event.respondWith(fetch(req).catch(() => caches.match('/offline.html')))
  } else if (OFFLINE.includes(new URL(req.url).pathname)) {
    event.respondWith(caches.match(req).then((r) => r || fetch(req)))
  }
})

self.addEventListener('push', (event) => {
  let data = {}
  try { data = event.data ? event.data.json() : {} } catch { data = { title: event.data && event.data.text() } }
  const title = data.title || 'Dinar'
  event.waitUntil(self.registration.showNotification(title, {
    body: data.body || '',
    icon: data.icon || '/icon-192.png',
    badge: '/icon-192.png',
    tag: data.tag,
    data: data.data || {},
  }))
})

self.addEventListener('notificationclick', (event) => {
  event.notification.close()
  const url = new URL((event.notification.data && event.notification.data.url) || '/', self.location.origin).href
  event.waitUntil((async () => {
    const wins = await self.clients.matchAll({ type: 'window', includeUncontrolled: true })
    for (const w of wins) {
      if (new URL(w.url).origin === self.location.origin) {
        await w.focus()
        return w.navigate(url)
      }
    }
    return self.clients.openWindow(url)
  })())
})
