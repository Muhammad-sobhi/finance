const BASE = (import.meta.env.VITE_API_URL as string | undefined) ?? '/api/v1'
const TOKEN_KEY = 'fin.token'

export class ApiError extends Error {
  status: number
  data: { message?: string; code?: string; errors?: Record<string, string[]> }
  constructor(status: number, data: ApiError['data']) {
    super(data?.message ?? `HTTP ${status}`)
    this.status = status
    this.data = data ?? {}
  }
  /** First validation message per field. */
  get fieldErrors(): Record<string, string> {
    const out: Record<string, string> = {}
    for (const [k, v] of Object.entries(this.data.errors ?? {})) out[k] = v[0]
    return out
  }
}

export const token = {
  get: () => { try { return localStorage.getItem(TOKEN_KEY) } catch { return null } },
  set: (t: string | null) => { try { if (t) localStorage.setItem(TOKEN_KEY, t); else localStorage.removeItem(TOKEN_KEY) } catch { /* private mode */ } },
}

/** Fired when the server rejects our token so the app can return to the login screen. */
export const UNAUTHORIZED_EVENT = 'fin:unauthorized'

export async function api<T = unknown>(path: string, opts: { method?: string; body?: unknown } = {}): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json' }
  const t = token.get()
  if (t) headers.Authorization = `Bearer ${t}`
  if (opts.body !== undefined) headers['Content-Type'] = 'application/json'

  const res = await fetch(BASE + path, {
    method: opts.method ?? 'GET',
    headers,
    body: opts.body !== undefined ? JSON.stringify(opts.body) : undefined,
  })
  if (res.status === 204) return undefined as T
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    if (res.status === 401 && t) {
      token.set(null)
      window.dispatchEvent(new Event(UNAUTHORIZED_EVENT))
    }
    throw new ApiError(res.status, data)
  }
  return data as T
}

export const post = <T>(path: string, body: unknown) => api<T>(path, { method: 'POST', body })
export const patch = <T>(path: string, body: unknown) => api<T>(path, { method: 'PATCH', body })
export const put = <T>(path: string, body: unknown) => api<T>(path, { method: 'PUT', body })
export const del = (path: string) => api<void>(path, { method: 'DELETE' })
