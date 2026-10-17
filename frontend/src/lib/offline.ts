import { useEffect, useState } from 'react'
import { ApiError, post } from './api'

/**
 * Working without internet: new transactions wait in an outbox on this device and are sent
 * when the connection is back. Each one carries a client_id, so the server saves it once
 * even if it is sent twice. Only additions are queued, never edits or deletions.
 *
 * Kept in localStorage (small, synchronous, same lifetime as the login token).
 */

export interface OutboxItem {
  client_id: string
  user_id: number
  body: Record<string, unknown>
  /** For the pending list: shown without a server round trip. */
  label: { amount: number; currency: string; category: string | null }
  queued_at: string
  /** Set when the server refused it (e.g. the account was deleted meanwhile). */
  error?: string
}

const OUTBOX_KEY = 'fin.outbox'
const CHANGED = 'fin:outbox'

const read = (): OutboxItem[] => {
  try { return JSON.parse(localStorage.getItem(OUTBOX_KEY) ?? '[]') as OutboxItem[] } catch { return [] }
}
const write = (items: OutboxItem[]) => {
  try { localStorage.setItem(OUTBOX_KEY, JSON.stringify(items)) } catch { /* storage full or blocked */ }
  window.dispatchEvent(new Event(CHANGED))
}

export const outbox = {
  list: (userId: number) => read().filter((i) => i.user_id === userId),
  add: (item: Omit<OutboxItem, 'queued_at'>) => write([...read(), { ...item, queued_at: new Date().toISOString() }]),
  remove: (clientId: string) => write(read().filter((i) => i.client_id !== clientId)),
  retry: (clientId: string) => write(read().map((i) => (i.client_id === clientId ? { ...i, error: undefined } : i))),
}

export const newClientId = (): string =>
  crypto.randomUUID?.() ?? '10000000-1000-4000-8000-100000000000'.replace(/[018]/g, (c) =>
    (Number(c) ^ (crypto.getRandomValues(new Uint8Array(1))[0] & (15 >> (Number(c) / 4)))).toString(16))

/** fetch() itself failed: no connection (as opposed to an answer from the server). */
export const isNetworkError = (err: unknown) => err instanceof TypeError || !navigator.onLine

let syncing = false

/**
 * Send waiting items one by one, oldest first. Stops at the first network or server problem
 * (tried again later); an item the server rejects stays with its error for the user to see.
 * Resolves to the number saved.
 */
export async function syncOutbox(userId: number): Promise<number> {
  if (syncing || !navigator.onLine) return 0
  syncing = true
  let saved = 0
  try {
    for (const item of outbox.list(userId)) {
      if (item.error) continue
      try {
        await post('/transactions', { ...item.body, client_id: item.client_id })
        outbox.remove(item.client_id)
        saved++
      } catch (err) {
        if (err instanceof ApiError && (err.status === 422 || err.status === 403)) {
          const msg = Object.values(err.fieldErrors)[0] ?? err.message
          write(read().map((i) => (i.client_id === item.client_id ? { ...i, error: msg } : i)))
          continue
        }
        break   // offline again, signed out, rate limit or server error: try later
      }
    }
  } finally {
    syncing = false
  }
  return saved
}

/** The browser's view of the connection, live. */
export function useOnline(): boolean {
  const [online, setOnline] = useState(() => navigator.onLine)
  useEffect(() => {
    const on = () => setOnline(true)
    const off = () => setOnline(false)
    window.addEventListener('online', on)
    window.addEventListener('offline', off)
    return () => { window.removeEventListener('online', on); window.removeEventListener('offline', off) }
  }, [])
  return online
}

/** This user's waiting items, live (also across tabs). */
export function useOutbox(userId: number | undefined): OutboxItem[] {
  const [items, setItems] = useState<OutboxItem[]>(() => (userId ? outbox.list(userId) : []))
  useEffect(() => {
    if (!userId) return
    const load = () => setItems(outbox.list(userId))
    load()
    const onStorage = (e: StorageEvent) => e.key === OUTBOX_KEY && load()
    window.addEventListener(CHANGED, load)
    window.addEventListener('storage', onStorage)
    return () => { window.removeEventListener(CHANGED, load); window.removeEventListener('storage', onStorage) }
  }, [userId])
  return items
}

/**
 * Last copy of a few lists the add forms need (accounts, categories, last used), so they open
 * offline. Callers pass only names, ids, currencies and colours — never balances or notes.
 */
const cacheKey = (userId: number, path: string) => `fin.cache.${userId}.${path}`

export const offlineCache = {
  get: <T>(userId: number, path: string): T | null => {
    try { const v = localStorage.getItem(cacheKey(userId, path)); return v ? (JSON.parse(v) as T) : null } catch { return null }
  },
  set: (userId: number, path: string, data: unknown) => {
    try { localStorage.setItem(cacheKey(userId, path), JSON.stringify(data)) } catch { /* storage full or blocked */ }
  },
  clear: () => {
    try {
      Object.keys(localStorage).filter((k) => k.startsWith('fin.cache.')).forEach((k) => localStorage.removeItem(k))
    } catch { /* blocked */ }
  },
}
