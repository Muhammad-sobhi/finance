/**
 * The vault's cryptography, all in the browser (WebCrypto). A random AES-256-GCM vault key
 * encrypts every item. The server gets that key only wrapped by a key derived from the
 * owner's PIN (PBKDF2-SHA256) and/or, if the owner wants their heirs to be able to open the
 * vault (or chose no PIN), as a copy it stores encrypted like the rest of their data. Items
 * never leave the browser in clear. The unlocked key lives in memory only and is forgotten
 * after a few idle minutes or a reload.
 */

export const ITERATIONS = 600_000
const IDLE_LOCK_MS = 5 * 60 * 1000

export type Mode = 'pin_heir' | 'pin' | 'open'
export interface VaultKeyBox { salt: string | null; iterations: number | null; iv: string | null; wrapped_key: string | null; mode: Mode }
/** What the server stores for a mode: the PIN-wrapped key and/or the key copy. */
export type KeyBody = { mode: Mode; salt?: string; iterations?: number; iv?: string; wrapped_key?: string; escrow_key?: string }
export interface Sealed { iv: string; ciphertext: string }

const enc = new TextEncoder()
const dec = new TextDecoder()

export const b64 = (bytes: ArrayBuffer | Uint8Array) => {
  const u = bytes instanceof Uint8Array ? bytes : new Uint8Array(bytes)
  let s = ''
  for (let i = 0; i < u.length; i++) s += String.fromCharCode(u[i])
  return btoa(s)
}
export const unb64 = (s: string) => Uint8Array.from(atob(s), (c) => c.charCodeAt(0))
const random = (n: number) => crypto.getRandomValues(new Uint8Array(n))

async function pinKey(pin: string, salt: Uint8Array, iterations: number): Promise<CryptoKey> {
  const base = await crypto.subtle.importKey('raw', enc.encode(pin.normalize('NFKC')), 'PBKDF2', false, ['deriveKey'])
  return crypto.subtle.deriveKey({ name: 'PBKDF2', hash: 'SHA-256', salt: salt as BufferSource, iterations }, base, { name: 'AES-GCM', length: 256 }, false, ['encrypt', 'decrypt'])
}

async function wrap(raw: Uint8Array, pin: string) {
  const salt = random(16)
  const iv = random(12)
  const kek = await pinKey(pin, salt, ITERATIONS)
  const wrapped = await crypto.subtle.encrypt({ name: 'AES-GCM', iv: iv as BufferSource }, kek, raw as BufferSource)
  return { salt: b64(salt), iterations: ITERATIONS, iv: b64(iv), wrapped_key: b64(wrapped) }
}

/** Raw vault key bytes, or null when the PIN is wrong. */
async function unwrapRaw(box: VaultKeyBox, pin: string): Promise<Uint8Array | null> {
  if (!box.salt || !box.iv || !box.wrapped_key || !box.iterations) return null
  try {
    const kek = await pinKey(pin, unb64(box.salt), box.iterations)
    return new Uint8Array(await crypto.subtle.decrypt({ name: 'AES-GCM', iv: unb64(box.iv) as BufferSource }, kek, unb64(box.wrapped_key) as BufferSource))
  } catch {
    return null
  }
}

// Extractable so the owner can change the PIN/mode while unlocked; it still never leaves memory except as wrapped above.
const importItemKey = (raw: Uint8Array) => crypto.subtle.importKey('raw', raw as BufferSource, 'AES-GCM', true, ['encrypt', 'decrypt'])

// ---- The unlocked session (memory only) ----
let current: CryptoKey | null = null
let timer: number | undefined
const listeners = new Set<() => void>()

export const isUnlocked = () => current !== null
export function onLockChange(fn: () => void) { listeners.add(fn); return () => { listeners.delete(fn) } }
const notify = () => listeners.forEach((fn) => fn())

/** Any vault activity pushes the automatic lock back. */
export function touch() {
  window.clearTimeout(timer)
  if (current) timer = window.setTimeout(lock, IDLE_LOCK_MS)
}

export function lock() {
  current = null
  window.clearTimeout(timer)
  notify()
}

async function body(raw: Uint8Array, mode: Mode, pin: string | null): Promise<KeyBody> {
  const out: KeyBody = { mode }
  if (mode !== 'open') Object.assign(out, await wrap(raw, pin ?? ''))
  if (mode !== 'pin') out.escrow_key = b64(raw)
  return out
}

/** New vault: a fresh random key, stored as the mode asks. Unlocks it. */
export async function createVault(mode: Mode, pin: string | null): Promise<KeyBody> {
  const raw = random(32)
  const out = await body(raw, mode, pin)
  current = await importItemKey(raw)
  raw.fill(0)
  touch()
  notify()
  return out
}

/** Re-store the unlocked key under a new mode/PIN (items don't change). */
export async function rekey(mode: Mode, pin: string | null): Promise<KeyBody> {
  const raw = new Uint8Array(await crypto.subtle.exportKey('raw', key()))
  const out = await body(raw, mode, pin)
  raw.fill(0)
  return out
}

/** Open with the key copy the server released (no-PIN vault, or an heir after activation). */
export async function unlockWithCopy(copy: string) {
  const raw = unb64(copy)
  current = await importItemKey(raw)
  raw.fill(0)
  touch()
  notify()
}

export async function unlock(box: VaultKeyBox, pin: string): Promise<boolean> {
  const raw = await unwrapRaw(box, pin)
  if (!raw) return false
  current = await importItemKey(raw)
  raw.fill(0)
  touch()
  notify()
  return true
}

function key(): CryptoKey {
  if (!current) throw new Error('vault locked')
  touch()
  return current
}

export async function seal(data: unknown): Promise<Sealed> {
  const iv = random(12)
  const ct = await crypto.subtle.encrypt({ name: 'AES-GCM', iv: iv as BufferSource }, key(), enc.encode(JSON.stringify(data)))
  return { iv: b64(iv), ciphertext: b64(ct) }
}

export async function open<T>(s: Sealed): Promise<T> {
  const plain = await crypto.subtle.decrypt({ name: 'AES-GCM', iv: unb64(s.iv) as BufferSource }, key(), unb64(s.ciphertext) as BufferSource)
  return JSON.parse(dec.decode(plain)) as T
}

/** Rough strength check: length and variety. A short PIN can be guessed offline by whoever holds the database. */
export function pinIssue(pin: string): 'short' | 'weak' | null {
  if (pin.length < 8) return 'short'
  const kinds = [/[0-9]/, /[a-z]/, /[A-Z]/, /[^0-9a-zA-Z]/].filter((r) => r.test(pin)).length
  return kinds < 2 && pin.length < 12 ? 'weak' : null
}
