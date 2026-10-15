import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import { Shell } from './components/Shell'
import { useI18n } from './i18n'
import { useSession } from './lib/session'
import { Accounts } from './pages/Accounts'
import { Assets } from './pages/Assets'
import { Categories } from './pages/Categories'
import { Debts } from './pages/Debts'
import { Investments } from './pages/Investments'
import { Recurring } from './pages/Recurring'
import { Reports } from './pages/Reports'
import { Settings } from './pages/Settings'
import { Dashboard } from './pages/Dashboard'
import { Login } from './pages/Login'
import { Notifications } from './pages/Notifications'
import { HeirPage } from './pages/Heir'
import { Income } from './pages/Income'
import { Planning } from './pages/Planning'
import { ForgotPasswordPage, HeirRequestPage, LinkPage, ResetPasswordPage } from './pages/Public'
import { Transactions } from './pages/Transactions'

export default function App() {
  const { user, booting } = useSession()
  const { t } = useI18n()

  if (booting) return <div className="empty">{t('loading')}</div>

  return (
    <BrowserRouter>
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
          <Route path="reports" element={<Reports />} />
          <Route path="heir" element={<HeirPage />} />
          <Route path="settings" element={<Settings />} />
          <Route path="notifications" element={<Notifications />} />
          <Route path="*" element={<Navigate to="/" replace />} />
        </Route>}
      </Routes>
    </BrowserRouter>
  )
}
