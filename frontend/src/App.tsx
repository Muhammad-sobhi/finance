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
import { Settings } from './pages/Settings'
import { Dashboard } from './pages/Dashboard'
import { Login } from './pages/Login'
import { Notifications } from './pages/Notifications'
import { Placeholder } from './pages/Placeholder'
import { Transactions } from './pages/Transactions'

export default function App() {
  const { user, booting } = useSession()
  const { t } = useI18n()

  if (booting) return <div className="empty">{t('loading')}</div>
  if (!user) return <Login />

  return (
    <BrowserRouter>
      <Routes>
        <Route element={<Shell />}>
          <Route index element={<Dashboard />} />
          <Route path="accounts" element={<Accounts />} />
          <Route path="transactions" element={<Transactions />} />
          <Route path="categories" element={<Categories />} />
          <Route path="debts" element={<Debts />} />
          <Route path="bills" element={<Recurring key="bills" kind="bills" />} />
          <Route path="subscriptions" element={<Recurring key="subscriptions" kind="subscriptions" />} />
          <Route path="investments" element={<Investments />} />
          <Route path="assets" element={<Assets />} />
          <Route path="reports" element={<Placeholder k="reports" />} />
          <Route path="heir" element={<Placeholder k="heir" />} />
          <Route path="settings" element={<Settings />} />
          <Route path="notifications" element={<Notifications />} />
          <Route path="*" element={<Navigate to="/" replace />} />
        </Route>
      </Routes>
    </BrowserRouter>
  )
}
