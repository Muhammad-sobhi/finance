import { useEffect, useRef, useState, type FormEvent } from 'react'
import { useI18n } from '../i18n'
import { appLock, MAX_FAILS, verifyBiometric, type LockConfig } from '../lib/appLock'
import { useSession } from '../lib/session'
import { Icon } from './Icon'

/**
 * Full-screen lock shown instead of the app (nothing of the app is on the page meanwhile).
 * Fingerprint / face first when set up, the PIN always works. Too many wrong PINs or
 * "forgot PIN" sign out: the password is needed again and the lock is removed.
 */
export function LockScreen({ config, onUnlock }: { config: LockConfig; onUnlock: () => void }) {
  const { t, lang, num } = useI18n()
  const { user, logout } = useSession()
  const [pin, setPin] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const inputRef = useRef<HTMLInputElement>(null)

  const bio = async () => {
    if (!config.credential) return
    try {
      if (await verifyBiometric(config.credential)) onUnlock()
    } catch {
      inputRef.current?.focus()   // cancelled or not possible now: the PIN is right there
    }
  }
  // Ask for fingerprint / face straight away (some phones need a tap first: the button stays).
  useEffect(() => { bio() }, [])   // eslint-disable-line react-hooks/exhaustive-deps

  const signOut = async () => {
    appLock.clear()
    await logout()
  }

  const check = async (value: string) => {
    setBusy(true)
    const { ok, fails } = await appLock.checkPin(appLock.get(config.user_id) ?? config, value)
    setBusy(false)
    if (ok) return onUnlock()
    setPin('')
    if (fails >= MAX_FAILS) return signOut()
    setError(t('lockWrong', { n: MAX_FAILS - fails }))
  }

  const submit = (e: FormEvent) => { e.preventDefault(); if (pin) check(pin) }

  return (
    <div className="auth lock" lang={lang}>
      <form className="card" onSubmit={submit}>
        <div className="lock-h">
          <Icon name="lock" />
          <h1>{t('app')}</h1>
          <p className="muted">{user?.name}</p>
        </div>
        <div className="field">
          <label htmlFor="lock-pin">{t('lockEnter')}</label>
          <input id="lock-pin" ref={inputRef} className="inp num code-inp" type="password" inputMode="numeric" autoComplete="off" dir="ltr"
            maxLength={8} autoFocus={!config.credential} value={pin} disabled={busy}
            onChange={(e) => {
              const v = e.target.value.replace(/[٠-٩]/g, (d) => String('٠١٢٣٤٥٦٧٨٩'.indexOf(d))).replace(/\D/g, '')
              setPin(v); setError(null)
              if (v.length === config.length) check(v)
            }} />
        </div>
        {error && <span className="err" role="alert">{error}</span>}
        <button type="submit" className="btn" disabled={busy || !pin}>{t('lockOpen')}</button>
        {config.credential && (
          <button type="button" className="btn ghost" onClick={bio}><Icon name="fingerprint" />{t('lockBio')}</button>
        )}
        <div className="auth-links">
          <button type="button" className="more" onClick={() => { if (window.confirm(t('lockForgotConfirm'))) signOut() }}>{t('lockForgot')}</button>
          {config.fails > 0 && !error && <small className="muted">{t('lockTriesLeft', { n: num(MAX_FAILS - config.fails) })}</small>}
        </div>
      </form>
    </div>
  )
}
