import { useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { useI18n } from '../i18n'
import { ApiError } from '../lib/api'
import { useSession } from '../lib/session'

export function Login() {
  const { t } = useI18n()
  const { login, lang, setLang } = useSession()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      await login(email.trim(), password)
    } catch (err) {
      setError(err instanceof ApiError && (err.status === 422 || err.status === 429) ? (err.status === 429 ? err.message : t('wrongLogin')) : t('error'))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="auth">
      <form className="card" onSubmit={submit}>
        <div className="auth-h">
          <h1>{t('app')}</h1>
          <div className="seg" role="radiogroup" aria-label={t('language')}>
            <button type="button" role="radio" aria-checked={lang === 'ar'} className={lang === 'ar' ? 'on' : ''} onClick={() => setLang('ar')}>ع</button>
            <button type="button" role="radio" aria-checked={lang === 'en'} className={lang === 'en' ? 'on' : ''} onClick={() => setLang('en')}>EN</button>
          </div>
        </div>
        <div className="field">
          <label htmlFor="login-email">{t('email')}</label>
          <input id="login-email" className="inp" type="email" autoComplete="username" dir="ltr" required value={email} onChange={(e) => setEmail(e.target.value)} />
        </div>
        <div className="field">
          <label htmlFor="login-password">{t('password')}</label>
          <input id="login-password" className="inp" type="password" autoComplete="current-password" dir="ltr" required value={password} onChange={(e) => setPassword(e.target.value)} />
        </div>
        {error && <span className="err" role="alert">{error}</span>}
        <button type="submit" className="btn" disabled={busy}>{t('login')}</button>
        <Link to="/heir-request" className="more">{t('heirRequest')}</Link>
      </form>
    </div>
  )
}
