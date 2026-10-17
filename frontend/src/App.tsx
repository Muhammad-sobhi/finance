import { lazy, Suspense, useEffect } from 'react'
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import { Shell } from './components/Shell'
import { useI18n } from './i18n'
import { useSession } from './lib/session'
import { Dashboard } from './pages/Dashboard'
import { Login } from './pages/Login'
import { Transactions } from './pages/Transactions'

// Daily screens (home, expenses, login) ship in the main bundle; every other page is its own
// chunk, fetched on first visit and prefetched in the background once the app is idle.
const pages = {
  Accounts: () => import('./pages/Accounts'),
  Assets: () => import('./pages/Assets'),
  Categories: () => import('./pages/Categories'),
  Debts: () => import('./pages/Debts'),
  Investments: () => import('./pages/Investments'),
  Recurring: () => import('./pages/Recurring'),
  Reports: () => import('./pages/Reports'),
  Settings: () => import('./pages/Settings'),
  Notifications: () => import('./pages/Notifications'),
  Heir: () => import('./pages/Heir'),
  Income: () => import('./pages/Income'),
  Planning: () => import('./pages/Planning'),
  Public: () => import('./pages/Public'),
  Admin: () => import('./pages/Admin'),
  Vault: () => import('./pages/Vault'),
}
const Accounts = lazy(() => pages.Accounts().then((m) => ({ default: m.Accounts })))
const Assets = lazy(() => pages.Assets().then((m) => ({ default: m.Assets })))
const Categories = lazy(() => pages.Categories().then((m) => ({ default: m.Categories })))
const Debts = lazy(() => pages.Debts().then((m) => ({ default: m.Debts })))
const Investments = lazy(() => pages.Investments().then((m) => ({ default: m.Investments })))
const Recurring = lazy(() => pages.Recurring().then((m) => ({ default: m.Recurring })))
const Reports = lazy(() => pages.Reports().then((m) => ({ default: m.Reports })))
const Settings = lazy(() => pages.Settings().then((m) => ({ default: m.Settings })))
const Notifications = lazy(() => pages.Notifications().then((m) => ({ default: m.Notifications })))
const HeirPage = lazy(() => pages.Heir().then((m) => ({ default: m.HeirPage })))
const Income = lazy(() => pages.Income().then((m) => ({ default: m.Income })))
const Planning = lazy(() => pages.Planning().then((m) => ({ default: m.Planning })))
const Admin = lazy(() => pages.Admin().then((m) => ({ default: m.Admin })))
const Vault = lazy(() => pages.Vault().then((m) => ({ default: m.Vault })))
const LinkPage = lazy(() => pages.Public().then((m) => ({ default: m.LinkPage })))
const HeirRequestPage = lazy(() => pages.Public().then((m) => ({ default: m.HeirRequestPage })))
const ForgotPasswordPage = lazy(() => pages.Public().then((m) => ({ default: m.ForgotPasswordPage })))
const ResetPasswordPage = lazy(() => pages.Public().then((m) => ({ default: m.ResetPasswordPage })))

export default function App() {
  const { user, booting } = useSession()
  const { t } = useI18n()

  // Once signed in and idle, fetch the other pages so later navigation is instant.
  useEffect(() => {
    if (!user) return
    const idle = window.requestIdleCallback ?? ((fn: () => void) => window.setTimeout(fn, 1500))
    idle(() => Object.values(pages).forEach((load) => load().catch(() => undefined)))
  }, [user])

  if (booting) return <div className="empty">{t('loading')}</div>

  return (
    <BrowserRouter>
      <Suspense fallback={<div className="empty">{t('loading')}</div>}>
      <Routes>
        {/* Public: emailed signed actions and the heir access request. */}
        <Route path="link" element={<LinkPage />} />
        <Route path="heir-request" element={<HeirRequestPage />} />
        <Route path="forgot-password" element={<ForgotPasswordPage />} />
        <Route path="reset-password" element={<ResetPasswordPage />} />
        {!user && <Route path="*" element={<Login />} />}
        {user && <Route element={<Shell />}>
          <Route index element={<Dashboard />} />
          <Route path="accounts" element={<Accounts />} />
          <Route path="transactions" element={<Transactions />} />
          <Route path="categories" element={<Categories />} />
          <Route path="income" element={<Income />} />
          <Route path="planning" element={<Planning />} />
          <Route path="debts" element={<Debts />} />
          <Route path="bills" element={<Recurring key="bills" kind="bills" />} />
          <Route path="subscriptions" element={<Recurring key="subscriptions" kind="subscriptions" />} />
          <Route path="investments" element={<Investments />} />
          <Route path="assets" element={<Assets />} />
          <Route path="vault" element={<Vault />} />
          <Route path="reports" element={<Reports />} />
          <Route path="heir" element={<HeirPage />} />
          <Route path="settings" element={<Settings />} />
          <Route path="admin" element={<Admin />} />
          <Route path="notifications" element={<Notifications />} />
          <Route path="*" element={<Navigate to="/" replace />} />
        </Route>}
      </Routes>
      </Suspense>
    </BrowserRouter>
  )
}
