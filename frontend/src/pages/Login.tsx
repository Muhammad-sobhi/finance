import { useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { PasswordInput } from '../components/PasswordInput'
import { useI18n } from '../i18n'
import { ApiError } from '../lib/api'
import { useSession } from '../lib/session'

export function Login() {
  const { t } = useI18n()
  const { login, completeTwoFactor, lang, setLang } = useSession()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [challenge, setChallenge] = useState<string | null>(null)
  const [code, setCode] = useState('')
  const [recovery, setRecovery] = useState(false)
  const [remember, setRemember] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  const fail = (err: unknown) => {
    if (err instanceof ApiError && err.status === 429) setError(err.message)
    else if (err instanceof ApiError && err.data.errors?.challenge) { setChallenge(null); setCode(''); setError(t('signInExpired')) }
    else if (err instanceof ApiError && err.status === 422) setError(challenge ? t('invalidCode') : t('wrongLogin'))
    else setError(t('error'))
  }

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      if (challenge) await completeTwoFactor(challenge, recovery ? { recovery_code: code.trim() } : { code: code.trim() }, remember)
      else setChallenge(await login(email.trim(), password))
    } catch (err) {
      fail(err)
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
        {challenge ? (
          <>
            <p className="muted">{recovery ? t('recoveryCode') : t('twoFactorTitle')}</p>
            <div className="field">
              <label htmlFor="login-code">{recovery ? t('recoveryCode') : t('authCode')}</label>
              <input id="login-code" className="inp num code-inp" dir="ltr" required autoFocus value={code} onChange={(e) => setCode(e.target.value)}
                autoComplete="one-time-code" inputMode={recovery ? 'text' : 'numeric'} maxLength={recovery ? 30 : 6} />
            </div>
            <label className="check small-check" htmlFor="login-remember">
              <input id="login-remember" type="checkbox" checked={remember} onChange={(e) => setRemember(e.target.checked)} />
              {t('trustDevice')}
            </label>
            {error && <span className="err" role="alert">{error}</span>}
            <button type="submit" className="btn" disabled={busy}>{t('verify')}</button>
            <div className="auth-links">
              <button type="button" className="more" onClick={() => { setRecovery(!recovery); setCode(''); setError(null) }}>{recovery ? t('useApp') : t('useRecovery')}</button>
              <button type="button" className="more" onClick={() => { setChallenge(null); setCode(''); setError(null) }}>{t('back')}</button>
            </div>
          </>
        ) : (
          <>
            <div className="field">
              <label htmlFor="login-email">{t('email')}</label>
              <input id="login-email" className="inp" type="email" autoComplete="username" dir="ltr" required value={email} onChange={(e) => setEmail(e.target.value)} />
            </div>
            <div className="field">
              <label htmlFor="login-password">{t('password')}</label>
              <PasswordInput id="login-password" autoComplete="current-password" required value={password} onChange={(e) => setPassword(e.target.value)} />
            </div>
            {error && <span className="err" role="alert">{error}</span>}
            <button type="submit" className="btn" disabled={busy}>{t('login')}</button>
            <div className="auth-links">
              <Link to="/forgot-password" className="more">{t('forgotPassword')}</Link>
              <Link to="/heir-request" className="more">{t('heirRequest')}</Link>
            </div>
          </>
        )}
      </form>
    </div>
  )
}
