import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import { Shell } from './components/Shell'
import { useI18n } from './i18n'
import { useSession } from './lib/session'
import { Accounts } from './pages/Accounts'
import { Categories } from './pages/Categories'
import { Dashboard } from './pages/Dashboard'
import { Login } from './pages/Login'
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
          <Route path="debts" element={<Placeholder k="debts" />} />
          <Route path="bills" element={<Placeholder k="bills" />} />
          <Route path="subscriptions" element={<Placeholder k="subscriptions" />} />
          <Route path="investments" element={<Placeholder k="investments" />} />
          <Route path="assets" element={<Placeholder k="assets" />} />
          <Route path="reports" element={<Placeholder k="reports" />} />
          <Route path="heir" element={<Placeholder k="heir" />} />
          <Route path="settings" element={<Placeholder k="settings" />} />
          <Route path="*" element={<Navigate to="/" replace />} />
        </Route>
      </Routes>
    </BrowserRouter>
  )
}
