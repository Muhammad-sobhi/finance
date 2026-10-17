import { Suspense, useEffect, useState, type CSSProperties } from 'react'
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom'
import { useI18n, type Key } from '../i18n'
import { patch } from '../lib/api'
import { useInstall } from '../lib/install'
import { useSession } from '../lib/session'
import type { Theme } from '../lib/types'
import { Bell } from './Bell'
import { select } from './fields'
import { FormModal } from './Modal'
import { useForm } from '../hooks/useForm'
import { Icon } from './Icon'
import { OfflineBar } from './OfflineBar'
import { QuickAdd } from './QuickAdd'
import { TransactionForm } from './TransactionForm'

export const NAV: { path: string; key: Key }[] = [
  { path: '/', key: 'home' },
  { path: '/accounts', key: 'accounts' },
  { path: '/transactions', key: 'transactions' },
  { path: '/bills', key: 'bills' },
  { path: '/income', key: 'incomeNav' },
  { path: '/categories', key: 'categories' },
  { path: '/planning', key: 'planning' },
  { path: '/debts', key: 'debts' },
  { path: '/subscriptions', key: 'subscriptions' },
  { path: '/investments', key: 'investments' },
  { path: '/assets', key: 'assets' },
  { path: '/reports', key: 'reports' },
  { path: '/vault', key: 'vault' },
  { path: '/heir', key: 'heir' },
  { path: '/settings', key: 'settings' },
]

export const PHONE_NAV_DEFAULT = ['/', '/accounts', '/transactions', '/bills']

const SWATCH: Record<Theme, [string, string]> = {
  ledger: ['#f4efe3', '#2e5e3e'], swiss: ['#ffffff', '#e4572e'], geo: ['#1e3a5f', '#c0623b'], vault: ['#1c1b19', '#b08d57'],
}

