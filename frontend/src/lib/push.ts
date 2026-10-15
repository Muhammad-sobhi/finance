import { api, post } from './api'

export type PushState = 'unsupported' | 'denied' | 'off' | 'on'

export const pushSupported = () => 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window

export function registerServiceWorker() {
  if ('serviceWorker' in navigator) navigator.serviceWorker.register('/sw.js').catch(() => { /* no SW: push unavailable */ })
}

const keyBytes = (base64: string) => {
  const pad = '='.repeat((4 - (base64.length % 4)) % 4)
  const raw = atob((base64 + pad).replace(/-/g, '+').replace(/_/g, '/'))
  return Uint8Array.from(raw, (c) => c.charCodeAt(0))
}

export async function pushState(): Promise<PushState> {
  if (!pushSupported()) return 'unsupported'
  if (Notification.permission === 'denied') return 'denied'
  const reg = await navigator.serviceWorker.getRegistration()
  const sub = await reg?.pushManager.getSubscription()
  return sub ? 'on' : 'off'
}

/** Ask permission, subscribe this browser with the server's VAPID key, register it with the API. */
export async function enablePush(): Promise<PushState> {
  if (!pushSupported()) return 'unsupported'
  if ((await Notification.requestPermission()) !== 'granted') return 'denied'
  const reg = await navigator.serviceWorker.register('/sw.js')
  await navigator.serviceWorker.ready
  const { key } = await api<{ key: string | null }>('/push/key')
  if (!key) throw new Error('VAPID key missing on server')
  const sub = (await reg.pushManager.getSubscription())
    ?? (await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(key) }))
  const json = sub.toJSON()
  await post('/push/subscribe', {
    endpoint: json.endpoint, keys: json.keys,
    contentEncoding: (PushManager as unknown as { supportedContentEncodings?: string[] }).supportedContentEncodings?.[0] ?? 'aes128gcm',
  })
  return 'on'
}

export async function disablePush(): Promise<PushState> {
  const reg = await navigator.serviceWorker.getRegistration()
  const sub = await reg?.pushManager.getSubscription()
  if (sub) {
    await post('/push/unsubscribe', { endpoint: sub.endpoint }).catch(() => undefined)
    await sub.unsubscribe()
  }
  return 'off'
}
