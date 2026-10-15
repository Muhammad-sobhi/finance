import { useEffect, useState, type FormEvent, type ReactNode } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useI18n } from '../i18n'
import { ApiError, post } from '../lib/api'
import { useSession } from '../lib/session'

/** Shared frame for the logged-out pages. */
function AuthCard({ title, children }: { title: string; children: ReactNode }) {
  const { t } = useI18n()
  const { lang, setLang } = useSession()
  return (
    <div className="auth">
      <div className="card">
        <div className="auth-h">
          <h1>{title}</h1>
          <div className="seg" role="radiogroup" aria-label={t('language')}>
            <button type="button" role="radio" aria-checked={lang === 'ar'} className={lang === 'ar' ? 'on' : ''} onClick={() => setLang('ar')}>ع</button>
            <button type="button" role="radio" aria-checked={lang === 'en'} className={lang === 'en' ? 'on' : ''} onClick={() => setLang('en')}>EN</button>
          </div>
        </div>
        {children}
      </div>
    </div>
  )
}

/** Heir asks for access; the answer is the same whether or not the email is known. */
export function HeirRequestPage() {
  const { t } = useI18n()
  const [email, setEmail] = useState('')
  const [state, setState] = useState<'idle' | 'busy' | 'sent' | 'error'>('idle')
  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setState('busy')
    try {
      await post('/heir-requests', { email: email.trim() })
      setState('sent')
    } catch (err) {
      setState(err instanceof ApiError && err.status === 429 ? 'sent' : 'error')
    }
  }
  return (
    <AuthCard title={t('heirRequest')}>
      {state === 'sent' ? <p role="status">{t('requestSent')}</p> : (
        <form className="auth-form" onSubmit={submit}>
          <p className="muted">{t('heirRequestHint')}</p>
          <div className="field">
            <label htmlFor="hr-email">{t('email')}</label>
            <input id="hr-email" className="inp" type="email" dir="ltr" required value={email} onChange={(e) => setEmail(e.target.value)} />
          </div>
          {state === 'error' && <span className="err" role="alert">{t('error')}</span>}
          <button type="submit" className="btn" disabled={state === 'busy'}>{t('sendRequest')}</button>
        </form>
      )}
      <Link to="/" className="more">{t('backToLogin')}</Link>
    </AuthCard>
  )
}

type Info = { action: 'check_in' | 'confirm' | 'cancel' | 'activate'; owner?: string; heir?: string; email?: string; status?: string; active?: boolean }

/** Performs an emailed signed action (/link?to=/api/v1/links/...). */
export function LinkPage() {
  const { t } = useI18n()
  const [params] = useSearchParams()
  const to = params.get('to') ?? ''
  const valid = /^\/api\/v1\/links\/[a-z-]+\/\d+(\/\d+)?\?/.test(to) // only our own signed endpoints
  const [info, setInfo] = useState<Info | null>(null)
  const [state, setState] = useState<'loading' | 'ready' | 'done' | 'invalid' | 'already' | 'error'>(valid ? 'loading' : 'invalid')
  const [pw, setPw] = useState({ password: '', password_confirmation: '' })
  const [err, setErr] = useState<string | null>(null)

  useEffect(() => {
    if (!valid) return
    fetch(to, { headers: { Accept: 'application/json' } })
      .then(async (r) => {
        if (!r.ok) { setState('invalid'); return }
        const data = (await r.json()) as Info
        setInfo(data)
        // Confirming needs a pending request; cancelling also works during the confirmed window.
        const open = data.action === 'cancel' ? ['pending', 'confirmed'] : ['pending']
        setState(data.status && !open.includes(data.status) ? 'already' : 'ready')
      })
      .catch(() => setState('error'))
  }, [to, valid])

  const act = async (e?: FormEvent) => {
    e?.preventDefault()
    setErr(null)
    const r = await fetch(to, { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json' }, body: JSON.stringify(info?.action === 'activate' ? pw : {}) })
    if (r.ok) setState('done')
    else if (r.status === 409) setState('already')
    else if (r.status === 422) setErr(Object.values(((await r.json()) as { errors?: Record<string, string[]> }).errors ?? {})[0]?.[0] ?? t('error'))
    else setState(r.status === 403 ? 'invalid' : 'error')
  }

  const title = info?.action === 'activate' ? t('activateTitle') : info?.action === 'check_in' ? t('checkIn') : t('heirRequest')
  return (
    <AuthCard title={title}>
      {state === 'loading' && <p>{t('loading')}</p>}
      {state === 'invalid' && <p className="err" role="alert">{t('linkInvalid')}</p>}
      {state === 'error' && <p className="err" role="alert">{t('error')}</p>}
      {state === 'already' && <p role="status">{t('alreadyDone')}</p>}
      {state === 'done' && <p role="status">{info?.action === 'activate' ? `${t('linkDone')} ${info.email ?? ''}` : t('linkDone')}</p>}
      {state === 'ready' && info && (
        info.action === 'activate' ? (
          <form className="auth-form" onSubmit={act}>
            <p className="muted">{info.heir} · <span dir="ltr">{info.email}</span></p>
            <div className="field">
              <label htmlFor="ln-pw">{t('password')} <small className="muted">({t('passwordHint')})</small></label>
              <input id="ln-pw" className="inp" type="password" autoComplete="new-password" dir="ltr" required minLength={10}
                value={pw.password} onChange={(e) => setPw({ ...pw, password: e.target.value })} />
            </div>
            <div className="field">
              <label htmlFor="ln-pw2">{t('passwordConfirm')}</label>
              <input id="ln-pw2" className="inp" type="password" autoComplete="new-password" dir="ltr" required
                value={pw.password_confirmation} onChange={(e) => setPw({ ...pw, password_confirmation: e.target.value })} />
            </div>
            {err && <span className="err" role="alert">{err}</span>}
            <button type="submit" className="btn">{t('save')}</button>
          </form>
        ) : (
          <>
            <p>
              {info.action === 'check_in' && t('checkInQ')}
              {info.action === 'confirm' && t('confirmRequestQ', { heir: info.heir ?? '', owner: info.owner ?? '' })}
              {info.action === 'cancel' && t('cancelRequestQ', { heir: info.heir ?? '' })}
            </p>
            <button type="button" className={`btn${info.action === 'cancel' ? ' danger' : ''}`} onClick={() => act()}>
              {info.action === 'check_in' ? t('checkIn') : info.action === 'confirm' ? t('confirm') : t('cancelRequest')}
            </button>
          </>
        )
      )}
      <Link to="/" className="more">{state === 'done' && info?.action === 'activate' ? t('goLogin') : t('backToLogin')}</Link>
    </AuthCard>
  )
}
