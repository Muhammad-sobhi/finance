import { Suspense, useEffect, useState } from 'react'
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom'
import { useI18n, type Key } from '../i18n'
import { useInstall } from '../lib/install'
import { useSession } from '../lib/session'
import type { Theme } from '../lib/types'
import { Bell } from './Bell'
import { Icon } from './Icon'
import { QuickAdd } from './QuickAdd'
import { TransactionForm } from './TransactionForm'

export const NAV: { path: string; key: Key; phone?: boolean }[] = [
  { path: '/', key: 'home', phone: true },
  { path: '/accounts', key: 'accounts', phone: true },
  { path: '/transactions', key: 'transactions', phone: true },
  { path: '/bills', key: 'bills', phone: true },
  { path: '/income', key: 'incomeNav' },
  { path: '/categories', key: 'categories' },
  { path: '/planning', key: 'planning' },
  { path: '/debts', key: 'debts' },
  { path: '/subscriptions', key: 'subscriptions' },
  { path: '/investments', key: 'investments' },
  { path: '/assets', key: 'assets' },
  { path: '/reports', key: 'reports' },
  { path: '/heir', key: 'heir' },
  { path: '/settings', key: 'settings' },
]

const SWATCH: Record<Theme, [string, string]> = {
  ledger: ['#f4efe3', '#2e5e3e'], swiss: ['#ffffff', '#e4572e'], geo: ['#1e3a5f', '#c0623b'], vault: ['#1c1b19', '#b08d57'],
}

export function Shell() {
  const { t, d } = useI18n()
  const { theme, setTheme, lang, setLang, readOnly, logout } = useSession()
  const loc = useLocation()
  const nav = useNavigate()
  const [sheet, setSheet] = useState(false)
  const [quick, setQuick] = useState(false)
  const [full, setFull] = useState(false)
  const current = loc.pathname === '/notifications' ? { key: 'notifications' as Key }
    : loc.pathname === '/admin' ? { key: 'usersAdmin' as Key }
    : NAV.find((n) => n.path === loc.pathname) ?? NAV[0]
  const onMore = !NAV.find((n) => n.path === loc.pathname)?.phone

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
            <NavLink key={n.path} to={n.path} end title={t(n.key)}
              className={({ isActive }) => `${isActive ? 'on' : ''}${n.phone ? '' : ' x'}`}>
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
        <header className="top">
          <h1>{t(current.key)}</h1>
          <div className="ctrls">
            <Bell />
            <div className="swatches" role="radiogroup" aria-label={t('theme')}>
              {(Object.keys(SWATCH) as Theme[]).map((k) => (
                <button type="button" key={k} role="radio" aria-checked={theme === k} className={theme === k ? 'on' : ''}
                  title={d.themes[k]} aria-label={d.themes[k]}
                  style={{ background: `linear-gradient(135deg, ${SWATCH[k][0]} 50%, ${SWATCH[k][1]} 50%)` }}
                  onClick={() => setTheme(k)} />
              ))}
            </div>
            <div className="seg" role="radiogroup" aria-label={t('language')}>
              <button type="button" role="radio" aria-checked={lang === 'ar'} className={lang === 'ar' ? 'on' : ''} onClick={() => setLang('ar')} lang="ar">ع</button>
              <button type="button" role="radio" aria-checked={lang === 'en'} className={lang === 'en' ? 'on' : ''} onClick={() => setLang('en')} lang="en">EN</button>
            </div>
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
      {quick && <QuickAdd onClose={() => setQuick(false)} onDetails={() => { setQuick(false); setFull(true) }} />}
      {full && <TransactionForm onClose={() => setFull(false)} />}

      {sheet && (
        <div className="sheet" onMouseDown={(e) => e.target === e.currentTarget && setSheet(false)}>
          <div className="in" role="dialog" aria-modal="true" aria-label={t('more')}>
            {NAV.filter((n) => !n.phone).map((n) => (
              <button type="button" key={n.path} onClick={() => go(n.path)} className={n.path === loc.pathname ? 'on' : ''}>
                <Icon name={n.key} /><span>{t(n.key)}</span>
              </button>
            ))}
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
