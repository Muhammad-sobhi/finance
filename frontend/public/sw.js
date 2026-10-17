// Service worker: the app opens offline, web-push alerts, and opening the right page when tapped.
// Only the app's own files are cached (HTML shell, JS, CSS, fonts, icons) — never /api, so
// financial data is never stored here. Offline entries wait in the app's outbox (src/lib/offline.ts).
const SHELL = 'dinar-shell-v2'
const ASSETS = 'dinar-assets-v2'
const PRECACHE = ['/', '/offline.html', '/icon-192.png', '/manifest.webmanifest', '/boot.js']
const MAX_ASSETS = 150

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(SHELL).then((c) => c.addAll(PRECACHE)).then(() => self.skipWaiting()))
})
self.addEventListener('activate', (e) => e.waitUntil((async () => {
  for (const key of await caches.keys()) if (key !== SHELL && key !== ASSETS) await caches.delete(key)
  await self.clients.claim()
})()))

const sameOrigin = (url) => url.origin === self.location.origin

// Hashed build files never change: serve from cache, fetch and keep on first use.
async function asset(req) {
  const hit = await caches.match(req)
  if (hit) return hit
  const res = await fetch(req)
  if (res.ok) {
    const cache = await caches.open(ASSETS)
    await cache.put(req, res.clone())
    const keys = await cache.keys()   // old builds' files: drop the oldest
    for (const k of keys.slice(0, Math.max(0, keys.length - MAX_ASSETS))) await cache.delete(k)
  }
  return res
}

// Pages: the network first (always the latest build); offline, the saved app shell.
async function page(req) {
  try {
    const res = await fetch(req)
    if (res.ok) (await caches.open(SHELL)).put('/', res.clone())
    return res
  } catch {
    return (await caches.match('/')) || (await caches.match('/offline.html'))
  }
}

self.addEventListener('fetch', (event) => {
  const req = event.request
  const url = new URL(req.url)
  if (req.method !== 'GET' || !sameOrigin(url) || url.pathname.startsWith('/api/') || url.pathname === '/up') return
  if (req.mode === 'navigate') event.respondWith(page(req))
  else if (url.pathname.startsWith('/assets/')) event.respondWith(asset(req))
  else if (PRECACHE.includes(url.pathname)) {
    event.respondWith(fetch(req).catch(() => caches.match(req)))
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
