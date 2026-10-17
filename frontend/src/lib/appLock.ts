/**
 * App lock for this device: a PIN (every phone) and optionally the phone's own fingerprint /
 * face unlock (WebAuthn platform authenticator — the phone decides which one it uses).
 *
 * Everything stays on the device and works offline: the PIN is kept only as a salted
 * PBKDF2-SHA256 hash; for biometrics the device keeps a credential that only opens after the
 * phone verifies its owner. It guards the open app against someone holding the unlocked phone;
 * it is not server-side security (the sign-in token is what the server checks).
 */

export interface LockConfig {
  user_id: number
  salt: string
  hash: string
  /** Digits in the PIN, so it submits on the last digit. */
  length: number
  /** WebAuthn credential id (base64url) when fingerprint / face is on. */
  credential: string | null
  /** Lock again after this many seconds in the background (0 = every time). */
  after: number
  fails: number
}

const KEY = 'fin.lock'
/** Window event after the lock is set up, changed or removed. */
export const LOCK_CHANGED = 'fin:lock'
export const MAX_FAILS = 5
const ITERATIONS = 210000

const b64 = (buf: ArrayBuffer | Uint8Array) => {
  const bytes = buf instanceof Uint8Array ? buf : new Uint8Array(buf)
  return btoa(String.fromCharCode(...bytes)).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
}
const unb64 = (s: string) => Uint8Array.from(atob(s.replace(/-/g, '+').replace(/_/g, '/')), (c) => c.charCodeAt(0))
const random = (n: number) => crypto.getRandomValues(new Uint8Array(n))

async function derive(pin: string, salt: Uint8Array): Promise<string> {
  const key = await crypto.subtle.importKey('raw', new TextEncoder().encode(pin), 'PBKDF2', false, ['deriveBits'])
  return b64(await crypto.subtle.deriveBits({ name: 'PBKDF2', hash: 'SHA-256', salt, iterations: ITERATIONS }, key, 256))
}

export const appLock = {
  get(userId: number | undefined): LockConfig | null {
    if (!userId) return null
    try {
      const c = JSON.parse(localStorage.getItem(KEY) ?? 'null') as LockConfig | null
      return c && c.user_id === userId ? c : null
    } catch {
      return null
    }
  },
  save(c: LockConfig) {
    try { localStorage.setItem(KEY, JSON.stringify(c)) } catch { /* blocked storage: lock can't be kept */ }
    window.dispatchEvent(new Event(LOCK_CHANGED))
  },
  clear() {
    try { localStorage.removeItem(KEY) } catch { /* blocked */ }
    window.dispatchEvent(new Event(LOCK_CHANGED))
  },

  async create(userId: number, pin: string, after: number, credential: string | null): Promise<LockConfig> {
    const salt = random(16)
    const c: LockConfig = { user_id: userId, salt: b64(salt), hash: await derive(pin, salt), length: pin.length, credential, after, fails: 0 }
    appLock.save(c)
    return c
  },

  /** Right PIN → resets the failure count. Wrong → counts it; the caller signs out at MAX_FAILS. */
  async checkPin(c: LockConfig, pin: string): Promise<{ ok: boolean; fails: number }> {
    const ok = (await derive(pin, unb64(c.salt))) === c.hash
    const fails = ok ? 0 : c.fails + 1
    appLock.save({ ...c, fails })
    return { ok, fails }
  },
}

/** 4–8 digits, not all the same and not a straight run (1234, 9876). */
export function pinIssue(pin: string): 'format' | 'weak' | null {
  if (!/^\d{4,8}$/.test(pin)) return 'format'
  const d = [...pin].map(Number)
  const step = d[1] - d[0]
  if (Math.abs(step) <= 1 && d.every((x, i) => i === 0 || x - d[i - 1] === step)) return 'weak'
  return null
}

/** Does this phone/browser have fingerprint or face unlock that a web app can use? */
export async function biometricAvailable(): Promise<boolean> {
  try {
    return !!window.PublicKeyCredential && await PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable()
  } catch {
    return false
  }
}

/** Register this device's fingerprint / face for Dinar. Resolves to the credential id. */
export async function registerBiometric(user: { email: string; name: string }): Promise<string> {
  const cred = await navigator.credentials.create({
    publicKey: {
      challenge: random(32),
      rp: { name: 'Dinar', id: location.hostname },
      user: { id: random(16), name: user.email, displayName: user.name },
      pubKeyCredParams: [{ type: 'public-key', alg: -7 }, { type: 'public-key', alg: -257 }],
      authenticatorSelection: { authenticatorAttachment: 'platform', userVerification: 'required', residentKey: 'discouraged' },
      timeout: 60000,
      attestation: 'none',
    },
  }) as PublicKeyCredential | null
  if (!cred) throw new Error('cancelled')
  return b64(cred.rawId)
}

/** Ask the phone to verify its owner (fingerprint / face). True only if it confirmed the user. */
export async function verifyBiometric(credential: string): Promise<boolean> {
  const res = await navigator.credentials.get({
    publicKey: {
      challenge: random(32),
      allowCredentials: [{ type: 'public-key', id: unb64(credential) }],
      userVerification: 'required',
      timeout: 60000,
    },
  }) as PublicKeyCredential | null
  if (!res) return false
  // authenticatorData byte 32 holds the flags; 0x04 = the user was verified (not just present).
  const data = new Uint8Array((res.response as AuthenticatorAssertionResponse).authenticatorData)
  return (data[32] & 0x04) !== 0
}