export function Shell() {
  const { t, d } = useI18n()
  const { theme, setTheme, lang, setLang, readOnly, logout, user } = useSession()
  // The phone's bottom bar: the four pages this user picked (in their order), then "More".
  const phoneNav = user?.phone_nav ?? PHONE_NAV_DEFAULT
  // Theme popover position (fixed, so it never makes the header taller); null = closed.
  const [themes, setThemes] = useState<{ top: number; left: number } | null>(null)
  const [picker, setPicker] = useState(false)
  const loc = useLocation()
  const nav = useNavigate()
  const [sheet, setSheet] = useState(false)
  const [quick, setQuick] = useState(false)
  const [full, setFull] = useState(false)
  const current = loc.pathname === '/notifications' ? { key: 'notifications' as Key }
    : loc.pathname === '/admin' ? { key: 'usersAdmin' as Key }
    : NAV.find((n) => n.path === loc.pathname) ?? NAV[0]
  const onMore = !phoneNav.includes(loc.pathname)

  const { canInstall, install } = useInstall()
  const go = (path: string) => { setSheet(false); nav(path) }

  // Home-screen shortcut "Add expense" opens the app at /?quick=1.
  useEffect(() => {
    if (new URLSearchParams(loc.search).get('quick') === '1') {
      if (!readOnly) setQuick(true)
      nav(loc.pathname, { replace: true })
    }
  }, [loc.search, loc.pathname, nav, readOnly])

  return (
    <div className="app">
      <aside className="side">
        <div className="logo"><span className="full">{t('app')}</span><span className="short" aria-hidden="true">{t('app').charAt(0)}</span></div>
        <nav className="nav" aria-label={t('menu')}>
          {NAV.map((n) => (
            <NavLink key={n.path} to={n.path} end title={t(n.key)} style={{ '--po': phoneNav.indexOf(n.path) } as CSSProperties}
              className={({ isActive }) => `${isActive ? 'on' : ''}${phoneNav.includes(n.path) ? '' : ' x'}`}>
              <Icon name={n.key} /><span className="lbl">{t(n.key)}</span>
            </NavLink>
          ))}
          <button type="button" className={`mob${onMore ? ' on' : ''}`} onClick={() => setSheet(true)} aria-haspopup="dialog">
            <Icon name="more" /><span className="lbl">{t('more')}</span>
          </button>
          <button type="button" className="bottom x" onClick={logout} title={t('logout')}>
            <Icon name="logout" className="flip" /><span className="lbl">{t('logout')}</span>
          </button>
        </nav>
      </aside>

      <main className="main">
        {readOnly && <div className="readonly-bar" role="status">{t('readOnly')}</div>}
        {!readOnly && <OfflineBar />}
        <header className="top">
          <h1>{t(current.key)}</h1>
          <div className="ctrls">
            <Bell />
            {/* One button for the theme: shows the current one, opens the four. */}
            <div className="theme-pick">
              <button type="button" className="icon-btn swatch-btn" aria-haspopup="true" aria-expanded={!!themes} aria-label={`${t('theme')}: ${d.themes[theme]}`} title={d.themes[theme]}
                onClick={(e) => {
                  const r = e.currentTarget.getBoundingClientRect()
                  setThemes(themes ? null : { top: r.bottom + 6, left: Math.max(8, Math.min(r.left + r.width / 2 - 78, window.innerWidth - 164)) })
                }}>
                <i style={{ background: `linear-gradient(135deg, ${SWATCH[theme][0]} 50%, ${SWATCH[theme][1]} 50%)` }} />
              </button>
              {themes && (
                <>
                  <div className="pop-shield" onMouseDown={() => setThemes(null)} />
                  <div className="theme-pop swatches" role="radiogroup" aria-label={t('theme')} style={{ top: themes.top, left: themes.left }}
                    onKeyDown={(e) => e.key === 'Escape' && setThemes(null)}>
                    {(Object.keys(SWATCH) as Theme[]).map((k) => (
                      <button type="button" key={k} role="radio" aria-checked={theme === k} className={theme === k ? 'on' : ''}
                        title={d.themes[k]} aria-label={d.themes[k]} autoFocus={theme === k}
                        style={{ background: `linear-gradient(135deg, ${SWATCH[k][0]} 50%, ${SWATCH[k][1]} 50%)` }}
                        onClick={() => { setTheme(k); setThemes(null) }} />
                    ))}
                  </div>
                </>
              )}
            </div>
            {/* One button for the language: shows the current one; tap to switch. */}
            <button type="button" className="icon-btn lang-btn" lang={lang} aria-label={`${t('language')}: ${lang === 'ar' ? 'العربية' : 'English'}`}
              title={lang === 'ar' ? 'English' : 'العربية'} onClick={() => setLang(lang === 'ar' ? 'en' : 'ar')}>
              {lang === 'ar' ? 'ع' : 'E'}
            </button>
          </div>
        </header>
        <section className="view">
          {/* Pages load on first visit; the shell stays put meanwhile. */}
          <Suspense fallback={<div className="empty">{t('loading')}</div>}>
            <Outlet />
          </Suspense>
        </section>
      </main>

      {!readOnly && (
        <button type="button" className="fab" onClick={() => setQuick(true)} aria-label={t('quickExpense')} title={t('quickExpense')}>
          <Icon name="plus" />
        </button>
      )}
      {picker && <BarPicker current={phoneNav} onClose={() => setPicker(false)} />}
      {quick && <QuickAdd onClose={() => setQuick(false)} onDetails={() => { setQuick(false); setFull(true) }} />}
      {full && <TransactionForm onClose={() => setFull(false)} />}

      {sheet && (
        <div className="sheet" onMouseDown={(e) => e.target === e.currentTarget && setSheet(false)}>
          <div className="in" role="dialog" aria-modal="true" aria-label={t('more')}>
            {NAV.filter((n) => !phoneNav.includes(n.path)).map((n) => (
              <button type="button" key={n.path} onClick={() => go(n.path)} className={n.path === loc.pathname ? 'on' : ''}>
                <Icon name={n.key} /><span>{t(n.key)}</span>
              </button>
            ))}
            <button type="button" onClick={() => { setSheet(false); setPicker(true) }}>
              <Icon name="settings" /><span>{t('customizeBar')}</span>
            </button>
            {canInstall && (
              <button type="button" onClick={() => { setSheet(false); install() }}>
                <Icon name="plus" /><span>{t('installApp')}</span>
              </button>
            )}
            <button type="button" onClick={() => { setSheet(false); logout() }}>
              <Icon name="logout" className="flip" /><span>{t('logout')}</span>
            </button>
          </div>
        </div>
      )}
    </div>
  )
}

/** Pick the four pages of the phone's bottom bar (saved to the account, so every device follows). */
function BarPicker({ current, onClose }: { current: string[]; onClose: () => void }) {
  const { t } = useI18n()
  const { reloadUser } = useSession()
  const form = useForm({ b1: current[0], b2: current[1], b3: current[2], b4: current[3] }, onClose)
  const options = NAV.map((n) => [n.path, t(n.key)] as [string, string])
  const [err, setErr] = useState<Record<string, string>>({})
  const submit = () => {
    const picked = [form.f.b1, form.f.b2, form.f.b3, form.f.b4]
    if (new Set(picked).size < 4) { setErr({ b4: t('pickFourDifferent') }); return }
    form.run(async () => { await patch('/auth/me', { phone_nav: picked }); await reloadUser() })
  }
  return (
    <FormModal title={t('customizeBar')} errors={{ ...form.errors, ...err }} busy={form.busy} onClose={onClose} onSubmit={submit}
      extra={<button type="button" className="btn ghost" onClick={() => form.run(async () => { await patch('/auth/me', { phone_nav: null }); await reloadUser() })}>{t('resetDefault')}</button>}
      fields={(['b1', 'b2', 'b3', 'b4'] as const).map((k, i) => select(form, k, t('barSlot', { n: i + 1 }), options))} />
  )
}
