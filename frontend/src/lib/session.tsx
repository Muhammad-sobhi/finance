import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'
import { api, ApiError, patch, post, token, UNAUTHORIZED_EVENT } from './api'
import { I18nCtx, makeI18n, type Lang } from '../i18n'
import type { ListMode, Theme, User } from './types'

const THEME_COLORS: Record<Theme, string> = { ledger: '#f4efe3', swiss: '#ffffff', geo: '#1e3a5f', vault: '#1c1b19' }

const stored = <T extends string>(key: string, fallback: T, allowed: readonly T[]): T => {
  try {
    const v = localStorage.getItem(key) as T | null
    return v && allowed.includes(v) ? v : fallback
  } catch {
    return fallback
  }
}
const store = (key: string, v: string) => { try { localStorage.setItem(key, v) } catch { /* ignore */ } }

interface Session {
  user: User | null
  booting: boolean
  theme: Theme
  lang: Lang
  listMode: ListMode
  readOnly: boolean
  setTheme: (t: Theme) => void
  setLang: (l: Lang) => void
  setListMode: (m: ListMode) => void
  login: (email: string, password: string) => Promise<void>
  logout: () => Promise<void>
  /** Bumped after any write so every mounted view refetches. */
  revision: number
  refresh: () => void
  toast: (msg: string) => void
}

const Ctx = createContext<Session | null>(null)

export function useSession(): Session {
  const s = useContext(Ctx)
  if (!s) throw new Error('useSession outside provider')
  return s
}

export function SessionProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null)
  const [booting, setBooting] = useState(() => !!token.get())
  const [theme, setThemeState] = useState<Theme>(() => stored('fin.theme', 'ledger', ['ledger', 'swiss', 'geo', 'vault']))
  const [lang, setLangState] = useState<Lang>(() => stored('fin.lang', 'ar', ['ar', 'en']))
  const [listMode, setListModeState] = useState<ListMode>(() => stored('fin.list', 'table', ['table', 'cards']))
  const [revision, setRevision] = useState(0)
  const [toastMsg, setToastMsg] = useState<string | null>(null)

  const applyUser = useCallback((u: User) => {
    setUser(u)
    setThemeState(u.theme); store('fin.theme', u.theme)
    setLangState(u.locale); store('fin.lang', u.locale)
    setListModeState(u.list_mode); store('fin.list', u.list_mode)
  }, [])

  useEffect(() => {
    if (!token.get()) return
    // Retry transient failures (rate limit, network); only a 401 means the token is gone.
    const boot = (attempt: number): Promise<void> => api<User>('/auth/me').then(applyUser).catch((err) => {
      if (err instanceof ApiError && err.status === 401) return token.set(null)
      if (attempt < 3) return new Promise<void>((r) => setTimeout(r, 1000 * (attempt + 1))).then(() => boot(attempt + 1))
    })
    boot(0).finally(() => setBooting(false))
  }, [applyUser])

  useEffect(() => {
    const onUnauth = () => setUser(null)
    window.addEventListener(UNAUTHORIZED_EVENT, onUnauth)
    return () => window.removeEventListener(UNAUTHORIZED_EVENT, onUnauth)
  }, [])

  useEffect(() => {
    const h = document.documentElement
    h.dataset.theme = theme
    h.lang = lang
    h.dir = lang === 'ar' ? 'rtl' : 'ltr'
    document.querySelector('meta[name="theme-color"]')?.setAttribute('content', THEME_COLORS[theme])
  }, [theme, lang])

  useEffect(() => {
    if (!toastMsg) return
    const id = setTimeout(() => setToastMsg(null), 2200)
    return () => clearTimeout(id)
  }, [toastMsg])

  // Preferences apply instantly and persist per user on the server.
  const savePref = useCallback((body: Partial<Pick<User, 'theme' | 'locale' | 'list_mode'>>) => {
    if (user) patch<User>('/auth/me', body).catch(() => { /* local copy still applies */ })
  }, [user])

  const value = useMemo<Session>(() => ({
    user, booting, theme, lang, listMode,
    readOnly: !!user?.read_only,
    setTheme: (t) => { setThemeState(t); store('fin.theme', t); savePref({ theme: t }) },
    setLang: (l) => { setLangState(l); store('fin.lang', l); savePref({ locale: l }) },
    setListMode: (m) => { setListModeState(m); store('fin.list', m); savePref({ list_mode: m }) },
    login: async (email, password) => {
      const res = await post<{ token: string; user: User }>('/auth/login', { email, password, device_name: navigator.userAgent.slice(0, 90) })
      token.set(res.token)
      applyUser(res.user)
    },
    logout: async () => {
      await post('/auth/logout', {}).catch(() => undefined)
      token.set(null)
      setUser(null)
    },
    revision,
    refresh: () => setRevision((r) => r + 1),
    toast: setToastMsg,
  }), [user, booting, theme, lang, listMode, revision, savePref, applyUser])

  const i18n = useMemo(() => makeI18n(lang), [lang])

  return (
    <Ctx.Provider value={value}>
      <I18nCtx.Provider value={i18n}>
        {children}
        {toastMsg && <div className="toast" role="status">{toastMsg}</div>}
      </I18nCtx.Provider>
    </Ctx.Provider>
  )
}
